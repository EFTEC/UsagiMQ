<?php
include "../UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if ($usa->connected) {
    echo $usa->receive();
    if ($usa->key) {
        // method 1: calling a random worker.
        // its an example, if the operation is insert, then we call an random worker. In this case, its a local worker but it could be located in a different server
        if ($usa->op=='insert') {
            curlAsync('worker_insert_'.rand(1,3).'.php?key='.$usa->key);
        }
        // method 2: load balance
        if ($usa->op=='insert') {
            $ic=$usa->redis->incr('INSERTCOUNTER');
            if ($ic>=3) {
                $ic=0;
                $usa->redis->set('INSERTCOUNTER',$ic);
            }
            curlAsync('worker_insert_'.($ic+1).'.php?key='.$usa->key);
        }
    }
} else {
    echo "not connected";
}


/**
 * This operation returns nothing and takes 1ms.
 * @param $url
 */
function curlAsync($url) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1); // for php >5.2.3
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

    curl_exec($ch);
    curl_close($ch);
}