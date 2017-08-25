<?php

/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-15
 * Time: 上午9:48
 */
namespace App\Cli\Jobs;

use App\Cli\Jobs\Traits\SpiderCollector;
use App\Cli\Jobs\Traits\SpiderQueue;
use App\Core\Utility\Log;
use App\Core\Utility\Requests;
use App\Core\Utility\Selector;

abstract class SpiderWorker{
    use SpiderQueue,SpiderCollector;

    /**
     * 主进程PID
     */
    protected $masterPid;
    /**
     * 进程容器
     * @var array
     */
    protected $worker = [];
    /**
     * 是否为主进程
     * @var
     */
    protected $isMaster = true;
    /**
     * 配置项
     * @var array
     */
    protected $configs = [];

    /**
     * 任务数
     * @var int
     */
    protected $taskNum = 1;
    /**
     * 当前任务编号
     * @var
     */
    protected $taskIndex;
    /**
     * 当前任务PID
     * @var
     */
    protected $taskPid;

    protected $time_start;

    protected $collect_succ;
    protected $collect_fail;

    const AGENT_PC = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36";
    const AGENT_IOS = "Mozilla/5.0 (iPhone; CPU iPhone OS 9_3_3 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13G34 Safari/601.1";
    const AGENT_ANDROID = "Mozilla/5.0 (Linux; U; Android 6.0.1;zh_cn; Le X820 Build/FEXCNFN5801507014S) AppleWebKit/537.36 (KHTML, like Gecko)Version/4.0 Chrome/49.0.0.0 Mobile Safari/537.36 EUI Browser/5.8.015S";


    /******************回调钩子****************/
    public $on_status_code = null;
    public $is_anti_spider = null;
    public $on_download_page =null;
    public $on_scan_page = null;
    public $on_list_page = null;
    public $on_content_page = null;
    public $on_fetch_url = null;
    public $on_download_attached_page = null;
    public $on_handle_img = null;
    public $on_extract_field = null;
    public $on_extract_page = null;

    public function __construct($configs = array()){//config('spider')
        //$values = $this->get_fields_xpath(file_get_contents('/var/www/index.html'), "//div[@id='info']//span[contains(text(), \"作者\")]/following-sibling::a[1]/text()", "book_author");
        //var_dump($values);

        $configs['name']        = isset($configs['name'])        ? $configs['name']        : 'phpspider';
        $configs['proxy']       = isset($configs['proxy'])       ? $configs['proxy']       : '';
        $configs['user_agent']  = isset($configs['user_agent'])  ? $configs['user_agent']  : self::AGENT_PC;
        $configs['user_agents'] = isset($configs['user_agents']) ? $configs['user_agents'] : null;
        $configs['client_ip']   = isset($configs['client_ip'])   ? $configs['client_ip']   : null;
        $configs['client_ips']  = isset($configs['client_ips'])  ? $configs['client_ips']  : null;
        $configs['interval']    = isset($configs['interval'])    ? $configs['interval']    : 300;
        $configs['timeout']     = isset($configs['timeout'])     ? $configs['timeout']     : 5;
        $configs['max_try']     = isset($configs['max_try'])     ? $configs['max_try']     : 3;
        $configs['max_depth']   = isset($configs['max_depth'])   ? $configs['max_depth']   : 0;
        $configs['max_fields']  = isset($configs['max_fields'])  ? $configs['max_fields']  : 0;
        //$configs['export']      = isset($configs['export'])      ? $configs['export']      : array();
        $configs['export'] = true;

        // 是否设置了并发任务数, 并且大于1, 而且不是windows环境
        if (isset($configs['tasknum']) && $configs['tasknum'] > 1) {
            $this->taskNum = $configs['tasknum'];
        }

        $this->configs = $configs;

        $this->masterPid = posix_getpid();
        // 添加入口URL到队列
        if ($this->check_cache()){
            foreach ($configs['scan_urls'] as $url ) {
                // false 表示不允许重复
                $this->add_scan_url($url, null, false);
            }
        }else{
          /*  $list = db()->table('douban_fail_page')->where("name",'fail')->orderBy("id",'desc')->get();
            foreach ($list as $item){
                $this->add_scan_url($item->url, null, true);
            }*/
        }
    }

