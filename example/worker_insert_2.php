<?php
@set_time_limit(60*60); // 1 hour.

include "../UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if (!$usa->connected) {
    echo "not connected";
    die(1);
}
$key=@$_GET['key'];
if ($key) {
    $envelope = $usa->readItem($key);
    //todo: here we do the task.
}