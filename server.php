<?php
/**
 * Created by PhpStorm.
 * User: yang
 * Date: 17-8-8
 * Time: 上午9:55
 */
require 'vendor/autoload.php';



use App\Application;

$a =new Application();
define("TAG", "小说");
$a->run();
//$a->run();