    /**
     * 下载网页, 得到网页内容
     *
     * @param mixed $url
     * @param mixed $link
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-09-18 10:17
     */
    public function request_url($url, $link = array()){
        $time_start = microtime(true);

        //$url = "http://www.qiushibaike.com/article/117568316";

        // 设置了编码就不要让requests去判断了
        if (isset($this->configs['input_encoding']))
        {
            Requests::$input_encoding = $this->configs['input_encoding'];
        }
        // 得到的编码如果不是utf-8的要转成utf-8, 因为xpath只支持utf-8
        Requests::$output_encoding = 'utf-8';
        Requests::set_timeout($this->configs['timeout']);
        Requests::set_useragent($this->configs['user_agent']);
        if ($this->configs['user_agents'])
        {
            Requests::set_useragents($this->configs['user_agents']);
        }
        if ($this->configs['client_ip'])
        {
            Requests::set_client_ip($this->configs['client_ip']);
        }
        if ($this->configs['client_ips'])
        {
            Requests::set_client_ips($this->configs['client_ips']);
        }

        // 是否设置了代理
        if (!empty($link['proxy']))
        {
            Requests::set_proxies(array('http'=>$link['proxy'], 'https'=>$link['proxy']));
            // 自动切换IP
            Requests::set_header('Proxy-Switch-Ip', 'yes');
        }

        // 如何设置了 HTTP Headers
        if (!empty($link['headers'])) {
            foreach ($link['headers'] as $k=>$v)
            {
                Requests::set_header($k, $v);
            }
        }

        $method = empty($link['method']) ? 'get' : strtolower($link['method']);
        $params = empty($link['params']) ? array() : $link['params'];
        $html = Requests::$method($url, $params);
        // 此url附加的数据不为空, 比如内容页需要列表页一些数据, 拼接到后面去
        if ($html && !empty($link['context_data']))
        {
            $html .= $link['context_data'];
        }

        $http_code = Requests::$status_code;


        //回调钩子
        if ($this->on_status_code)
        {
            $return = call_user_func($this->on_status_code, $http_code, $url, $html, $this);
            if (isset($return))
            {
                $html = $return;
            }
            if (!$html)
            {
                return false;
            }
        }

        if ($http_code != 200) {
            // 如果是301、302跳转, 抓取跳转后的网页内容
            if ($http_code == 301 || $http_code == 302) {
                $info = Requests::$info;
                if (isset($info['redirect_url'])) {
                    $url = $info['redirect_url'];
                    Requests::$input_encoding = null;
                    $html = $this->request_url($url, $link);
                    if ($html && !empty($link['context_data']))
                    {
                        $html .= $link['context_data'];
                    }
                }
                else {
                    return false;
                }
            }
            else {
                if ($http_code == 407) {
                    // 扔到队列头部去, 继续采集
                    $this->queue_rpush($link);
                    Log::error("Failed to download page {$url}");
                    $this->collect_fail++;
                }
                elseif (in_array($http_code, array('0','502','503','429'))) {
                    // 采集次数加一
                    $link['try_num']++;
                    // 抓取次数 小于 允许抓取失败次数
                    if ( $link['try_num'] <= $link['max_try'] )
                    {
                        // 扔到队列头部去, 继续采集
                        $this->queue_rpush($link);
                    }
                    Log::error("Failed to download page {$url}, retry({$link['try_num']})");
                }
                else
                {
                    Log::error("Failed to download page {$url}");
                    $this->collect_fail++;
                    db()->table('douban_fail_page')->insert(['url'=>$url, 'name'=>"fail"]);
                }
                Log::error("HTTP CODE: {$http_code}");
                return false;
            }
        }
        // 爬取页面耗时时间
        $time_run = round(microtime(true) - $time_start, 3);
        Log::debug("Success download page {$url} in {$time_run} s");
        $this->collect_succ++;

        return $html;
    }

