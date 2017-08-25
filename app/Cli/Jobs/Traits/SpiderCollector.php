<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-15
 * Time: 上午10:52
 */

namespace App\Cli\Jobs\Traits;


trait SpiderCollector  {

    /**
     * 连接对象格式化
     *
     * @param mixed $link
     * @return array
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-11-05 18:58
     */
    public function link_format($link)
    {
        $link = array(
            'url'          => isset($link['url'])          ? $link['url']          : '',
            'url_type'     => isset($link['url_type'])     ? $link['url_type']     : '',
            'method'       => isset($link['method'])       ? $link['method']       : 'get',
            'headers'      => isset($link['headers'])      ? $link['headers']      : array(),
            'params'       => isset($link['params'])       ? $link['params']       : array(),
            'context_data' => isset($link['context_data']) ? $link['context_data'] : '',
            'proxy'        => isset($link['proxy'])        ? $link['proxy']        : $this->configs['proxy'],
            'try_num'      => isset($link['try_num'])      ? $link['try_num']      : 0,
            'max_try'      => isset($link['max_try'])      ? $link['max_try']      : $this->configs['max_try'],
            'depth'        => isset($link['depth'])        ? $link['depth']        : 0,
        );

        return $link;
    }

    /**
     * 连接对象压缩
     *
     * @return array
     */
    public function link_compress($link)
    {
        if (empty($link['url_type'])) {
            unset($link['url_type']);
        }

        if (empty($link['method']) || strtolower($link['method']) == 'get') {
            unset($link['method']);
        }

        if (empty($link['headers'])) {
            unset($link['headers']);
        }

        if (empty($link['params'])) {
            unset($link['params']);
        }

        if (empty($link['context_data'])) {
            unset($link['context_data']);
        }

        if (empty($link['proxy'])) {
            unset($link['proxy']);
        }

        if (empty($link['try_num'])) {
            unset($link['try_num']);
        }

        if (empty($link['max_try'])) {
            unset($link['max_try']);
        }

        if (empty($link['depth'])) {
            unset($link['depth']);
        }
        //$json = json_encode($link);
        //$json = gzdeflate($json);
        return $link;
    }


