<?php

/**
 * Class UsagiMQ A minimalist Message Queue
 * @author Jorge Castro C. MIT License.
 * @version 1.1 2017-11-14
 * @link https://www.google.cl
 */
class UsagiMQ
{
    /** @var Redis $redis */
    public $redis;
    /** @var  bool $connected */
    public $connected=false; // false if redis is not connected. true if its connected.

    public $op=''; // last op obtained
    public $key=''; // last key obtained

    const MAXTIMEKEEP=3600*24*14; // max time in seconds to keep the information, 2 weeks ,-1 for unlimited.
    const MAXPOST=1024*1024*20; // 20mb

    const MAXTRY=20; // max number of tries. If the operation fails 20 times then the item is deleted.

    const LOGFILE='usagimq.txt'; // empty for no log

    /**
     * UsagiMQ constructor.
     * @param $redisIP . Example '127.0.0.1'
     * @param $redisPort.  Example 6379
     * @param int $redisDB.  Example 0,1,etc.
     */
    public function __construct($redisIP,$redisPort=6379,$redisDB=0)
    {
        try {
            if (!class_exists("Redis")) {
                echo "this software required Redis https://pecl.php.net/package/redis";
                die(1);
            }
            $this->redis = new Redis();
            $ok=@$this->redis->connect($redisIP, $redisPort, 5); // 5 sec timeout.
            if (!$ok) {
                $this->redis=null;
                $this->debugFile("Unable to open redis $redisIP : $redisPort",'__construct');
                return;
            }
            @$this->redis->select($redisDB);
            $this->connected=true;
        } catch (Exception $ex) {
            $this->connected=false;
            $this->debugFile($ex->getMessage(),'__construct');
        }
    }

    /**
     * receive a new envelope via post http://myserver/mq.php?id=<Customer>&op=<operation>&from=<AndroidApp> (and post info)
     * @return string OKI if the operation was successful, otherwise it returns the error.
     */
    public function receive() {
        try {
            $counter = $this->redis->incr('counterUsagiMQ');
            $post = file_get_contents('php://input');
            $id = @$_GET['id'];
            $this->op = @$_GET['op']; // operation.
            $from = @$_GET['from']; // security if any (optional)
            if (strlen($id)>1000 || strlen($this->op)>1000 || strlen($from)>1000 || strlen($post)>self::MAXPOST) {
                // avoid overflow.
                return "BAD INFO";
            }
            if (empty($post) || empty($this->op) || empty($id)) {
                return "NO INFO $post,{$this->op},$id";
            }

            $envelope = array();
            $envelope['id'] = $id; // this id is not the id used by the library. This could be repeated.
            $envelope['from'] = $from; // it could be used for security
            $envelope['body'] = $post;
            $envelope['date'] = time();
            $envelope['try'] = 0; // use future.
            $this->key="UsagiMQ_{$this->op}:" . $counter; // the key is unique and is composed by the operator and a counter.
            $ok = $this->redis->set($this->key, json_encode($envelope), self::MAXTIMEKEEP); // 24 hours
            if ($ok) {
                return "OKI";
            }
            $msg='Error reciving information';
            $this->debugFile($msg,'receive');
            return $msg;
        } catch (Exception $ex) {
            $this->debugFile($ex->getMessage(),'receive');
            return $ex->getMessage();
        }
    }

    /**
     * List with all ids of envelop pending.
     * @param $op
     * @return array
     */
    public function listPending($op) {
        $it = NULL;
        $redisKeys=array();
        while($arr_keys = $this->redis->scan($it, "UsagiMQ_{$op}:*", 1000)) { // 1000 read at the same time.
            foreach($arr_keys as $str_key) {
                $redisKeys[]=$str_key;
            }
        }
        natsort($redisKeys); // why sort,because we use scan/set and it doesn't sort. zadd is another alternative but it lacks of ttl
        return $redisKeys;
    }

    /**
     * @param string $key Key of the envelope.
     * @return array Returns an array with the form of an envelope[id,from,body,date,try]
     */
    public function readItem($key) {
       return json_decode($this->redis->get($key), true);
    }

    /**
     * The item failed. So we will try it again soon.
     * @param string $key Key of the envelope.
     * @param array $arr . The envelope [id,from,body,date,try]
     */
    public function failedItem($key,$arr) {
        try {
            $arr['try']++;
            if ($arr['try'] > self::MAXTRY) {
                $this->deleteItem($key); // we did the best but we failed.
                return;
            }
            $this->redis->set($key, json_encode($arr), self::MAXTIMEKEEP);
        } catch(Exception $ex) {
            $this->debugFile($ex->getMessage(),'failedItem');
        }
    }

    /**
     * Delete an envelope
     * @param string $key Key of the envelope.
     */
    public function deleteItem($key) {
        try {
            $this->redis->delete($key);
        } catch(Exception $ex) {
            $this->debugFile($ex->getMessage(),'deleteItem');
        }
    }

    /**
     * Delete all envelope and reset the counters.
     */
    function deleteAll() {
        try {
        $it = NULL;
        while($arr_keys = $this->redis->scan($it, "UsagiMQ_*", 10000)) {
            foreach($arr_keys as $v) {
                $this->redis->delete($v);
            }
        }
        $this->redis->set('counterUsagiMQ',"0");
        } catch(Exception $ex) {
            $this->debugFile($ex->getMessage(),'deleteAll');
        }
    }

    /**
     * Close redis.
     */
    function close() {
        try {
            $this->redis->close();
        } catch(Exception $ex) {
            $this->debugFile($ex->getMessage(),'close');
        }
    }

    private function debugFile($txt,$type="ERROR") {

        if (empty(self::LOGFILE)) {
            return;
        }
        $folder=(@ini_get('error_log')!="")?dirname(ini_get('error_log')):'';
        $file=$folder.'\\'.self::LOGFILE;
        $fz=@filesize($file);

        if (is_object($txt) || is_array($txt)) {
            $txtW=print_r($txt,true);
        } else {
            $txtW=$txt;
        }

        if ($fz>100000) {
            // more than 100kb, reduces it.
            $fp = @fopen($file, 'w');
        } else {
            $fp = @fopen($file, 'a');
        }
        if (!$fp) {
            die(1);
        }
        $today=new DateTime();
        fwrite($fp,$today->format('Y-m-d H:i:s')."\t[{$type}:]\t{$txtW}\n");
        fclose($fp);
    }

}