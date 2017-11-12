<?php
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL,            "http://localhost/UsagiMQ/example/mq.php?id=200&op=INSERTCUSTOMER&from=SALESSYSTEM" );
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt($ch, CURLOPT_POST,           1 );
curl_setopt($ch, CURLOPT_POSTFIELDS,     "** IT IS WHAT WE WANT TO SEND**" );
curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: text/plain'));

$result=curl_exec ($ch);

echo $result; // if returns OKI then the operation was successful. Otherwise, it an error.

