<?php
$json=json_decode(file_get_contents("php://input"),true);
$ts=filemtime('results.gz');
if ($ts>$json['ts']) $results=file_get_contents('results.gz');
else $results='';
header('Content-type: application/json');
echo json_encode(['results'=>$results,'ts'=>$ts]);
?>