<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-10
 * Time: 上午10:40
 */
//----------------------------------
// 日志类文件
//----------------------------------
namespace App\Core\Utility;
class Log
{
    /*public static $log_show = false;
    public static $log_type = false;
    public static $log_file = "data/phpspider.log";
    public static $out_sta = "";
    public static $out_end = "";*/

    public static function note($msg)
    {
        //self::$out_sta = self::$out_end = "";
        self::msg($msg, 'note');
    }

    public static function info($msg)
    {
        //self::$out_sta = self::$out_end = "";
        self::msg($msg, 'info');
    }

    public static function warn($msg)
    {
        /*self::$out_sta = self::$out_end = "";
        if (!util::is_win()) 
        {
            self::$out_sta = "\033[33m";
            self::$out_end = "\033[0m";
        }*/

        self::msg($msg, 'warn');
    }

    public static function debug($msg)
    {
       /* self::$out_sta = self::$out_end = "";
        if (!util::is_win()) 
        {
            self::$out_sta = "\033[36m";
            self::$out_end = "\033[0m";
        }*/

        self::msg($msg, 'debug');
    }

    public static function error($msg)
    {
       /* self::$out_sta = self::$out_end = "";
        if (!util::is_win()) 
        {
            self::$out_sta = "\033[31m";
            self::$out_end = "\033[0m";
        }*/

        self::msg($msg, 'error');
    }

    public static function msg($msg, $log_type, $log_show = false)
    {

        $msg = date("Y-m-d H:i:s")." [{$log_type}] " . $msg . "\n";

        if($log_show)
        {
            echo $msg;
        }

        $filePrefix = date('ym');
        $filePath = base_path("storage/logs")."/{$filePrefix}_log.txt";
        file_put_contents($filePath, $msg.PHP_EOL, FILE_APPEND | LOCK_EX);

        /* file_put_contents(self::$log_file, $msg, FILE_APPEND | LOCK_EX);
        $filePrefix = date('ym');
        $filePath = ROOT."/{$filePrefix}_log.txt";
        file_put_contents($filePath,$str,FILE_APPEND|LOCK_EX);*/
    }

    /**
     * 记录日志 XXX
     * @param string $msg
     * @param string $log_type  Note|Warning|Error
     * @return void
     */
    public static function add($msg, $log_type = '', $log_show=false)
    {
        if ($log_type != '') 
        {
            $msg = date("Y-m-d H:i:s")." [{$log_type}] " . $msg . "\n";
        }
        if($log_show)
        {
            echo $msg;
        }
        //file_put_contents(PATH_DATA."/log/".strtolower($log_type).".log", $msg, FILE_APPEND | LOCK_EX);
        $filePrefix = date('ym');
        $filePath = base_path("storage/logs")."/{$filePrefix}_log.txt";
        file_put_contents($filePath, $msg.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

}

