<?php
date_default_timezone_set('Asia/Shanghai');
define("PATH",dirname(__FILE__));
require_once(PATH . '/code/MiniSmarty.php');

$smarty = new MiniSmarty(true, PATH . '/cache');

$smarty->template = PATH . '/template';//模板文件路径
$smarty->resBase = 'http://127.0.0.1/template';//css js image 等静态文件路径
$smarty->leftDelimiter = '{{';
$smarty->rightDelimiter = '}}';

$smarty->assign("title", "api");
$smarty->assign("name", "api");
$smarty->assign("tip","(一)");
$smarty->assign("array",array(
    123=>"A",456=>"B", 789=>'C'));
$smarty->assign("time",time());
//渲染模板
$smarty->display('index.html');
?>
