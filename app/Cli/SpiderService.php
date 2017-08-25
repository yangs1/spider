<?php

/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-11
 * Time: 上午10:39
 */
namespace App\Cli;

use App\Cli\Jobs\SpiderWorker;
use App\Core\Utility\Log;
use Swoole\Mysql\Exception;

class SpiderService extends SpiderWorker
{

    public function boot(){
        try {
            swoole_set_process_name(sprintf('php-spider:%s', 'master'));
            $this->do_collect_page();
            $this->processWait();
        }catch (\Exception $e){
            //die('ALL ERROR: '.$e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    public function do_collect_page(){
        //TODO  未完成
        while( $queue_lsize = $this->queue_lsize() )
        {
            // 如果是主任务
            if ($this->isMaster) {
                // 多任务下主任务未准备就绪
                if ($this->taskNum > 1 && count($this->worker)+1 < $this->taskNum)
                {
                    // 主进程采集到两倍于任务数时, 生成子任务一起采集
                    if ( $queue_lsize > $this->taskNum*2 )
                    {
                        // fork 子进程前一定要先干掉redis连接fd, 不然会存在进程互抢redis fd 问题
                        redis()->close();
                        // task进程从2开始, 1被master进程所使用
                        for ($i = 1; $i < ($this->taskNum); $i++)
                        {
                            $this->fork_one_task($i);
                        }
                    }


                }  // 抓取页面
                $this->collect_page();

                // 保存任务状态
                // $this->set_task_status();
                // 检查进程是否收到关闭信号
                $this->checkChild();

            }
            // 如果是子任务
            else {
                // 如果队列中的网页比任务数2倍多, 子任务可以采集, 否则等待...
                if ( $queue_lsize > $this->taskNum*2 ) {
                    // 抓取页面
                    $this->collect_page();
                    // 保存任务状态
                    //$this->set_task_status();
                }
                else {
                    //Log::warn("Task(".$this->taskIndex.") waiting...");
                    sleep(1);
                }
                if ($this->checkMpid($this->masterPid)){
                    break;
                }
            }


        }
    }

    /**
     * 创建一个子进程
     * @param $index
     * @return int
     */
    public function fork_one_task($index){
        $process = new \swoole_process(function(\swoole_process $worker)use($index){

            swoole_set_process_name(sprintf('php-ps:%s',$index));
            // 初始化子进程参数
            $this->time_start = microtime(true);
            $this->taskIndex     = $index;
            $this->isMaster = false;
            $this->taskPid    = posix_getpid();
            $this->collect_succ = 0;
            $this->collect_fail = 0;
            $this->do_collect_page();

            $worker->exit(0);
        }, false, false);
        $pid = $process->start();
        $this->worker[$index] = $pid;
        return $pid;
    }

    public function checkMpid($pid){
        if(!\swoole_process::kill($this->masterPid,0)){
            Log::error("Master process exited, I [{$pid}] also quit\n");
            return true;
            // 这句提示,实际是看不到的.需要写到日志中
        }
        return false;
    }
    public function checkChild(){
        while($ret =  \swoole_process::wait(false)) {
            if (isset($this->worker[$ret['pid']])){
                unset($this->worker[$ret['pid']]);
            }
        }

        return false;
    }

    public function processWait(){

        while($ret =  \swoole_process::wait(false)) {
            echo "PID={$ret['pid']}\n";
        }
    }


    /**
     * 设置任务状态, 主进程和子进程每成功采集一个页面后调用
     *
     * @return void
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-10-30 23:56
     */
    public function set_task_status()
    {
        //TODO 未完成
        // 每采集成功一个页面, 生成当前进程状态到文件, 供主进程使用
        $mem = round(memory_get_usage(true)/(1024*1024),2);
        $use_time = microtime(true) - $this->time_start;
        $speed = round(($this->collect_succ + $this->collect_fail) / $use_time, 2);
        $status = array(
            'id' => $this->taskIndex,
            'pid' => $this->taskPid,
            'mem' => $mem,
            'collect_succ' => $this->collect_succ,
            'collect_fail' => $this->collect_fail,
            'speed' => $speed,
        );
        $task_status = json_encode($status);

        $key = "server-"." uniqid server "."-task_status-".$this->taskIndex;
        redis()->set($key, $task_status);
    }


}