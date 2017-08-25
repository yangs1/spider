<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-15
 * Time: 上午10:52
 */

namespace App\Cli\Jobs\Traits;

trait SpiderQueue {
    /**
     * 从队列左边插入
     * @param array $link
     * @param bool $allowed_repeat
     * @return bool
     */
    public function queue_lpush($link = array(), $allowed_repeat = false){
        if (empty($link) || empty($link['url'])) {
            return false;
        }
        $url = $link['url'];
        $link = $this->link_compress($link);

        $status = false;
        $key = "collect_urls-".md5($url);
        // 加锁: 一个进程一个进程轮流处理
        if ( redis()->lock($key)) {

            $exists = redis()->exists($key);
            // 不存在或者当然URL可重复入
            if (!$exists || $allowed_repeat)
            {
                // 待爬取网页记录数加一
                redis()->incr("collect_urls_num");
                // 先标记为待爬取网页
                redis()->set($key, time());
                // 入队列
                $link = json_encode($link);
                redis()->lpush("collect_queue", $link);
                $status = true;
            }
            // 解锁
            redis()->unLock($key);
        }
        return $status;
    }

    /**
     * 从队列右边插入
     * @param array $link
     * @param bool $allowed_repeat
     * @return bool
     */
    public function queue_rpush($link = array(), $allowed_repeat = false)
    {
        if (empty($link) || empty($link['url'])) {
            return false;
        }

        $url = $link['url'];
        $status = false;
        $key = "collect_urls-".md5($url);
        // 加锁: 一个进程一个进程轮流处理
        if (redis()->lock($key))
        {
            $exists = redis()->exists($key);
            // 不存在或者当然URL可重复入
            if (!$exists || $allowed_repeat)
            {
                // 待爬取网页记录数加一
                $this->incr_collect_url_num();
                // 先标记为待爬取网页
                redis()->set($key, time());
                // 入队列
                $link = json_encode($link);
                redis()->rpush("collect_queue", $link);
                $status = true;
            }
            // 解锁
            redis()->unLock($key);
        }
        return $status;
    }

    /**
     * 从队列左边取出
     * 后进先出
     * 可以避免采集内容页有分页的时候采集失败数据拼凑不全
     * 还可以按顺序采集列表页
     */
    public function queue_lpop()
    {
        $link =  redis()->lpop("collect_queue");
        return  json_decode($link, true);
    }

    /**
     * 从队列右边取出
     * @return mixed
     */
    public function queue_rpop()
    {
        $link = redis()->rpop("collect_queue");
        return json_decode($link, true);
    }

    /**
     * 队列长度
     * @return int|mixed
     */
    public function queue_lsize()
    {
        return redis()->lsize("collect_queue");
    }



    /**
     * 提取到的field数目加一
     * @return bool
     */
    public function incr_fields_num()
    {
        return redis()->incr("fields_num");
    }

    /**
     * 采集深度加一
     * @param $depth
     */
    public function incr_depth_num($depth)
    {
        $lock = "lock-depth_num";
        if (redis()->lock($lock)) {
            if (redis()->get("depth_num") < $depth) {
                redis()->set("depth_num", $depth);
            }
            redis()->unLock($lock);
        }
    }

    /**
     * 等待爬取页面数量加一
     */
    public function incr_collect_url_num()
    {
        redis()->incr("collect_urls_num");
    }
    /**
     * 获取等待爬取页面数量
     * @return int
     */
    public function get_collect_url_num(){
        return redis()->get("collect_urls_num");
    }

    /**
     * 已采集页面数量加一
     */
    public function incr_collected_url_num()
    {
        redis()->incr("collected_urls_num");
    }
    /**
     * 获取已经爬取页面数量
     * @return int
     */
    public function get_collected_url_num()
    {
        return redis()->get("collected_urls_num");
    }
}