    /**
     * 是否列表页面
     *
     * @param mixed $url
     * @return bool
     */
    public function is_list_page($url)
    {
        $result = false;
        if (!empty($this->configs['list_url_regexes']))
        {
            foreach ($this->configs['list_url_regexes'] as $regex)
            {
                if (preg_match("#{$regex}#i", $url))
                {
                    $result = true;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * 是否内容页面
     *
     * @param mixed $url
     * @return bool
     */
    public function is_content_page($url)
    {
        $result = false;
        if (!empty($this->configs['content_url_regexes']))
        {
            foreach ($this->configs['content_url_regexes'] as $regex) {
                if (preg_match("#{$regex}#i", $url)) {
                    $result = true;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * 投递页面到队列
     * @param $url
     * @param array $options
     * @param bool $allowed_repeat
     * @return bool
     */
    public function add_scan_url($url, $options = array(), $allowed_repeat = true){
        $link = $options;
        $link['url'] = $url;
        $link['url_type'] = 'scan_page';
        $link = $this->link_format($link);

        if ($this->is_list_page($url)) {
            $link['url_type'] = 'list_page';
            $status = $this->queue_lpush($link, $allowed_repeat);
        }
        elseif ($this->is_content_page($url)) {
            $link['url_type'] = 'content_page';
            $status = $this->queue_lpush($link, $allowed_repeat);
        }
        else {
            $status = $this->queue_lpush($link, $allowed_repeat);
        }
        return $status;
    }

    /**
     * 一般在 on_scan_page 和 on_list_page 回调函数中调用, 用来往待爬队列中添加url
     * 两个进程同时调用这个方法, 传递相同url的时候, 就会出现url重复进入队列
     *
     * @param $url
     * @param array $options
     * @param int $depth
     * @return bool
     */
    public function add_url($url, $options = array(), $depth = 0)
    {
        // 投递状态
        $status = false;

        $link = $options;
        $link['url'] = $url;
        $link['depth'] = $depth;
        $link = $this->link_format($link);

        if ($this->is_list_page($url))
        {
            $link['url_type'] = 'list_page';
            $status = $this->queue_lpush($link);
        }

        if ($this->is_content_page($url))
        {
            $link['url_type'] = 'content_page';
            $status = $this->queue_lpush($link);
        }

        return $status;
    }


    /**
     * 获得完整的连接地址
     *
     * @param mixed $url            要检查的URL
     * @param mixed $collect_url    从那个URL页面得到上面的URL
     * @return bool
     * @author seatle <seatle@foxmail.com>
     * @created time :2016-09-23 17:13
     */
    public function fill_url($url, $collect_url){
        $url = trim($url);
        $collect_url = trim($collect_url);

        // 排除JavaScript的连接
        if( preg_match("@^(javascript:|#|'|\")@i", $url) || $url == '') {
            return false;
        }
        // 排除没有被解析成功的语言标签
        if(substr($url, 0, 3) == '<%=') {
            return false;
        }

        $parse_url = @parse_url($collect_url);
        if (empty($parse_url['scheme']) || empty($parse_url['host']))
        {
            return false;
        }
        // 过滤mailto、tel、sms、wechat、sinaweibo、weixin等协议
        if (!in_array($parse_url['scheme'], array("http", "https")))
        {
            return false;
        }
        $scheme = $parse_url['scheme'];
        $domain = $parse_url['host'];
        $path = empty($parse_url['path']) ? '' : $parse_url['path'];
        $base_url_path = $domain.$path;
        $base_url_path = preg_replace("/\/([^\/]*)\.(.*)$/","/",$base_url_path);
        $base_url_path = preg_replace("/\/$/",'',$base_url_path);

        $i = $path_step = 0;
        $dstr = $pstr = '';
        $pos = strpos($url,'#');
        if($pos > 0) {
            // 去掉#和后面的字符串
            $url = substr($url, 0, $pos);
        }

        // 京东变态的都是 //www.jd.com/111.html
        if(substr($url, 0, 2) == '//')
        {
            $url = str_replace("//", "", $url);
        }
        // /1234.html
        elseif($url[0] == '/')
        {
            $url = $domain.$url;
        }
        // ./1234.html、../1234.html 这种类型的
        elseif($url[0] == '.')
        {
            if(!isset($url[2]))
            {
                return false;
            }
            else
            {
                $urls = explode('/',$url);
                foreach($urls as $u)
                {
                    if( $u == '..' )
                    {
                        $path_step++;
                    }
                    // 遇到 ., 不知道为什么不直接写$u == '.', 貌似一样的
                    else if( $i < count($urls)-1 )
                    {
                        $dstr .= $urls[$i].'/';
                    }
                    else
                    {
                        $dstr .= $urls[$i];
                    }
                    $i++;
                }
                $urls = explode('/',$base_url_path);
                if(count($urls) <= $path_step)
                {
                    return false;
                }
                else
                {
                    $pstr = '';
                    for($i=0;$i<count($urls)-$path_step;$i++){ $pstr .= $urls[$i].'/'; }
                    $url = $pstr.$dstr;
                }
            }
        }
        else
        {
            if( strtolower(substr($url, 0, 7))=='http://' )
            {
                $url = preg_replace('#^http://#i','',$url);
                $scheme = "http";
            }
            else if( strtolower(substr($url, 0, 8))=='https://' )
            {
                $url = preg_replace('#^https://#i','',$url);
                $scheme = "https";
            }
            else
            {
                $url = $base_url_path.'/'.$url;
            }
        }
        // 两个 / 或以上的替换成一个 /
        $url = preg_replace('@/{1,}@i', '/', $url);
        $url = $scheme.'://'.$url;
        //echo $url;exit("\n");

        $parse_url = @parse_url($url);
        // $domain = empty($parse_url['host']) ? $domain : $parse_url['host'];
        // 如果host不为空, 判断是不是要爬取的域名
        if (!empty($parse_url['host']))
        {
            //排除非域名下的url以提高爬取速度
            if (!in_array($parse_url['host'], $this->configs['domains']))
            {
                return false;
            }
        }

        return $url;
    }
}