<?php
include "../UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if ($usa->connected) {
    echo $usa->receive();
} else {
    echo "not connected";
}



