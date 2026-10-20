<?php
// Somente para o servidor de desenvolvimento php -S. Hostinger usa .htaccess.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(preg_match('~^/(app|vendor|pf-private)(/|$)|(^|/)\.|\.sql$~i',$path)){http_response_code(403);exit;}
if(str_starts_with($path,'/api/')){require __DIR__.'/api.php';return;}
if($path==='/install.php'){require __DIR__.'/install.php';return;}
$file=realpath(__DIR__.$path);
if($file&&str_starts_with($file,__DIR__.DIRECTORY_SEPARATOR)&&is_file($file)&&$path!=='/api.php')return false;
require __DIR__.'/index.php';
