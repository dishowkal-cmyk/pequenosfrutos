<?php
declare(strict_types=1);
namespace PF;
require __DIR__.'/app/bootstrap.php';
secureHeaders();
set_exception_handler(function(\Throwable $e){
 if($e instanceof HttpError)jsonOut(['error'=>$e->getMessage()],$e->status);
 if($e->getMessage()==='SETUP_REQUIRED')jsonOut(['error'=>'Conclua a instalação da loja.','setup_required'=>true],503);
 error_log('PequenosFrutos: '.get_class($e));jsonOut(['error'=>'Não foi possível concluir agora. Se já gerou um Pix, consulte o pedido em andamento.'],500);
});
$c=config();
if(($c['https_required']??true)&&($_SERVER['HTTPS']??'')!=='on')throw new HttpError(400,'Use a conexão HTTPS da loja.');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
if($path==='/api/webhook/c7'){
 if($method!=='POST')throw new HttpError(405,'Método inválido.');
 if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new HttpError(413,'Evento muito grande.');
 $raw=file_get_contents('php://input');if(strlen($raw)>65536)throw new HttpError(413,'Evento muito grande.');
 try{$out=Store::webhook($raw,['timestamp'=>$_SERVER['HTTP_X_C7_TIMESTAMP']??'','signature'=>$_SERVER['HTTP_X_C7_SIGNATURE']??'','event'=>$_SERVER['HTTP_X_C7_EVENT']??'']);}
 catch(\JsonException){throw new HttpError(400,'Evento inválido.');}
 jsonOut($out);
}
startSession();
if($path==='/api/bootstrap'&&$method==='GET'){
 jsonOut(['csrf'=>$_SESSION['csrf'],'store'=>setting('store',[]),'checkout_enabled'=>(bool)setting('checkout_enabled',false)&&!empty($c['c7']['enabled'])&&!empty($c['c7']['webhook_secret']),'products'=>Store::catalog(),'admin'=>!empty($_SESSION['admin_id'])]);
}
if(preg_match('~^/api/cep/(\d{8})$~D',$path,$m)&&$method==='GET'){
 rateLimit('cep',45,60);$cep=$m[1];
 $cache=$_SESSION['cep_cache'][$cep]??null;if($cache&&$cache['expires']>time())jsonOut($cache['data']);
 $ch=curl_init('https://viacep.com.br/ws/'.$cep.'/json/');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($http!==200||!is_string($raw))throw new HttpError(503,'Não foi possível consultar o CEP agora. Tente novamente.');
 $d=json_decode($raw,true);if(!is_array($d)||!empty($d['erro'])||preg_replace('/\D/','',(string)($d['cep']??''))!==$cep)throw new HttpError(422,'CEP não encontrado.');
 $address=['cep'=>$cep,'street'=>(string)($d['logradouro']??''),'district'=>(string)($d['bairro']??''),'city'=>(string)($d['localidade']??''),'state'=>(string)($d['uf']??'')];
 if(count($_SESSION['cep_cache']??[])>20)$_SESSION['cep_cache']=[];
 $_SESSION['cep_cache'][$cep]=['expires'=>time()+600,'data'=>$address];jsonOut($address);
}
if($path==='/api/quote'&&$method==='POST'){csrf();rateLimit('quote',60,60);jsonOut(Store::quote(input()));}
if($path==='/api/checkout'&&$method==='POST'){csrf();rateLimit('checkout',12,3600);$d=input();session_write_close();jsonOut((new Store())->checkout($d));}
if($path==='/api/recover'&&$method==='POST'){csrf();rateLimit('recover',60,60);jsonOut(['order'=>Store::recover((string)(input()['idempotency_key']??''))]);}
if(preg_match('~^/api/order/([a-f0-9]{64})$~D',$path,$m)&&$method==='GET'){rateLimit('order',90,60);jsonOut(Store::publicOrder($m[1]));}
if($path==='/api/admin/login'&&$method==='POST'){
 csrf();rateLimit('login',8,900);$d=input();$email=mb_strtolower(trim((string)($d['email']??'')));$pass=(string)($d['password']??'');
 if(strlen($pass)>500)throw new HttpError(401,'E-mail ou senha incorretos.');
 $a=query('SELECT * FROM admin_users WHERE email=?',[$email])->fetch();
 if(!$a||!password_verify($pass,$a['password_hash']))throw new HttpError(401,'E-mail ou senha incorretos.');
 session_regenerate_id(true);$_SESSION['admin_id']=(int)$a['id'];$_SESSION['admin_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(24));jsonOut(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if(str_starts_with($path,'/api/admin/')){
 admin();
 if($method!=='GET')csrf();
 if($path==='/api/admin/logout'&&$method==='POST'){$_SESSION=[];session_regenerate_id(true);jsonOut(['ok'=>true]);}
 if($path==='/api/admin/overview'&&$method==='GET'){
  $orders=query('SELECT id,external_id,state,customer_email,total_cents,fulfillment,inventory_attention,created_at FROM orders ORDER BY id DESC LIMIT 100')->fetchAll();
  $products=query('SELECT * FROM products ORDER BY sort_order,title')->fetchAll();$variants=query('SELECT * FROM variants ORDER BY id')->fetchAll();
  foreach($products as&$p)$p['variants']=array_values(array_filter($variants,fn($v)=>$v['product_id']===$p['id']));
  jsonOut(['orders'=>$orders,'products'=>$products,'shipping'=>query('SELECT * FROM shipping_rules ORDER BY id')->fetchAll(),'coupons'=>query('SELECT * FROM coupons ORDER BY code')->fetchAll(),'store'=>setting('store',[]),'checkout_enabled'=>setting('checkout_enabled',false),
   'readiness'=>['gateway'=>!empty($c['c7']['enabled'])&&!empty($c['c7']['api_key'])&&!empty($c['c7']['secret']),'webhook'=>!empty($c['c7']['webhook_secret']),'smtp'=>!empty($c['smtp']['enabled']),'pending_emails'=>(int)query("SELECT COUNT(*) FROM email_outbox WHERE state<>'sent'")->fetchColumn()],'version'=>'hostinger-1.0']);
 }
 if(preg_match('~^/api/admin/orders/(\d+)$~D',$path,$m)&&$method==='GET'){
  $o=query('SELECT access_token,admin_note,inventory_attention FROM orders WHERE id=?',[$m[1]])->fetch();if(!$o)throw new HttpError(404,'Pedido não encontrado.');
  jsonOut(['order'=>Store::publicOrder($o['access_token']),'admin_note'=>$o['admin_note'],'inventory_attention'=>(bool)$o['inventory_attention'],'attempts'=>query('SELECT acquirer,state,http_status,gateway_id,created_at FROM payment_attempts WHERE order_id=? ORDER BY id',[$m[1]])->fetchAll()]);
 }
 if($path==='/api/admin/products/save'&&$method==='POST'){
  $d=input();$id=Identity::text($d['id']??'',64);$title=Identity::text($d['title']??'',160);$category=Identity::text($d['category']??'',60);
  $price=filter_var($d['price_cents']??null,FILTER_VALIDATE_INT);if($price===false||$price<0||$price>10000000)throw new HttpError(422,'Preço inválido.');
  $description=Identity::text($d['description']??'',2000,false);
  if(!query('SELECT id FROM products WHERE id=?',[$id])->fetchColumn())throw new HttpError(404,'Produto não encontrado.');
  $variants=$d['variants']??[];if(!is_array($variants)||count($variants)>60)throw new HttpError(422,'Variações inválidas.');
  db()->beginTransaction();
  try{
   query('UPDATE products SET title=?,category=?,description=?,price_cents=?,active=? WHERE id=?',[$title,$category,$description,$price,!empty($d['active'])?1:0,$id]);
   foreach($variants as$v){
    $stock=filter_var($v['stock']??null,FILTER_VALIDATE_INT);if($stock===false||$stock<0||$stock>100000)throw new HttpError(422,'Estoque inválido.');
    $size=Identity::text($v['size']??'',30);$color=Identity::text($v['color']??'',60);$vid=(int)($v['id']??0);
    if($vid>0){if(!query('SELECT id FROM variants WHERE id=? AND product_id=? FOR UPDATE',[$vid,$id])->fetchColumn())throw new HttpError(422,'Variação inválida.');query('UPDATE variants SET size=?,color=?,stock=? WHERE id=?',[$size,$color,$stock,$vid]);}
    else query('INSERT INTO variants (product_id,size,color,stock) VALUES (?,?,?,?)',[$id,$size,$color,$stock]);
   }
   db()->commit();
  }catch(\Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  query('INSERT INTO audit_log (admin_id,action,entity_id) VALUES (?,?,?)',[$_SESSION['admin_id'],'product.save',$id]);jsonOut(['ok'=>true]);
 }
 if($path==='/api/admin/shipping/save'&&$method==='POST'){
  $d=input();$start=preg_replace('/\D/','',(string)($d['cep_start']??''));$end=preg_replace('/\D/','',(string)($d['cep_end']??''));
  $price=filter_var($d['price_cents']??null,FILTER_VALIDATE_INT);$min=filter_var($d['min_days']??null,FILTER_VALIDATE_INT);$max=filter_var($d['max_days']??null,FILTER_VALIDATE_INT);
  if(strlen($start)!==8||strlen($end)!==8||(int)$start>(int)$end||$price===false||$price<0||$price>35000||$min===false||$max===false||$min<0||$max<$min||$max>180)throw new HttpError(422,'Confira os dados de frete.');
  $label=Identity::text($d['label']??'',100);$id=(int)($d['id']??0);$values=[$label,(int)$start,(int)$end,$price,$min,$max,!empty($d['active'])?1:0];
  if($id>0)query('UPDATE shipping_rules SET label=?,cep_start=?,cep_end=?,price_cents=?,min_days=?,max_days=?,active=? WHERE id=?',[...$values,$id]);
  else query('INSERT INTO shipping_rules (label,cep_start,cep_end,price_cents,min_days,max_days,active) VALUES (?,?,?,?,?,?,?)',$values);
  jsonOut(['ok'=>true]);
 }
 if($path==='/api/admin/coupons/save'&&$method==='POST'){
  $d=input();$code=mb_strtoupper(trim((string)($d['code']??'')));$percent=(int)($d['percent']??0);$min=(int)($d['min_subtotal_cents']??0);
  if(!preg_match('/^[A-Z0-9_-]{2,40}$/D',$code)||$percent<1||$percent>90||$min<0)throw new HttpError(422,'Confira o cupom.');
  query('INSERT INTO coupons (code,percent,min_subtotal_cents,active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE percent=VALUES(percent),min_subtotal_cents=VALUES(min_subtotal_cents),active=VALUES(active)',[$code,$percent,$min,!empty($d['active'])?1:0]);jsonOut(['ok'=>true]);
 }
 if($path==='/api/admin/settings'&&$method==='POST'){
  $d=input();$store=[];
  foreach(['name','city','email','whatsapp','company_name','cnpj','address','returns','privacy']as$key)$store[$key]=Identity::text($d['store'][$key]??'',$key==='returns'||$key==='privacy'?2500:250,false);
  if($store['email']!==''&&!filter_var($store['email'],FILTER_VALIDATE_EMAIL))throw new HttpError(422,'E-mail de atendimento inválido.');
  $enabled=!empty($d['checkout_enabled']);
  if($enabled&&(empty($c['c7']['enabled'])||empty($c['c7']['api_key'])||empty($c['c7']['secret'])||empty($c['c7']['webhook_secret'])))throw new HttpError(422,'Configure as chaves e o webhook no arquivo privado antes de ativar o checkout.');
  if($enabled&&(!(int)query('SELECT COUNT(*) FROM shipping_rules WHERE active=1')->fetchColumn()||!(int)query('SELECT COUNT(*) FROM variants v JOIN products p ON p.id=v.product_id WHERE v.stock>0 AND p.price_cents>0 AND p.active=1')->fetchColumn()))throw new HttpError(422,'Configure o frete e ao menos um produto com preço e estoque.');
  setSetting('store',$store);setSetting('checkout_enabled',$enabled);jsonOut(['ok'=>true]);
 }
 if($path==='/api/admin/fulfillment'&&$method==='POST'){
  $d=input();$state=(string)($d['fulfillment']??'');
  if(!in_array($state,['pending','preparing','shipped','delivered'],true))throw new HttpError(422,'Status inválido.');
  $tracking=Identity::text($d['tracking_code']??'',100,false);
  query("UPDATE orders SET fulfillment=?,tracking_code=? WHERE id=? AND state='paid'",[$state,$tracking,(int)($d['id']??0)]);
  query('INSERT INTO audit_log (admin_id,action,entity_id) VALUES (?,?,?)',[$_SESSION['admin_id'],'order.fulfillment',(string)($d['id']??0)]);jsonOut(['ok'=>true]);
 }
 if($path==='/api/admin/reconcile'&&$method==='POST'){
  $d=input();$id=(int)($d['id']??0);$note=Identity::text($d['note']??'',1000);
  if(mb_strlen($note)<10)throw new HttpError(422,'Descreva a conferência realizada na gateway.');
  db()->beginTransaction();
  try{
   $o=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$o||$o['state']==='paid'||!hash_equals($o['external_id'],(string)($d['confirm_no_charge']??'')))throw new HttpError(409,'Confira a referência e o estado do pedido.');
   query("UPDATE orders SET state='reconciled',admin_note=? WHERE id=?",[$note,$id]);db()->commit();
  }catch(\Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  Store::releaseStock($id);query('INSERT INTO audit_log (admin_id,action,entity_id) VALUES (?,?,?)',[$_SESSION['admin_id'],'order.reconcile_no_charge',(string)$id]);jsonOut(['ok'=>true]);
 }
}
throw new HttpError(404,'Rota não encontrada.');
