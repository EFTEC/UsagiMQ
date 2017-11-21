<?php
include "../UsagiMQ.php";

$usa=new UsagiMQ("127.0.0.1",6379,1);
if ($usa->connected) {
    $info=$usa->receive();
    if ($info=='NO INFO') {
        $refresh=$usa->webUI(); // if not information is send, then it opens the UI.  It is optional
        if ($refresh) {
            //todo: call the subscriber(s)
        }
    } else {
        echo $info; // show the result.
    }
} else {
    echo "not connected";
}



