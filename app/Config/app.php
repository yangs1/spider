<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-9
 * Time: 下午11:21
 */

return [
    'redis' => [
        //'client' => 'predis',
        'host' => '127.0.0.1',
        'password' => '',
        'port' => 6379,
        'db' => 0,
        'prefix'=>"",
        'timeout'=>1,
        'tryTime'=>5
    ],

    'connections' => [
        'default' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => "spider",
            'username' => "root",
            'password' => 'root',
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
            //'unix_socket' => '',
            //'strict' => true,
            //'engine' => null,
        ],
    ],


    'spider'    => array(
        'name' => '豆瓣读书',
        'log_show' => false,
        'tasknum' => 2,
        //'save_running_state' => true,
        'domains' => array(
            'book.douban.com',
        ),
        'scan_urls' => array(
            'https://book.douban.com/tag/'.TAG.'/'
        ),
        'list_url_regexes' => array(
            "https://book.douban.com/tag/".TAG."?start=\d+\?s=\d+&type=T"
        ),
        'content_url_regexes' => array(
            "https://book.douban.com/subject/\d+(/)?$",
        ),
        'max_try' => 5,
        'export' => array(
            'type' => 'csv',
            'file' => '/douban.csv',
        ),
        'fields' => array(
            array(
                'name' => "book_tag",
                'selector' => "//strong[contains(@class,'rating_num')]",
                'required' => true,
            ),
            array(
                'name' => "book_name",
                'selector' => "//span[contains(@property,'v:itemreviewed')]",
                'required' => true,
            ),
            array(
                'name' => "book_author",
                'selector' => "//div[@id='info']//span[contains(text(), \"作者\")]/following-sibling::a[1]/text()",
                //'selector' => "//div[@id='info']/span[1]/following-sibling::a[1]/text()",
                //'selector' => "//div[@id='info']//span[1]//a",

                'required' => true,
            ),
            array(
                'name' => "book_publishing_house",
                'selector' => "@出版社:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_original_name",
                'selector' => "@原作名:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_subtitle",
                'selector' => "@副标题:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_translator",
                'selector' => "@译者:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_publishing_year",
                'selector' => "@出版年:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_page_num",
                'selector' => "@页数:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_price",
                'selector' => "@定价:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_binding",
                'selector' => "@装帧:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_series",
                'selector' => "@丛书:</span>&nbsp;(.*)<br>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_isbn",
                'selector' => "@ISBN:</span>(.*)<br/>@",
                'selector_type' => "regex",
            ),
            array(
                'name' => "book_rating",
                'selector' => "//strong[contains(@class,'rating_num')]",
                'required' => true,
            ),
            array(
                'name' => "book_rating_people",
                'selector' => "//span[contains(@property,'v:votes')]",
                'required' => true,
            ),
            array(
                'name' => "book_star5",
                'selector' => '@[^xyz]*5星[^xyz]*</span>[^xyz]*<div class="power" style="width:\d+px"></div>[^xyz]*<span class="rating_per">(.*)</span>[^xyz]*<br>@',
                'selector_type' => "regex",
                'required' => true,
            ),
            array(
                'name' => "book_star4",
                'selector' => '@[^xyz]*4星[^xyz]*</span>[^xyz]*<div class="power" style="width:\d+px"></div>[^xyz]*<span class="rating_per">(.*)</span>[^xyz]*<br>@',
                'selector_type' => "regex",
                'required' => true,
            ),
            array(
                'name' => "book_star3",
                'selector' => '@[^xyz]*3星[^xyz]*</span>[^xyz]*<div class="power" style="width:\d+px"></div>[^xyz]*<span class="rating_per">(.*)</span>[^xyz]*<br>@',
                'selector_type' => "regex",
                'required' => true,
            ),
            array(
                'name' => "book_star2",
                'selector' => '@[^xyz]*2星[^xyz]*</span>[^xyz]*<div class="power" style="width:\d+px"></div>[^xyz]*<span class="rating_per">(.*)</span>[^xyz]*<br>@',
                'selector_type' => "regex",
                'required' => true,
            ),
            array(
                'name' => "book_star1",
                'selector' => '@[^xyz]*1星[^xyz]*</span>[^xyz]*<div class="power" style="width:\d+px"></div>[^xyz]*<span class="rating_per">(.*)</span>[^xyz]*<br>@',
                'selector_type' => "regex",
                'required' => true,
            ),
        ),
    )
];