<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-10
 * Time: 上午10:40
 */

namespace App\Core\Utility;

use ArrayAccess;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Config\Repository;
class Redis
{

    /**
     * @var redis 标识符
     */
    private $_redis = null;
    /**
     *  redis配置数组
     */
    public $configs = null;
    /**
     *  默认redis前缀
     */
    protected $prefix  = "spider";
    protected $error  = "";
    protected $maxTryTime = 5;
    protected $currentTryTime = 0;

    /**
     * @return mixed|null
     */
    public function connect(){
        // 如果当前链接标识符为空，或者ping不通，就close之后重新打开
        //if ( empty(self::$links[self::$link_name]) || !self::ping() )
        if (empty($this->_redis)) {
            // 获取配置
            $this->configs = $this->configs == null ? self::_get_default_config() : $this->configs;

            $this->_redis = new \Redis();
            if (isset($this->configs['pconnect'])){
                if (!$this->_redis->pconnect($this->configs['host'], $this->configs['port'], $this->configs['timeout'])) {
                    unset($this->_redis);
                    return null;
                }
            }else{
                if (!$this->_redis->connect($this->configs['host'], $this->configs['port'], $this->configs['timeout'])) {
                   unset($this->_redis);
                    return null;
                }
            }


            // 验证
            if ($this->configs['password']) {
                if ( !$this->_redis->auth($this->configs['password']) ) {
                    $this->error = "Redis Server authentication failed\nPlease check the configuration file config/inc_config.php";
                    unset($this->_redis);
                    return null;
                }
            }

            $prefix = empty($this->configs['prefix']) ? $this->prefix : $this->configs['prefix'];
            $this->maxTryTime = empty($this->configs['maxTryTime']) ? $this->maxTryTime : $this->configs['maxTryTime'];
            $this->_redis->setOption(\Redis::OPT_PREFIX, $prefix . ":");
            $this->_redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
            $this->_redis->select($this->configs['db']);
        }
        return $this;
       // return self::$links[$this->link_name] = $this;
        //return self::$links[$this->link_name];
    }


    public function getPrefix(){
        return empty($this->configs['prefix']) ? $this->prefix : $this->configs['prefix'];
    }
    public function close(){
        $this->_redis->close();
    }

    /**
     * 获取默认配置
     */
    protected function _get_default_config()
    {
        if (empty($this->configs)) {
            $this->configs = ['host'=>"127.0.0.1",'port'=>'6379','password'=>'','database'=>0];
        }
        return $this->configs;
    }

    /**
     * set
     * @param mixed $key    键
     * @param mixed $value  值
     * @param int $expire   过期时间，单位：秒
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     * @return bool
     */
    public function set($key, $value, $expire = 0){
        if ($this->_redis instanceof \Redis){
            try {
                if ($expire > 0) {
                    $v = $this->_redis->setex($key, $expire, $value);
                } else {
                    $v = $this->_redis->set($key, $value);
                }
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);

                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->set($key, $value, $expire);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->set($key, $value, $expire);
        }
    }


    /**
     * set
     *
     * @param mixed $key    键
     * @param mixed $value  值
     * @param int $expire   过期时间，单位：秒
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     * @return bool
     */
    public function setnx($key, $value, $expire = 0){

        if ($this->_redis instanceof \Redis){
            try {
                if ($expire > 0) {
                    $v = $this->_redis->set($key, $value, array('nx', 'ex' => $expire));
                }
                else {
                    $v = $this->_redis->setnx($key, $value);
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);

                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->set($key, $value, $expire);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->setnx($key, $value, $expire);
        }
    }

    /**
     * 锁
     * 默认锁1秒
     *
     * @param mixed $name   锁的标识名
     * @param mixed $value  锁的值,貌似没啥意义
     * @param int $expire   当前锁的最大生存时间(秒)，必须大于0，超过生存时间系统会自动强制释放锁
     * @param int $interval   获取锁失败后挂起再试的时间间隔(微秒)
     * @return void
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-10-30 23:56
     */
    /*public static function lock($name, $value = 1, $expire = 5, $interval = 100000)
    {
        if ($name == null) return false;

        self::init();
        try
        {
            if ( self::$links[self::$link_name] )
            {
                $key = "Lock:{$name}";
                while (true)
                {
                    // 因为 setnx 没有 expire 设置，所以还是用set
                    //$result = self::$links[self::$link_name]->setnx($key, $value);
                    $result = self::$links[self::$link_name]->set($key, $value, array('nx', 'ex' => $expire));
                    if ($result != false)
                    {
                        return true;
                    }

                    usleep($interval);
                }
                return false;
            }
        }
        catch (Exception $e)
        {
            $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
            log::warn($msg);
            if ($e->getCode() == 0)
            {
                self::$links[self::$link_name]->close();
                self::$links[self::$link_name] = null;
                // 睡眠100毫秒
                usleep(100000);
                return self::lock($name, $value, $expire, $interval);
            }
        }
        return false;
    }*/

    /**
     * 删除锁
     */
    /*public static function unlock($name)
    {
        $key = "Lock:{$name}";
        return self::del($key);
    }*/

    /**
     * @param $name
     * @param int $timeout
     * @param int $expire
     * @param int $waitIntervalUs
     * @return bool
     */
    public function lock($name, $timeout = 1, $expire = 5, $waitIntervalUs = 100000) {
        if ($name == null) return false;
        if ($this->_redis instanceof \Redis){
            $now = time();
            $timeoutAt = $now + $timeout;
            $expireAt = $now + $expire;
            $lockName = "Lock:{$name}";
            try {
                while (true){
                    $result = $this->_redis->setnx($lockName, $expireAt);
                    if ($result != false) {
                        $this->currentTryTime = 0;
                        return true;
                    }

                    $tempF =$this->_redis->get($lockName);
                    if ($tempF === false){
                        continue;
                    }
                    if ($tempF < time()){
                        $tempS = $this->_redis->getSet($lockName, $expireAt);
                        if ($tempF === $tempS){
                            $this->currentTryTime = 0;
                            return true;
                        }
                    }
                    //$this->_redis->delete($lockName);
                    if ($timeout <= 0 || $timeoutAt < microtime(true)) break;
                    usleep($waitIntervalUs);
                }
                return true;
            }catch (\Exception $e){
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->lock($name, $timeout, $expire, $waitIntervalUs);
                }
                return false;
            }

        }else{
            $this->connect();
            return $this->lock($name, $timeout, $expire, $waitIntervalUs);
        }

    }