    /**
     * 爬取页面
     *
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-09-18 10:17
     */
    public function collect_page()
    {
        $get_collect_url_num = $this->get_collect_url_num();
        Log::info("Find pages: {$get_collect_url_num} ");

        $queue_lsize = $this->queue_lsize();
        Log::info("Waiting for collect pages: {$queue_lsize} ");

        $get_collected_url_num = $this->get_collected_url_num();
        Log::info("Collected pages: {$get_collected_url_num} ");


        // 先进先出
        $link = $this->queue_rpop();
        $link = $this->link_format($link);
        $url = $link['url'];

        // 爬取页数加一
        $this->incr_collected_url_num();

        // 爬取页面开始时间
        $page_time_start = microtime(true);

        Requests::$input_encoding = null;
        $html = $this->request_url($url, $link);

        if (!$html)
        {
            return false;
        }
        // 当前正在爬取的网页页面的对象
        $page = array(
            'url'     => $url,
            'raw'     => $html,
            'request' => array(
                'url'          => $url,
                'method'       => $link['method'],
                'headers'      => $link['headers'],
                'params'       => $link['params'],
                'context_data' => $link['context_data'],
                'try_num'      => $link['try_num'],
                'max_try'      => $link['max_try'],
                'depth'        => $link['depth'],
                'taskIndex'       => $this->taskIndex,
            ),
        );
        unset($html);

        //--------------------------------------------------------------------------------
        // 处理回调函数
        //--------------------------------------------------------------------------------

        // 判断当前网页是否被反爬虫了, 需要开发者实现
        if ($this->is_anti_spider) {
            $is_anti_spider = call_user_func($this->is_anti_spider, $url, $page['raw'], $this);
            // 如果在回调函数里面判断被反爬虫并且返回 true
            if ($is_anti_spider)
            {
                return false;
            }
        }

        // 在一个网页下载完成之后调用. 主要用来对下载的网页进行处理.
        // 比如下载了某个网页, 希望向网页的body中添加html标签
        if ($this->on_download_page)
        {
            $return = call_user_func($this->on_download_page, $page, $this);
            // 针对那些老是忘记return的人
            if (isset($return)) $page = $return;
        }

        // 是否从当前页面分析提取URL
        // 回调函数如果返回false表示不需要再从此网页中发现待爬url
        $is_find_url = true;
        if ($link['url_type'] == 'scan_page') {
            if ($this->on_scan_page)
            {
                $return = call_user_func($this->on_scan_page, $page, $page['raw'], $this);
                if (isset($return)) $is_find_url = $return;
            }
        } elseif ($link['url_type'] == 'list_page') {
            if ($this->on_list_page)
            {
                $return = call_user_func($this->on_list_page, $page, $page['raw'], $this);
                if (isset($return)) $is_find_url = $return;
            }
        } elseif ($link['url_type'] == 'content_page') {
            if ($this->on_content_page)
            {
                $return = call_user_func($this->on_content_page, $page, $page['raw'], $this);
                if (isset($return)) $is_find_url = $return;
            }
        }

        // on_scan_page、on_list_page、on_content_page 返回false表示不需要再从此网页中发现待爬url
        if ($is_find_url)
        {
            // 如果深度没有超过最大深度, 获取下一级URL
            if ($this->configs['max_depth'] == 0 || $link['depth'] < $this->configs['max_depth'])
            {
                // 分析提取HTML页面中的URL
                $this->get_urls($page['raw'], $url, $link['depth'] + 1);
            }
        }

        // 如果是内容页, 分析提取HTML页面中的字段
        // 列表页也可以提取数据的, source_type: urlcontext, 未实现
        if ($link['url_type'] == 'content_page')
        {
            $this->get_html_fields($page['raw'], $url, $page);
        }

        // 如果当前深度大于缓存的, 更新缓存
        $this->incr_depth_num($link['depth']);

        // 处理页面耗时时间
        $time_run = round(microtime(true) - $page_time_start, 3);
        Log::debug("Success process page {$url} in {$time_run} s");

        $spider_time_run = intval(microtime(true) - $this->time_start);
        Log::info("Spider running in {$spider_time_run}");

        // 爬虫爬取每个网页的时间间隔, 单位: 毫秒
        if (!isset($this->configs['interval'])) {
            // 默认睡眠100毫秒, 太快了会被认为是ddos
            $this->configs['interval'] = 100;
        }
        usleep($this->configs['interval'] * 1000);
        return true;
    }


