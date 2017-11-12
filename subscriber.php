<?php
// its a local subscriber
include "UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if (!$usa->connected) {
    echo "not connected";
    die(1);
}

$listEnveloper=$usa->listPending("insert");

foreach($listEnveloper as $id) {
    $env=$usa->readItem($id);
    var_dump($env);
    // here we process the envelope
    // and if its right then we could delete.
    //$usa->delete($id);
}