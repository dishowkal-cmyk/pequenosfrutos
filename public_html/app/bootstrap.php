<?php
declare(strict_types=1);
namespace PF;
use PDO;
use RuntimeException;

require_once __DIR__.'/Domain.php';
require_once __DIR__.'/Gateway.php';
require_once __DIR__.'/Store.php';
require_once __DIR__.'/MailQueue.php';

function config(): array {
 static $c;
 if(isset($c)) return $c;
 $path=getenv('PF_CONFIG_PATH') ?: dirname(__DIR__,2).'/pf-private/config.php';
 if(!is_file($path)) throw new RuntimeException('SETUP_REQUIRED');
 $c=require $path;
 if(!is_array($c)||strlen((string)($c['app_key']??''))<32||str_contains((string)$c['app_key'],'TROQUE-')) throw new RuntimeException('SETUP_REQUIRED');
 date_default_timezone_set('America/Sao_Paulo');
 return $c;
}
function db(): PDO {
 static $pdo;
 if($pdo) return $pdo;
 $d=config()['database'];
 $dsn=getenv('PF_TEST_DSN') ?: "mysql:host={$d['host']};port=".($d['port']??3306).";dbname={$d['name']};charset=utf8mb4";
 $pdo=new PDO($dsn,$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $pdo->exec("SET time_zone='+00:00'");
 return $pdo;
}
function query(string $sql,array $values=[]): \PDOStatement { $s=db()->prepare($sql);$s->execute($values);return $s; }
function jsonOut(array $data,int $status=200): never {
 http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
 echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
}
function input(): array {
 if((int)($_SERVER['CONTENT_LENGTH']??0)>65536) throw new HttpError(413,'Requisição muito grande.');
 $raw=file_get_contents('php://input');
 if(strlen($raw)>65536)throw new HttpError(413,'Requisição muito grande.');
 try{$d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(400,'Dados inválidos.');}
 if(!is_array($d))throw new HttpError(400,'Dados inválidos.');
 return $d;
}
function secureHeaders(): void {
 header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
 header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
 header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
 if(($_SERVER['HTTPS']??'')==='on')header('Strict-Transport-Security: max-age=31536000');
}
function startSession(): void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 ini_set('session.use_strict_mode','1');
 session_name('pf_session');
 session_set_cookie_params(['httponly'=>true,'secure'=>(bool)(config()['https_required']??true),'samesite'=>'Lax','path'=>'/']);
 session_start();
 $_SESSION['csrf']??=bin2hex(random_bytes(24));
}
function csrf(): void {
 startSession();
 $header=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
 if(!hash_equals($_SESSION['csrf'],$header))throw new HttpError(403,'Atualize a página antes de continuar.');
 $origin=$_SERVER['HTTP_ORIGIN']??null;
 if($origin!==null&&rtrim($origin,'/')!==rtrim(config()['site_url'],'/'))throw new HttpError(403,'Origem inválida.');
}
function admin(): void {
 startSession();
 if(empty($_SESSION['admin_id'])||($_SESSION['admin_seen']??0)<time()-1800) {unset($_SESSION['admin_id']);throw new HttpError(401,'Entre no painel para continuar.');}
 $_SESSION['admin_seen']=time();
}
function rateLimit(string $scope,int $limit,int $window): void {
 $ip=$_SERVER['REMOTE_ADDR']??'cli';
 $key=hash_hmac('sha256',$scope.'|'.$ip.'|'.intdiv(time(),$window),config()['app_key']);
 query('INSERT INTO rate_limits (bucket,hits,expires_at) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE hits=hits+1',[$key,$window*2]);
 if((int)query('SELECT hits FROM rate_limits WHERE bucket=?',[$key])->fetchColumn()>$limit)throw new HttpError(429,'Muitas tentativas. Aguarde alguns minutos.');
}
function setting(string $key,mixed $default=null): mixed {
 $v=query('SELECT value_json FROM settings WHERE setting_key=?',[$key])->fetchColumn();
 return $v===false?$default:json_decode($v,true,32,JSON_THROW_ON_ERROR);
}
function setSetting(string $key,mixed $value): void {
 query('INSERT INTO settings (setting_key,value_json) VALUES (?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)',[$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