    /**
     * @param $name
     * @return bool
     */
    public function unLock($name){
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->delete("Lock:{$name}");
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->unLock($name);
                }
            }
            return false;
        }else{
            $this->connect();
            return $this->unLock($name);
        }

    }


    /**
     * type 返回值的类型
     *
     * @param mixed $key
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function type($key){

        $types = array(
            '0' => 'set',
            '1' => 'string',
            '3' => 'list',
        );
        if ($this->_redis instanceof \Redis){
            try {
                $type = $this->_redis->type($key);
                if (isset($types[$type])) {
                    $v = $types[$type];
                }else{
                    $v = null;
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->type($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->type($key);
        }
    }

    /**
     * incr 名称为key的string增加integer, integer为0则增1
     *
     * @param mixed $key
     * @param int $integer
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function incr($key, $integer = 0)
    {
        if ($this->_redis instanceof \Redis){
            try {
                if (empty($integer)) {
                    $v = $this->_redis->incr($key);
                }
                else {
                    $v = $this->_redis->incrby($key, $integer);
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->incr($key, $integer);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->incr($key, $integer);
        }
    }

    /**
     * decr 名称为key的string减少integer, integer为0则减1
     *
     * @param mixed $key
     * @param int $integer
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function decr($key, $integer = 0)
    {
        if ($this->_redis instanceof \Redis){
            try {
                if (empty($integer)) {
                    $v = $this->_redis->decr($key);
                }
                else {
                    $v = $this->_redis->decrby($key, $integer);
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->decr($key, $integer);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->decr($key, $integer);
        }
    }



    /**
     * save 将数据保存到磁盘
     *
     * @param mixed $is_bgsave 将数据异步保存到磁盘
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function save($is_bgsave = false)
    {
        if ($this->_redis instanceof \Redis){
            try {
                if (!$is_bgsave) {
                    $this->_redis->save();
                    $v = true;
                }
                else {
                    $v = $this->_redis->bgsave();
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->save($is_bgsave);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->save($is_bgsave);
        }

    }


    /**
     * slowlog 慢查询日志

     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function slowlog($command = 'get', $len = 0)
    {
        if ($this->_redis instanceof \Redis){
            try {
                if (!empty($len))
                {
                    $v = $this->_redis->slowlog($command, $len);
                }
                else
                {
                    $v =  $this->_redis->slowlog($command);
                }
                $this->currentTryTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTryTime < $this->maxTryTime) {
                    $this->currentTryTime++;
                    usleep(100000);
                    return $this->slowlog($command, $len);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->slowlog($command, $len);
        }

    }



    /**
     * rlist 从右边弹出 $length 长度数据，并删除数据
     *
     * @param mixed $key
     * @param mixed $length
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public static function rlist($key, $length)
    {
        $queue_length = self::lsize($key);
        // 如果队列中有数据
        if ($queue_length > 0)
        {
            $list = array();
            $count = ($queue_length >= $length) ? $length : $queue_length;
            for ($i = 0; $i < $count; $i++)
            {
                $data = self::rpop($key);
                if ($data === false)
                {
                    continue;
                }

                $list[] = $data;
            }
            return $list;
        }
        else {
            // 没有数据返回NULL
            return NULL;
        }
    }

    /**
     * ping 检查当前redis是否存在且是否可以连接上
     */
    //protected static function ping()
    //{
    //if ( empty (self::$links[self::$link_name]) )
    //{
    //return false;
    //}
    //return self::$links[self::$link_name]->ping() == '+PONG';
    //}


    function __call($method, $args = array()) {
        while (true) {
            try {
                $result = call_user_func_array(array($this->_redis, $method), $args);
                $this->currentTryTime = 0;
                return $result;
            } catch (\RedisException $e) {
                $this->_redis->close();
                $this->_redis = null;
                //捕获链接失败异常
                if($this->currentTryTime >= $this->maxTryTime){
                    //超过重连次数
                    throw new \Exception("redis reconnect fail");
                }
                usleep(100000);
                $this->connect();
                $this->currentTryTime ++ ;
                continue;
            }

        }
    }
  /*  public static function encode($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public static function decode($value)
    {
        return json_decode($value, true);
    }*/
}