    /**
     * 分析提取HTML页面中的URL
     * @param $html
     * @param $collect_url
     * @param int $depth
     * @return bool
     */
    public function get_urls($html, $collect_url, $depth = 0)
    {
        //--------------------------------------------------------------------------------
        // 正则匹配出页面中的URL
        //--------------------------------------------------------------------------------
        $urls = Selector::select($html, '//a/@href');

        if (empty($urls)) {
            return false;
        }

        // 如果页面上只有一个url，要把他转为数组，否则下面会报警告
        if (!is_array($urls)) {
            $urls = array($urls);
        }

        foreach ($urls as $key=>$url) {
            $urls[$key] = str_replace(array("\"", "'",'&amp;'), array("",'','&'), $url);
        }

        //--------------------------------------------------------------------------------
        // 过滤和拼凑URL
        //--------------------------------------------------------------------------------
        // 去除重复的RUL
        $urls = array_unique($urls);
        foreach ($urls as $k=>$url) {
            $url = trim($url);
            if (empty($url))
            {
                continue;
            }

            $val = $this->fill_url($url, $collect_url);
            if ($val) {
                $urls[$k] = $val;
            } else {
                unset($urls[$k]);
            }
        }

        if (empty($urls)) {
            return false;
        }

        //--------------------------------------------------------------------------------
        // 把抓取到的URL放入队列
        //--------------------------------------------------------------------------------
        foreach ($urls as $url) {
            if ($this->on_fetch_url) {
                $return = call_user_func($this->on_fetch_url, $url, $this);
                $url = isset($return) ? $return : $url;
                unset($return);

                // 如果 on_fetch_url 返回 false，此URL不入队列
                if (!$url)
                {
                    continue;
                }
            }
            // 把当前页当做找到的url的Referer页
            $options = array(
                'headers' => array(
                    'Referer' => $collect_url,
                )
            );
            $this->add_url($url, $options, $depth);
        }
        return true;
    }

    /**
     * 分析提取HTML页面中的字段
     * @param $html
     * @param $url
     * @param $page
     * @return bool
     */
    public function get_html_fields($html, $url, $page)
    {
        $fields = $this->get_fields($this->configs['fields'], $html, $url, $page);

        if (!empty($fields))
        {
            if ($this->on_extract_page)
            {
                $return = call_user_func($this->on_extract_page, $page, $fields);
                if (!isset($return))
                {
                    Log::warn("on_extract_page return value can't be empty");
                }
                elseif (!is_array($return))
                {
                    Log::warn("on_extract_page return value must be an array");
                }
                else
                {
                    $fields = $return;
                }
            }

            if (isset($fields) && is_array($fields))
            {
                $fields_num = $this->incr_fields_num();
               /* if ($this->configs['max_fields'] != 0 && $fields_num > $this->configs['max_fields'])
                {
                    exit(0);
                }*/

                if (version_compare(PHP_VERSION,'5.4.0','<'))
                {
                    $fields_str = json_encode($fields);
                    $fields_str = preg_replace_callback( "#\\\u([0-9a-f]{4})#i", function($matchs) {
                        return iconv('UCS-2BE', 'UTF-8', pack('H4', $matchs[1]));
                    }, $fields_str );
                }
                else
                {
                    $fields_str = json_encode($fields, JSON_UNESCAPED_UNICODE);
                }

                /*  if (util::is_win())
                  {
                      $fields_str = mb_convert_encoding($fields_str, 'gb2312', 'utf-8');
                  }*/
                Log::info("Result[{$fields_num}]: ".$fields_str);

                // 如果设置了导出选项
                if (!empty($this->configs['export']))
                {
                    /* $this->export_type = isset($this->configs['export']['type']) ? $this->configs['export']['type'] : '';
                     if ($this->export_type == 'csv')
                     {
                         util::put_file(self::$export_file, util::format_csv($fields)."\n", FILE_APPEND);
                     }
                     elseif (self::$export_type == 'sql')
                     {
                         $sql = db::insert(self::$export_table, $fields, true);
                         util::put_file(self::$export_file, $sql.";\n", FILE_APPEND);
                     }
                     elseif (self::$export_type == 'db')
                     {
                         db::insert(self::$export_table, $fields);
                     }*/

                    if (count($fields) < 14){
                        return false;
                    }
                    $data = array_map(function($field){
                        $str = strip_tags(trim($field));
                        return str_replace(["   ","\t","\n","\r"], ' ', $str);
                    },$fields);
                    db()->table('douban')->insert($data);

                }
            }
        }
    }

