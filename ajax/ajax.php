<?php
require 'config.php';
$ajax=new Ajax;
return $ajax;
// remove apcu for now - perhaps add back in
class Ajax {
    function request() {
        $this->init(['version'=>10]); // update when updated in FM.js
        $json=json_decode(file_get_contents("php://input"),true);
        $this->debug($json,true);
        if ($json && isset($json['req'])) {
            if (method_exists($this,'req_'.$json['req'])) $resp=$this->{'req_'.$json['req']}($json);
            else $resp=['e'=>'404','r'=>"404 ({$json['req']} Not Found)"];
            $this->response($resp);
            if ($this->con) $this->db('close'); // don't leave open db
            $this->debug($resp);
        }
        else $this->debug($json);
    }
  
    private function req_files($json) {
        $files=[];
        foreach ($json['files'] as $name) {
            $gz=__DIR__.'/../storage/'.$name.'.gz';
            $ts=filemtime($gz);
            $file=file_get_contents($gz);
            $files[]=['name'=>$name,'file'=>$file,'ts'=>$ts];
        }
        return ['files'=>$files];
    }
    private function req_save($json) {
        $path=__DIR__.'/../storage/';
        $ret=[];
        foreach($json['data'] as $name => $gz) {
            file_put_contents($path.$name.'.gz',$gz);
            $ret[$name]['ts']=filemtime($path.$name.'.gz');
        }
        //apcu_delete('versions');
        return $ret;
    }
    private function req_versions() {
        //if ($versions=apcu_fetch('versions')) return $versions; 
        $path=__DIR__.'/../storage/';
        $versions=[];
        $versions['version']=$this->version;
        $versions['debug']=$this->config['debug'];
		foreach (['results'] as $name) {
			$versions[$name]['ts']=filemtime($path.$name.'.gz');
			$versions[$name]['size']=filesize($path.$name.'.gz');
        }
        //apcu_store('versions',$versions);
		return ['versions'=>$versions];
    }
    private function req_volunteers() {
        $roles=$this->db("select id,json from roles order by id desc limit 1");
        $vols=$this->db("select id,json from volunteers");
        return ['roles'=>$roles[0],'volunteers'=>$vols];
    }
    public function user($email=null,$id=null) {
        if (!isset($this->u)) {
            if ($email) $user=$this->db("select id,name,email,token,password from users where email=?",['s',&$email]);
            else if ($id) $user=$this->db("select id,name,email,token,password from users where id=?",['i',&$id]);
            else if ($this->token) $user=$this->db("select id,name,email,token,password from users where id=?",['i',&$this->token['id']]);
            $this->u=isset($user)&&$user?$user[0]:null;
        }
        return $this->u; 
    }
    private function validate($req,$spec) {
        foreach ($spec as $key=>$type) {
            if (!$req[$key]) $error[$key]=$type;
        }
        return isset($error)?['e'=>'422','r'=>$error]:null;
    }
    private function init($version) {
        error_reporting(E_ALL); // Reports all errors
        ini_set('display_errors','Off'); // Do not display errors for the end-users (security issue)
        ini_set('error_log',__DIR__.'/../storage/error.log'); // Set a logging file
        $this->freemaths=$version;
        $this->start=microtime(true);
        $this->config=config();
        $this->con=null;
        $this->mailer=null;
        $this->token=isset($_SERVER['HTTP_FM_TOKEN'])?json_decode($this->decrypt($_SERVER['HTTP_FM_TOKEN']),true):null;
        $this->origin=isset($_SERVER['HTTP_FM_ORIGIN'])?$_SERVER['HTTP_FM_ORIGIN']:null;
    }
    private function response($resp) {
        header('Content-type: application/json');
        if (isset($resp['e'])) {
            http_response_code($resp['e']);
            echo json_encode($resp['r']);
        }
        else echo json_encode($resp);
    }
    private function debug($message,$req=false,$max=250) {
        if (isset($message['password'])) $message['password']='...';
        if (is_array($message)) foreach ($message as $k=>$v) if (isset($message[$k]['password'])) $message[$k]['password']='...';
        $json=json_encode($message);
        if (strlen($json) > $max) {
            $short=[];
            foreach($message as $key=>$val) {
                $len=strlen(json_encode($val));
                if ($len > $max) $short[$key]="...($len)";
                else $short[$key]=$val;
            }
            $json=json_encode($short);
        }
        $msec=round($this->start-floor($this->start),4);
        $id=$this->token?$this->token['id'].($this->token['remember']?'+,':','):'';
        if (!$id && isset($message['user'])) $id=$message['user']['id'].','; 
        error_log(($req?$id:number_format(round(microtime(true)-$this->start,4),4).','.$id).$json.','.$msec);
    }
    private function mail($type,$json=null,$token=null,$logid=null)
    {
        if (!$this->mailer) {
            require_once 'mailer.php';
            $this->mailer=new Mailer();
        }
        $user=$this->user()?['id'=>$this->u['id'],'name'=>$this->u['name'],'email'=>$this->u['email']]:null;
        $this->mailer->send($type,$json,$token,$user=$this->user(),$logid);
    }
    private function db($query,$vals=[],$close=false) {
        $ret=null;
        if (!$this->con && $query!='close') {
            if ($this->con=new mysqli('localhost',$this->config['db']['user'],$this->config['db']['password'],$this->config['db']['db'])) {
                mysqli_set_charset($this->con,"utf8");
            }
        }
        if ($query=='close') $close=true;
        else if ($this->con && $query && count($vals)>0) {
            $stmt=$this->con->prepare($query);
            if (count($vals) > 0) call_user_func_array(array($stmt, 'bind_param'), $vals);
            $stmt->execute();
            if (substr($query,0,6)==='insert') $ret=mysqli_insert_id($this->con);
            else if (substr($query,0,6)==='update' || substr($query,0,6)==='delete') $ret=mysqli_affected_rows($this->con);
            else $ret=mysqli_fetch_all($stmt->get_result(),MYSQLI_ASSOC);
            $stmt->close();
        }
        else if ($this->con && $query) {
            $res=$this->con->query($query);
            if (substr($query,0,8)==='truncate') $ret=mysqli_affected_rows($this->con);
            else {
                $ret=mysqli_fetch_all($res,MYSQLI_ASSOC);
                mysqli_free_result($res);
            }
        }
        if ($this->con && $close) {
            mysqli_close($this->con);
            $this->con=null;
        }
        if (in_array('db',$this->config['debug'])) $this->debug(['query'=>$query,'vals'=>$vals,'result'=>$ret],false,500);
        return $ret;
    }
    private function unzip($zip) {
        return json_decode(gzuncompress(base64_decode($zip)),true);
    }
    private function zip($json) {
        return base64_encode(gzcompress(json_encode($json)));
    }
    private function decrypt($payload,$unserialize=true) {
        $payload=json_decode(base64_decode($payload),true);
        $iv=base64_decode($payload['iv']);
        $decrypted=openssl_decrypt($payload['value'],'AES-256-CBC',$this->config['key'],0,$iv);
        return $unserialize?unserialize($decrypted):$decrypted;
    }
    public function encrypt($value,$serialize=true) {
        $iv=random_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $value=openssl_encrypt($serialize?serialize($value):$value,'AES-256-CBC',$this->config['key'],0,$iv);
        $iv=base64_encode($iv);
        $mac=hash_hmac('sha256',$iv.$value,$this->config['key']);
        $json=json_encode(compact('iv','value','mac'));
        //$this->debug(['encrypt'=>$json?true:false,'iv'=>$iv?true:false,'value'=>$value?true:false,'mac'=>$mac?true:false]);
        return base64_encode($json);
    }
}
?>