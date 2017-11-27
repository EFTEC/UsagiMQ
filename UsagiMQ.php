<?php

/**
 * Class UsagiMQ A minimalist Message Queue
 * @author Jorge Castro C. MIT License.
 * @version 1.3.245 2017-11-26
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

    const VERSION='1.3 Build 245 2017-11-26';

    const DEFAULTUSER='admin'; // If the user or password is not set, then it uses it.
    const DEFAULTPASSWORD='admin'; // The password is only for the UI.

    var $user;
    var $password;

    /**
     * UsagiMQ constructor.
     * @param $redisIP . Example '127.0.0.1'
     * @param int $redisPort .  Example 6379
     * @param int $redisDB .  Example 0,1,etc.
     * @param string $user
     * @param string $password
     */
    public function __construct($redisIP, $redisPort=6379, $redisDB=0, $user=self::DEFAULTUSER, $password=self::DEFAULTPASSWORD)
    {
        try {
            if (!class_exists("Redis")) {
                echo "this software required Redis https://pecl.php.net/package/redis";
                die(1);
            }
            $this->redis = new Redis();
            $ok=@$this->redis->pconnect($redisIP, $redisPort, 5); // 5 sec timeout.
            if (!$ok) {
                $this->redis=null;
                $this->debugFile("Unable to open redis $redisIP : $redisPort",'__construct');
                return;
            }
            $this->user=$user;
            $this->password=$password;
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
            $post = file_get_contents('php://input');
            $id = @$_GET['id'];
            $this->op = @$_GET['op']; // operation.
            $from = @$_GET['from']; // security if any (optional)
            if (strlen($id)>1000 || strlen($this->op)>1000 || strlen($from)>1000 || strlen($post)>self::MAXPOST) {
                // avoid overflow.
                return "BAD INFO";
            }
            if (empty($post) || empty($this->op) || empty($id)) {
                return "NO INFO";
            }
            $counter =$this->redis->incr('counterUsagiMQ');
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
    public function logFilename() {
        $folder=(@ini_get('error_log')!="")?dirname(ini_get('error_log')):'';
        $file=$folder.'\\'.self::LOGFILE;
        return $file;
    }

    public function debugFile($txt,$type="ERROR") {
        if (empty(self::LOGFILE)) {
            return;
        }
        $file=$this->logFilename();
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
        $finalMsg=$today->format('Y-m-d H:i:s')."\t[{$type}:]\t{$txtW}\n";
        if ($type=='ERROR') {
            @$this->redis->set('LastErrorUsagiMQ', $finalMsg);
        } else {
            @$this->redis->set('LastMessageUsagiMQ',$finalMsg);
        }
        fwrite($fp,$finalMsg);
        fclose($fp);
    }
    public function webUI() {
        @session_start();
        $mode=@$_REQUEST['mode'];
        $curUser=@$_SESSION['user'];
        if ($curUser=='') {
            if ($mode=='login') {
                $info['user']=@$_POST['user'];
                $info['password']=@$_POST['password'];
                $info['msg']='';
                $button=@$_POST['button'];
                if ($button) {
                    sleep(1);
                    if ($info['user']!=$this->user || $info['password']!=$this->password) {
                        $info['msg']="User or login incorrect";
                        $this->loginForm($info);
                        @session_destroy();
                    } else {
                        $_SESSION['user']=$info['user'];
                        $this->tableForm();
                    }
                } else {
                    @session_destroy();
                    $this->loginForm($info);
                }
            } else {
                $this->loginForm(array());
            }
        } else {
            switch ($mode) {
                case 'clear':
                    $this->deleteAll();
                    $this->tableForm();
                    break;
                case 'refresh':
                    $this->tableForm();
                    break;
                case 'execute':
                    $this->tableForm();
                    return "execute";
                    break;
                case 'logout':
                    @session_destroy();
                    $this->loginForm(array());
                    break;
                case 'showall':
                    if (empty(self::LOGFILE)) {
                        $this->tableForm();
                        return "";
                    }
                    $file=$this->logFilename();
                    $fc=file_get_contents($file);
                    $fc=str_replace("\n",'<br>',$fc);
                    $this->tableForm($fc);
                    break;
                case 'deletelog':
                    @$this->redis->set('LastErrorUsagiMQ', "");
                    @$this->redis->set('LastMessageUsagiMQ',"");
                    if (empty(self::LOGFILE)) {
                        $this->tableForm();
                        return "";
                    }
                    $file=$this->logFilename();
                    $fp = @fopen($file, 'w');
                    @fclose($fp);
                    $this->debugFile("Log deleted","DEBUG");

                    $this->tableForm();
                    break;
                default:
                    $this->tableForm();
            }
        }
        return "";
    }
    private function loginForm($info) {
        $this->cssForm();
        echo "
        <form method='post'>
        <table id='tablecss' >
            <tr><th><b>UsagiMQ</b></th><th>&nbsp;</th></tr>            
            <tr><td><b>User:</b></td><td><input type='text' name='user' value='".htmlentities(@$info['user'])."' /></td></tr>
            <tr><td><b>Password:</b></td><td><input type='password' name='password' value='".htmlentities(@$info['password'])."' /></td></tr>
            <tr><td colspan='2'><input type='submit' name='button' value='Login' /></td></tr>
            <tr><td colspan='2'><b color='red'>".@$info['msg']."</b></td></tr>
            </table>        
        <input type='hidden' name='mode' value='login' />
        </form>
        ";
    }
    private function tableForm($lastMessage='') {
        $counter = @$this->redis->get('counterUsagiMQ');
        $lastError = @$this->redis->get('LastErrorUsagiMQ');
        $curUser=@$_SESSION['user'];
        $lastMessage =($lastMessage=='')?@$this->redis->get('LastMessageUsagiMQ'):$lastMessage;
        $num=0;
        $pending="";
        while($arr_keys = $this->redis->scan($it, "UsagiMQ_*", 1000)) { // 1000 read at the same time.
            foreach($arr_keys as $str_key) {
                $num++;
                $pending.=$str_key."<br>";
            }
        }
        $this->cssForm();
        $myurl=$_SERVER["SCRIPT_NAME"];
        $today=new DateTime();
        echo "
            <table id='tablecss'>
            <tr><th  style='width: 150px'><b>UsagiMQ</b></th><th>{$curUser}@UsagiMQ 
                <a href='$myurl?mode=refresh'>refresh</a>&nbsp;&nbsp;&nbsp;&nbsp;
                <a href='$myurl?mode=logout'>Logout</a>
                </th></tr>
            <tr><td><b>Version:</b></td><td>".self::VERSION." Current Date :".$today->format('Y-m-d H:i:s')."</td></tr>            
            <tr><td><b>Counter:</b></td><td>$counter</td></tr>
            <tr><td><b>Pending: $num</b></td><td>$pending
                <a href='$myurl?mode=execute'>Run Pending</a>&nbsp;&nbsp;&nbsp;
                <a href='$myurl?mode=clear' onclick=\"return confirm('Are you sure?')\">Clear</a></td></tr>
            <tr><td><b>Last Error:</b></td><td>".htmlentities($lastError)."</td></tr>
            <tr><td><b>Last Message:</b></td><td>$lastMessage 
                        <a href='$myurl?mode=showall'>Show All</a>
                        &nbsp;&nbsp;&nbsp;&nbsp;<a href='$myurl?mode=deletelog'>Delete log</a></td></tr>            
            </table>
        ";

    }
    private function cssForm() {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        echo "<head>
            <title>UsagiMQ</title>
              <link rel=\"icon\" type=\"image/x-icon\" class=\"js-site-favicon\" href=\"favicon.ico\">
            <style>
            a {
                background-color: white;
                color: black;
                border: 2px solid #4CAF50;
                padding: 10px 20px;
                text-align: center;
                text-decoration: none;
                display: inline-block;
                font-size: 16px;
                margin-left: 20px;
            }
            a:hover {
            background-color: #4CAF50;
            color: white;
            }
            #tablecss {
                font-family: \"Trebuchet MS\", Arial, Helvetica, sans-serif;
                border-collapse: collapse;
                width: 100%;
                table-layout : fixed;
            }
            #tablecss td {
                word-wrap:break-word;
            }
            
            #tablecss td, #customers th {
                border: 1px solid #ddd;
                padding: 8px;
            }
            
            #tablecss tr:nth-child(even){background-color: #f2f2f2;}
            
            #tablecss tr:hover {background-color: #ddd;}
            
            #tablecss th {
                padding-top: 12px;
                padding-bottom: 12px;
                text-align: left;
                background-color: #4CAF50;
                color: white;
            }
            </style>
            </head>";
    }
}