    public function test($val)
    {
        return "a";
    }

    /**
     * 根据配置提取HTML代码块中的字段
     *
     * @param $confs
     * @param $html
     * @param $url
     * @param $page
     * @return array
     */
    public function get_fields($confs, $html, $url, $page)
    {
        $fields = array();
        foreach ($confs as $conf)
        {
            // 当前field抽取到的内容是否是有多项
            $repeated = isset($conf['repeated']) && $conf['repeated'] ? true : false;
            // 当前field抽取到的内容是否必须有值
            $required = isset($conf['required']) && $conf['required'] ? true : false;

            if (empty($conf['name']))
            {
                Log::error("The field name is null, please check your \"fields\" and add the name of the field\n");
                exit;
            }

            $values = array();
            // 如果定义抽取规则
            if (!empty($conf['selector']))
            {
                // 如果这个field是上一个field的附带连接
                if (isset($conf['source_type']) && $conf['source_type']=='attached_url')
                {
                    // 取出上个field的内容作为连接, 内容分页是不进队列直接下载网页的
                    if (!empty($fields[$conf['attached_url']])) {
                        $collect_url = $this->fill_url($fields[$conf['attached_url']], $url);
                        //log::debug("Find attached content page: {$collect_url}");
                        $link['url'] = $collect_url;
                        $link = $this->link_format($link);
                        Requests::$input_encoding = null;
                        $html = $this->request_url($collect_url, $link);

                        // 在一个attached_url对应的网页下载完成之后调用. 主要用来对下载的网页进行处理.
                        if ($this->on_download_attached_page) {
                            $return = call_user_func($this->on_download_attached_page, $html, $this);
                            if (isset($return))
                            {
                                $html = $return;
                            }
                        }
                        // 请求获取完分页数据后把连接删除了
                        unset($fields[$conf['attached_url']]);
                    }
                }

                // 没有设置抽取规则的类型 或者 设置为 xpath
                if (!isset($conf['selector_type']) || $conf['selector_type']=='xpath') {
                    $values = $this->get_fields_xpath($html, $conf['selector'], $conf['name']);
                }
                elseif ($conf['selector_type']=='css') {
                    $values = $this->get_fields_css($html, $conf['selector'], $conf['name']);
                }
                elseif ($conf['selector_type']=='regex') {
                    $values = $this->get_fields_regex($html, $conf['selector'], $conf['name']);
                }

                // field不为空而且存在子配置
                if (!empty($values) && !empty($conf['children']))
                {
                    $child_values = array();
                    // 父项抽取到的html作为子项的提取内容
                    foreach ($values as $child_html)
                    {
                        // 递归调用本方法, 所以多少子项目都支持
                        $child_value = $this->get_fields($conf['children'], $child_html, $url, $page);
                        if (!empty($child_value))
                        {
                            $child_values[] = $child_value;
                        }
                    }
                    // 有子项就存子项的数组, 没有就存HTML代码块
                    if (!empty($child_values))
                    {
                        $values = $child_values;
                    }
                }
            }

            if (empty($values)) {
                // 如果值为空而且值设置为必须项, 跳出foreach循环
                if ($required)
                {
                    db()->table('douban_fail_page')->insert(['url'=>$url, 'name'=>$conf['name']]);
                    // 清空整个 fields
                    $fields = array();
                    break;
                }
                // 避免内容分页时attached_url拼接时候string + array了
                $fields[$conf['name']] = '';
                //$fields[$conf['name']] = array();
            }
            else {
                if (is_array($values))
                {
                    if ($repeated)
                    {
                        $fields[$conf['name']] = $values;
                    }
                    else
                    {
                        $fields[$conf['name']] = $values[0];
                    }
                }
                else
                {
                    $fields[$conf['name']] = $values;
                }
                // 不重复抽取则只取第一个元素
                //$fields[$conf['name']] = $repeated ? $values : $values[0];
            }
        }

        if (!empty($fields)) {
            foreach ($fields as $fieldname => $data)
            {
                $pattern = "/<img.*src=[\"']{0,1}(.*)[\"']{0,1}[> \r\n\t]{1,}/isU";
                /*$pattern = "/<img.*?src=[\'|\"](.*?(?:[\.gif|\.jpg|\.jpeg|\.png]))[\'|\"].*?[\/]?>/i"; */

                // 在抽取到field内容之后调用, 对其中包含的img标签进行回调处理
                if ($this->on_handle_img && preg_match($pattern, $data))
                {
                    $return = call_user_func($this->on_handle_img, $fieldname, $data);
                    if (!isset($return))
                    {
                        Log::warn("on_handle_img return value can't be empty\n");
                    }
                    else
                    {
                        // 有数据才会执行 on_handle_img 方法, 所以这里不要被替换没了
                        $data = $return;
                    }
                }

                // 当一个field的内容被抽取到后进行的回调, 在此回调中可以对网页中抽取的内容作进一步处理
                if ($this->on_extract_field) {
                    $return = call_user_func($this->on_extract_field, $fieldname, $data, $page);
                    if (!isset($return)) {
                        Log::warn("on_extract_field return value can't be empty\n");
                    }
                    else {
                        // 有数据才会执行 on_extract_field 方法, 所以这里不要被替换没了
                        $fields[$fieldname] = $return;
                    }
                }
            }
        }

        return $fields;
    }


