<?php

/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-9
 * Time: 上午9:38
 */
namespace App;

use App\Cli\SpiderService;
use App\Core\Utility\Config;
use App\Core\Utility\Redis;
use App\Event\Event;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;


class Application extends Container {

    /**
     * The base path of the application installation.
     *
     * @var string
     */
    protected $basePath;
    /**
     * Create a new Lumen application instance.
     * @param null $basePath
     */
    public function __construct($basePath = null){
        date_default_timezone_set('Asia/Shanghai');
        $this->basePath = $basePath;
        $this->bootstrapContainer();
        //$this->registerErrorHandling();

    }

    /**
     * Bootstrap the application container.
     *
     * @return void
     */
    protected function bootstrapContainer()
    {
        static::setInstance($this);

        $this->instance('app', $this);
        $this->instance('path', $this->path());

        $this->registerConfigBindings();
        $this->registerEventBindings();
        $this->registerDataBaseBindings();
    }

    protected function parseCommand(){
        // 检查运行命令的参数
        global $argv;
        $start_file = $argv[0];
        //var_dump($start_file);
        // 命令
        $command = isset($argv[1]) ? trim($argv[1]) : 'start';

        // 子命令, 目前只支持-d
        $param = isset($argv[2]) ? $argv[2] : '';

        // 根据命令做相应处理
        switch($command)
        {
            // 启动
            case 'start':
                return "cli";
                break;
            default :
                exit("Usage: php yourfile.php {start|stop|status|kill}\n");
        }
    }

    public function run(){
        $model = $this->parseCommand();
        if ($model == 'cli'){
            $this->executeCli();
        }
    }


    public function executeCli(){
        //$this->register(SwooleService::class);
        $this->register(SpiderService::class, config('spider'));
    }

    /**
     * Register a service provider with the application.
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @param  array $params
     * @return mixed
     * @throws \Exception
     */
    public function register($provider, $params = []){
        if (is_string($provider) && class_exists($provider)){
            $provider = new $provider($params);
        }else if (is_object($provider)){

        }else{
            throw new \Exception("the provider");
        }


        if (method_exists($provider, 'handler')) {
            $provider->handler();
        }

        if (method_exists($provider, 'boot')) {
            return $provider->boot();
        }
    }

    /**
     * Register container bindings for the application.
     *
     * @return void
     */
    protected function registerConfigBindings()
    {

        $this->singleton('config', Config::class);
        $this->extend("config", function (Config $config ){
            if (file_exists($path = $this->path().DIRECTORY_SEPARATOR.'Config/app.php')) {
                //var_dump($path);
                foreach (require $path as $key => $value){
                    $config->set($key, $value);
                }
            }
            return $config;
        });
    }
    /**
     * Register event bindings for the application.
     * Register App\Event\Event on*
     * @return void
     */
    protected function registerEventBindings(){
        $this->singleton('events', Event::class);
    }
    /**
     * Register database bindings for the application.
     * Register App\Event\Event on*
     * @return void
     */

    protected function registerDataBaseBindings(){
        $this->singleton('database', Manager::class);
        $this->extend('database', function ( Manager $container){

            foreach (config('connections') as $db => $config){
                $container->addConnection($config, $db);
            }
            $container->setAsGlobal();
            return $container;

        });

        if (!extension_loaded("redis")) {
            throw new \Exception("The redis extension was not found");
        }
        $this->singleton('redis', Redis::class);
        $this->extend('redis', function (Redis $redis){
            $redis->configs = config('redis');
            return $redis;
        });
    }

    /**
     * Register the swoole Bindings in the container.
     * @return void
     */
    protected function registerSwooleBindings(){

    }
    protected function registerErrorHandling()
    {
        error_reporting(-1);

        set_error_handler(function ($level, $message, $file = '', $line = 0) {
            /* if (error_reporting() & $level) {
                 throw new ErrorException($message, 0, $level, $file, $line);
             }*/
            var_dump("??????/");
        });

        /* set_exception_handler(function ($e) {
             $this->handleUncaughtException($e);
         });

         register_shutdown_function(function () {
             $this->handleShutdown();
         });*/
    }

    /**
     * Get the base path for the application.
     *
     * @param  string|null  $path
     * @return string
     */
    public function basePath($path = null)
    {
        if (isset($this->basePath)) {
            return $this->basePath.($path ? '/'.$path : $path);
        }
        $this->basePath = getcwd();
        return $this->basePath($path);
    }
    /**
     * Get the path to the application "app" directory.
     *
     * @return string
     */
    public function path()
    {
        return $this->basePath().DIRECTORY_SEPARATOR.'app';
    }


}