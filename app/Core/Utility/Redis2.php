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
    protected static $configs = array();
    private static $links = array();
    private $link_name = 'default';
    /**
     *  默认redis前缀
     */
    public $prefix  = "spider";

    public $error  = "";

    private $tryTime = 5;
    private $currentTime = 0;

    /**
     * @return mixed|null
     */
    public function connect(){
        // 获取配置
        $config = $this->link_name === 'default' ? self::_get_default_config() : self::$configs[$this->link_name];

        // 如果当前链接标识符为空，或者ping不同，就close之后重新打开
        //if ( empty(self::$links[self::$link_name]) || !self::ping() )
        if (empty(self::$links[$this->link_name])) {
            $this->_redis = new \Redis();
            if (!$this->_redis->connect($config['host'], $config['port'], $config['timeout'])) {
                $this->error = "Unable to connect to redis server\nPlease check the configuration file config/inc_config.php";
                unset($this->_redis);
                return null;
            }

            // 验证
            if ($config['password'])
            {
                if ( !$this->_redis->auth($config['password']) )
                {
                    $this->error = "Redis Server authentication failed\nPlease check the configuration file config/inc_config.php";
                    unset($this->_redis);
                    return null;
                }
            }

            $prefix = empty($config['prefix']) ? $this->prefix : $config['prefix'];
            $this->tryTime = empty($config['tryTime']) ? $this->tryTime : $config['tryTime'];
            $this->_redis->setOption(\Redis::OPT_PREFIX, $prefix . ":");
            $this->_redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
            $this->_redis->select($config['db']);
        }

        return self::$links[$this->link_name] = $this;
        //return self::$links[$this->link_name];
    }

    public static function clear_link()
    {
        if(self::$links)
        {
            foreach(self::$links as $k=>$v)
            {
                $v->close();
                unset(self::$links[$k]);
            }
        }
    }

    public function addConnection($config, $key="default"){
        self::$configs[$key] = $config;
    }

    public function schema($name = 'default')
    {
        if (isset(self::$links[$name])){
            return self::$links[$name];
        }
        if (isset(self::$configs[$name])){
            $this->link_name = $name;
            return $this->connect();
        }
        throw new \Exception("not found the config '{$name}' on the redis config");
    }


    /**
     * 获取默认配置
     */
    protected function _get_default_config()
    {
        if (empty(self::$configs['default'])) {
            self::$configs['default'] = ['host'=>"127.0.0.1",'port'=>'6379','password'=>'','database'=>0];
        }
        return self::$configs['default'];
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
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);

                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->setnx($key, $value, $expire);
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
     * get
     *
     * @param mixed $key
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function get($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->get($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->get($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->get($key);
        }
    }

    /**
     * del 删除数据
     *
     * @param mixed $key
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function del($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->del($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->del($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->del($key);
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
     * append 名称为key的string的值附加value
     *
     * @param mixed $key
     * @param mixed $value
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function append($key, $value)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->append($key, $value);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->append($key, $value);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->append($key, $value);
        }
    }

    /**
     * substr 返回名称为key的string的value的子串
     *
     * @param mixed $key
     * @param mixed $start
     * @param mixed $end
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function substr($key, $start, $end)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->substr($key, $start, $end);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->substr($key, $start, $end);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->substr($key, $start, $end);
        }
    }

    /**
     * select 按索引查询
     *
     * @param mixed $index
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function select($index)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->select($index);;
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->select($index);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->select($index);
        }

    }

    /**
     * dbsize 返回当前数据库中key的数目
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function dbsize()
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->dbsize();
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->dbsize();
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->dbsize();
        }
    }

    /**
     * flushdb 删除当前选择数据库中的所有key
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function flushdb()
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->flushdb();
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->flushdb();
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->flushdb();
        }

    }

    /**
     * flushall 删除所有数据库中的所有key
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function flushall()
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->flushall();
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->flushall();
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->flushall();
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
     * info 提供服务器的信息和统计
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function info()
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v =  $this->_redis->info();
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->info();
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->info();
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
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
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
     * lastsave 返回上次成功将数据保存到磁盘的Unix时戳
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-18 11:28
     */
    public function lastsave()
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lastsave();
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lastsave();
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->slowlog();
        }
    }

    /**
     * lpush 将数据从左边压入
     *
     * @param mixed $key
     * @param mixed $value
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function lpush($key, $value)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lpush($key, $value);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lpush($key, $value);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->lpush($key, $value);
        }

    }

    /**
     * rpush 将数据从右边压入
     *
     * @param mixed $key
     * @param mixed $value
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function rpush($key, $value)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->rpush($key, $value);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->rpush($key, $value);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->rpush($key, $value);
        }

    }

    /**
     * lpop 从左边弹出数据, 并删除数据
     *
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function lpop($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lpop($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lpop($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->lpop($key);
        }

    }

    /**
     * rpop 从右边弹出数据, 并删除数据
     *
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function rpop($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->rpop($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->rpop($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->rpop($key);
        }
    }

    /**
     * lsize 队列长度，同llen
     *
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function lsize($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lsize($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lsize($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->lsize($key);
        }

    }

    /**
     * lget 获取数据
     *
     * @param mixed $key
     * @param int $index
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function lget($key, $index = 0)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lget($key, $index);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lget($key, $index);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->lget($key, $index);
        }
    }

    /**
     * lRange 获取范围数据
     *
     * @param mixed $key
     * @param mixed $start
     * @param mixed $end
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function lRange($key, $start, $end)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->lRange($key, $start, $end);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->lRange($key, $start, $end);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->lRange($key, $start, $end);
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
     * keys
     *
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     * 查找符合给定模式的key。
     * KEYS *命中数据库中所有key。
     * KEYS h?llo命中hello， hallo and hxllo等。
     * KEYS h*llo命中hllo和heeeeello等。
     * KEYS h[ae]llo命中hello和hallo，但不命中hillo。
     * 特殊符号用"\"隔开
     * 因为这个类加了OPT_PREFIX前缀，所以并不能真的列出redis所有的key，需要的话，要把前缀去掉
     */
    public function keys($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->keys($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->keys($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->keys($key);
        }
    }

    /**
     * ttl 返回某个KEY的过期时间
     * 正数：剩余多少秒
     * -1：永不超时
     * -2：key不存在
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function ttl($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->ttl($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->ttl($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->ttl($key);
        }

    }

    /**
     * expire 为某个key设置过期时间,同setTimeout
     *
     * @param mixed $key
     * @param mixed $expire
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function expire($key, $expire)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->expire($key, $expire);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->expire($key, $expire);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->expire($key, $expire);
        }

    }

    /**
     * exists key值是否存在
     *
     * @param mixed $key
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    public function exists($key)
    {
        if ($this->_redis instanceof \Redis){
            try {
                $v = $this->_redis->exists($key);
                $this->currentTime = 0;
                return $v;
            } catch (\Exception $e) {
                $msg = "PHP Fatal error:  Uncaught exception 'RedisException' with message '".$e->getMessage()."'\n";
                //log::warn($msg);
                $this->_redis->close();
                $this->_redis = null;
                if ($e->getCode() == 0 && $this->currentTime < $this->tryTime) {
                    $this->currentTime++;
                    usleep(100000);
                    return $this->exists($key);
                }
                return false;
            }
        }else{
            $this->connect();
            return $this->exists($key);
        }
    }

    /**
     * ping 检查当前redis是否存在且是否可以连接上
     *
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2015-12-13 01:05
     */
    //protected static function ping()
    //{
    //if ( empty (self::$links[self::$link_name]) )
    //{
    //return false;
    //}
    //return self::$links[self::$link_name]->ping() == '+PONG';
    //}

  /*  public static function encode($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public static function decode($value)
    {
        return json_decode($value, true);
    }*/
}