    /**
     * 采用xpath分析提取字段
     * @param $html
     * @param $selector
     * @param $fieldname
     * @return bool|void
     */
    public function get_fields_xpath($html, $selector, $fieldname)
    {
        $result = Selector::select($html, $selector);
        if (Selector::$error)
        {
            Log::error("Field(\"{$fieldname}\") ".Selector::$error."\n");
        }
        return $result;
    }
    /**
     * 采用CSS选择器提取字段
     *
     * @param mixed $html
     * @param mixed $selector
     * @param mixed $fieldname
     * @return mixed
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-09-18 10:17
     */
    public function get_fields_css($html, $selector, $fieldname)
    {
        $result = Selector::select($html, $selector, 'css');
        if (Selector::$error)
        {
            Log::error("Field(\"{$fieldname}\") ".Selector::$error."\n");
        }
        return $result;
    }

    /**
     * 采用正则分析提取字段
     * @param $html
     * @param $selector
     * @param $fieldname
     * @return bool|void
     */
    public function get_fields_regex($html, $selector, $fieldname)
    {
        $result = Selector::select($html, $selector, 'regex');
        if (Selector::$error)
        {
            Log::error("Field(\"{$fieldname}\") ".Selector::$error."\n");
        }
        return $result;
    }

    public function check_cache()
    {
        //if (queue::exists("collect_queue"))
        $keys = redis()->keys("*");
        $count = count($keys);
        if ($count != 0)
        {
            // After this operation, 4,318 kB of additional disk space will be used.
            // Do you want to continue? [Y/n]
            //$msg = "发现Redis中有采集数据, 是否继续执行, 不继续则清空Redis数据重新采集\n";
            $msg = "Found that the data of Redis, no continue will empty Redis data start again\n";
            $msg .= "Do you want to continue? [Y/n]";
            fwrite(STDOUT, $msg);
            $arg = strtolower(trim(fgets(STDIN)));
            $arg = empty($arg) || !in_array($arg, array('y','n')) ? 'y' : $arg;
            if ($arg == 'n')
            {
                foreach ($keys as $key)
                {
                    $key = str_replace(redis()->getPrefix().":", "", $key);
                    redis()->del($key);
                }
                return true;
            }
            return false;
        }
        return true;
    }
}