<?php
// its a local subscriber
include "../UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if (!$usa->connected) {
    echo "not connected";
    die(1);
}

$listEnveloper=$usa->listPending("insert");

foreach($listEnveloper as $id) {
    $env=$usa->readItem($id);
    var_dump($env);
    // todo: code goes here
    $correct=true;

    // $correct indicates if the operation was successful or not. For example, if the operation was to insert and the operation failed.
    // We also could decide to delete it for any case. Its up to us.
    if ($correct) {
        $usa->deleteItem($id); // YAY!
    } else {
        $usa->failedItem($id,$env); // booh hiss!.
    }
}