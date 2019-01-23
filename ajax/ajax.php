<?php
// https://logmatic.io/blog/youre-doing-php-logging-wrong/
// https://www.ibm.com/developerworks/library/os-php-shared-memory/index.html
// http://php.net/manual/en/book.opcache.php
// see also xcache and memcache?
class Ajax {
    const path='/../ajax/';
    function request() {
        error_reporting(E_ALL); // Reports all errors
        ini_set('display_errors','Off'); // Do not display errors for the end-users (security issue)
        ini_set('error_log',$this->filepath('error.log')); // Set a logging file
        $json=json_decode(file_get_contents("php://input"),true);
        $this->debug($json);
        if ($json && isset($json['req']))
        {
            $resp=['error'=>'unspecified'];
            switch ($json['req']) {
                case 'results':
                    $resp=$this->results($json);
                    break;
                default:
                    $resp=['error'=>'404'];
            }
            $this->response($resp);
            $this->debug($resp);
        }
        else $this->debug($json);
    }
    function response($resp) {
        header('Content-type: application/json');
        echo json_encode($resp);
    }
    function debug($message) {
        $json=json_encode($message);
        if (strlen($json) > 100) {
            $short=[];
            foreach($message as $key=>$val) {
                $len=strlen(json_encode($val));
                if ($len > 100) $short[$key]="...($len)";
                else $short[$key]=$val;
            }
            $json=json_encode($short);
        }
        error_log("AJAX ".microtime().' '.$json);
    }
    function filepath($name) {
        return __DIR__.self::path.$name;
    }
    function results($json) {
        $gz=$this->filepath('results.gz');
        $ts=filemtime($gz);
        if ($ts>$json['ts']) $results=file_get_contents($gz);
        else $results='';
        return ['results'=>$results,'ts'=>$ts];
    }
}
$ajax=new Ajax;
return $ajax;
?>