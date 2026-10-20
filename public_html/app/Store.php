<?php
declare(strict_types=1);
namespace PF;
final class Store {
 public function __construct(private ?Gateway $gateway=null){$this->gateway??=new Gateway(config()['c7']);}
 public static function catalog():array {
  $products=query('SELECT id,title,category,description,image,price_cents FROM products WHERE active=1 ORDER BY sort_order,title')->fetchAll();
  $variants=query('SELECT id,product_id,size,color,stock FROM variants ORDER BY id')->fetchAll();
  $by=[];foreach($variants as$v){$v['id']=(int)$v['id'];$v['stock']=(int)$v['stock'];$by[$v['product_id']][]=$v;}
  foreach($products as&$p){$p['price_cents']=(int)$p['price_cents'];$p['variants']=$by[$p['id']]??[];}
  return $products;
 }
 public static function normalizedItems(array $items):array {
  if(!$items||count($items)>30)throw new HttpError(422,'Confira os itens da sacola.');
  $merged=[];
  foreach($items as$i){
   if(!is_array($i)||filter_var($i['variant_id']??null,FILTER_VALIDATE_INT)===false||filter_var($i['quantity']??null,FILTER_VALIDATE_INT)===false)throw new HttpError(422,'Confira os itens da sacola.');
   $id=(int)$i['variant_id'];$q=(int)$i['quantity'];
   if($id<1||$q<1||$q>12)throw new HttpError(422,'Quantidade inválida.');
   $merged[$id]=($merged[$id]??0)+$q;if($merged[$id]>12)throw new HttpError(422,'O limite por variação é de 12 unidades.');
  }
  if(array_sum($merged)>48)throw new HttpError(422,'O limite é de 48 unidades por pedido.');
  ksort($merged,SORT_NUMERIC);$out=[];foreach($merged as$id=>$q)$out[]=['variant_id'=>(int)$id,'quantity'=>$q];return $out;
 }
 public static function price(array $cart,string $cep,string $coupon='',bool $lock=false):array {
  if(!preg_match('/^\d{8}$/D',$cep))throw new HttpError(422,'Informe os oito números do CEP.');
  $items=self::normalizedItems($cart);$subtotal=0;$lines=[];
  foreach($items as$i){
   $row=query('SELECT v.id AS variant_id,v.size,v.color,v.stock,p.id AS product_id,p.title,p.image,p.price_cents FROM variants v JOIN products p ON p.id=v.product_id WHERE v.id=? AND p.active=1'.($lock?' FOR UPDATE':''),[$i['variant_id']])->fetch();
   if(!$row||(int)$row['price_cents']<=0||(int)$row['stock']<$i['quantity'])throw new HttpError(409,'Um item da sacola está indisponível. Confira os tamanhos e quantidades.');
   $row['quantity']=$i['quantity'];$row['unit_cents']=(int)$row['price_cents'];$subtotal+=$row['unit_cents']*$i['quantity'];$lines[]=$row;
  }
  $rule=query('SELECT * FROM shipping_rules WHERE active=1 AND cep_start<=? AND cep_end>=? ORDER BY (cep_end-cep_start),id DESC LIMIT 1',[(int)$cep,(int)$cep])->fetch();
  if(!$rule)throw new HttpError(422,'A entrega para este CEP ainda não está disponível.');
  $coupon=mb_strtoupper(trim($coupon));$discount=0;
  if($coupon!==''){
   if(!preg_match('/^[A-Z0-9_-]{2,40}$/D',$coupon))throw new HttpError(422,'Cupom inválido.');
   $c=query('SELECT * FROM coupons WHERE code=? AND active=1',[$coupon])->fetch();
   if(!$c||$subtotal<(int)$c['min_subtotal_cents'])throw new HttpError(422,'Esse cupom não se aplica à sua sacola.');
   $discount=intdiv($subtotal*(int)$c['percent']+50,100);
  }
  $shipping=(int)$rule['price_cents'];$total=$subtotal-$discount+$shipping;
  $fingerprint=hash('sha256',json_encode(['items'=>array_map(fn($i)=>[$i['variant_id'],$i['quantity'],$i['unit_cents']],$lines),'cep'=>$cep,'coupon'=>$coupon,'discount'=>$discount,'shipping'=>[$rule['id'],$shipping,$rule['min_days'],$rule['max_days'],$rule['label']]],JSON_THROW_ON_ERROR));
  return ['items'=>$lines,'subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'shipping_cents'=>$shipping,'total_cents'=>$total,'coupon'=>$coupon,'shipping'=>$rule,'fingerprint'=>$fingerprint];
 }
 public static function quote(array $input):array {
  $p=self::price($input['items']??[],(string)($input['cep']??''),(string)($input['coupon']??''));
  $p['quote_token']=QuoteToken::sign(['fingerprint'=>$p['fingerprint'],'expires'=>time()+900],config()['app_key']);
  unset($p['fingerprint']);$p['payable']=$p['total_cents']>=1000&&$p['total_cents']<=35000;
  $p['deadline_label']=(int)$p['shipping']['min_days'].' a '.(int)$p['shipping']['max_days'].' dias úteis após a confirmação';
  return $p;
 }
 private static function normalizedCustomer(array $d):array {
  $payer=Identity::name((string)($d['payer_name']??''));$cpf=Identity::cpf((string)($d['payer_cpf']??''));
  $email=mb_strtolower(trim((string)($d['email']??'')));
  if(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpError(422,'Informe um e-mail válido.');
  $raw=$d['address']??[];if(!is_array($raw))throw new HttpError(422,'Endereço inválido.');
  $address=['recipient'=>Identity::name((string)($raw['recipient']??'')),'cep'=>preg_replace('/\D/','',(string)($raw['cep']??'')),
   'street'=>Identity::text($raw['street']??''),'number'=>Identity::text($raw['number']??'',20),'complement'=>Identity::text($raw['complement']??'',80,false),
   'district'=>Identity::text($raw['district']??''),'city'=>Identity::text($raw['city']??'',100),'state'=>mb_strtoupper(Identity::text($raw['state']??'',2))];
  if(!preg_match('/^\d{8}$/D',$address['cep'])||!in_array($address['state'],['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'],true))throw new HttpError(422,'Confira o CEP e o estado.');
  return ['payer_name'=>$payer,'cpf'=>$cpf,'email'=>$email,'address'=>$address,'items'=>self::normalizedItems($d['items']??[]),'coupon'=>mb_strtoupper(trim((string)($d['coupon']??'')))];
 }
 public static function keyHash(string $key):string {
  if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new HttpError(422,'Atualize a página e tente novamente.');
  return hash('sha256',$key);
 }
 public function checkout(array $input):array {
  if(!setting('checkout_enabled',false)||empty(config()['c7']['enabled'])||empty(config()['c7']['webhook_secret']))throw new HttpError(503,'O pagamento Pix está temporariamente indisponível.');
  $data=self::normalizedCustomer($input);$key=self::keyHash((string)($input['idempotency_key']??''));
  $hash=hash_hmac('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),config()['app_key']);
  $existing=query('SELECT * FROM orders WHERE idempotency_hash=?',[$key])->fetch();
  if($existing){if(!hash_equals($existing['request_hash'],$hash))throw new HttpError(409,'Esta tentativa já pertence a outro pedido. Consulte o pedido em andamento.');return self::publicOrder($existing['access_token']);}
  $token=QuoteToken::verify((string)($input['quote_token']??''),config()['app_key']);$pdo=db();$pdo->beginTransaction();
  try{
   $price=self::price($data['items'],$data['address']['cep'],$data['coupon'],true);
   Money::pixRange($price['total_cents']);
   if(!hash_equals($token['fingerprint']??'',$price['fingerprint']))throw new HttpError(409,'O preço ou a entrega mudou. Confira novamente antes de gerar o Pix.');
   $external='PF-'.bin2hex(random_bytes(12));$access=bin2hex(random_bytes(32));
   query('INSERT INTO orders (external_id,access_token,idempotency_hash,request_hash,payer_name,cpf_last4,customer_email,shipping_json,subtotal_cents,discount_cents,shipping_cents,total_cents,coupon,shipping_label,min_days,max_days) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$external,$access,$key,$hash,$data['payer_name'],substr($data['cpf'],-4),$data['email'],json_encode($data['address'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$price['subtotal_cents'],$price['discount_cents'],$price['shipping_cents'],$price['total_cents'],$price['coupon'],$price['shipping']['label'],$price['shipping']['min_days'],$price['shipping']['max_days']]);
   $id=(int)$pdo->lastInsertId();
   foreach($price['items']as$i){query('UPDATE variants SET stock=stock-? WHERE id=? AND stock>=?',[$i['quantity'],$i['variant_id'],$i['quantity']]);
    query('INSERT INTO order_items (order_id,variant_id,title,size,color,image,quantity,unit_cents) VALUES (?,?,?,?,?,?,?,?)',[$id,$i['variant_id'],$i['title'],$i['size'],$i['color'],$i['image'],$i['quantity'],$i['unit_cents']]);}
   $pdo->commit();
  }catch(\Throwable $e){
   if($pdo->inTransaction())$pdo->rollBack();
   if($e instanceof \PDOException&&$e->getCode()==='23000'){
    $existing=query('SELECT * FROM orders WHERE idempotency_hash=?',[$key])->fetch();
    if($existing&&hash_equals($existing['request_hash'],$hash))return self::publicOrder($existing['access_token']);
   }throw $e;
  }
  $attemptId=null;
  $journal=function(string $state,array $info)use($id,&$attemptId){
   if($state==='started'){query('INSERT INTO payment_attempts (order_id,acquirer,state) VALUES (?,?,?)',[$id,$info['acquirer'],'started']);$attemptId=(int)db()->lastInsertId();}
   else if($attemptId!==null)query('UPDATE payment_attempts SET state=?,http_status=?,gateway_id=? WHERE id=?',[$state,$info['http']??null,($info['gateway_id']??'')?:null,$attemptId]);
  };
  $order=query('SELECT * FROM orders WHERE id=?',[$id])->fetch();
  $order['callback_url']=rtrim(config()['site_url'],'/').'/api/webhook/c7';
  try{$result=$this->gateway->create($order,$data['cpf'],$journal);}
  catch(\Throwable){query("UPDATE orders SET state='unknown' WHERE id=? AND state='creating'",[$id]);return self::publicOrder($access);}
  $expiration=null;if(!empty($result['expires_at'])){try{$expiration=(new \DateTimeImmutable($result['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(\Throwable){}}
  $pix=(string)($result['pix']??'');$usable=PixPayload::valid($pix,$price['total_cents']);
  $gatewayId=($result['gateway_id']??'')?:null;
  try{
   query("UPDATE orders SET state=CASE WHEN state='paid' THEN state ELSE ? END,gateway_id=COALESCE(gateway_id,?),pix_code=COALESCE(?,pix_code),expires_at=COALESCE(?,expires_at) WHERE id=?",[$result['state'],$gatewayId,$usable?$pix:null,$expiration,$id]);
  }catch(\PDOException){query("UPDATE orders SET state='unknown' WHERE id=? AND state<>'paid'",[$id]);}
  if($result['state']==='rejected')self::releaseStock($id);
  return self::publicOrder($access);
 }
 public static function releaseStock(int $id):void {
  $pdo=db();$pdo->beginTransaction();
  try{
   $o=query('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id])->fetch();
   if($o&&$o['state']!=='paid'&&$o['stock_released_at']===null){
    $items=query('SELECT variant_id,quantity FROM order_items WHERE order_id=? ORDER BY variant_id',[$id])->fetchAll();
    foreach($items as$i)query('UPDATE variants SET stock=stock+? WHERE id=?',[$i['quantity'],$i['variant_id']]);
    query('UPDATE orders SET stock_released_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
   }$pdo->commit();
  }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 }
 public static function publicOrder(string $token):array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new HttpError(404,'Pedido não encontrado.');
  $o=query('SELECT * FROM orders WHERE access_token=?',[$token])->fetch();if(!$o)throw new HttpError(404,'Pedido não encontrado.');
  $items=query('SELECT title,size,color,image,quantity,unit_cents FROM order_items WHERE order_id=? ORDER BY id',[$o['id']])->fetchAll();
  foreach($items as&$i){$i['quantity']=(int)$i['quantity'];$i['unit_cents']=(int)$i['unit_cents'];}
  $email=query('SELECT state FROM email_outbox WHERE order_id=?',[$o['id']])->fetchColumn();
  return ['external_id'=>$o['external_id'],'access_token'=>$o['access_token'],'state'=>$o['state'],'items'=>$items,'subtotal_cents'=>(int)$o['subtotal_cents'],'discount_cents'=>(int)$o['discount_cents'],'shipping_cents'=>(int)$o['shipping_cents'],'total_cents'=>(int)$o['total_cents'],
   'address'=>json_decode($o['shipping_json'],true),'email'=>$o['customer_email'],'email_state'=>$email?:'not_queued','pix_code'=>$o['pix_code'],'expires_at'=>$o['expires_at']?str_replace(' ','T',$o['expires_at']).'Z':null,
   'deadline_label'=>$o['min_days'].' a '.$o['max_days'].' dias úteis após a confirmação','eta_min'=>$o['eta_min'],'eta_max'=>$o['eta_max'],'paid_at'=>$o['paid_at'],'fulfillment'=>$o['fulfillment'],'tracking_code'=>$o['tracking_code']];
 }
 public static function recover(string $idempotency):?array {
  $o=query('SELECT access_token FROM orders WHERE idempotency_hash=?',[self::keyHash($idempotency)])->fetch();
  return $o?self::publicOrder($o['access_token']):null;
 }
 public static function webhook(string $body,array $headers):array {
  $secret=(string)(config()['c7']['webhook_secret']??'');
  if(!Gateway::verifyWebhook((string)($headers['timestamp']??''),(string)($headers['signature']??''),$body,$secret))throw new HttpError(401,'Assinatura inválida.');
  $d=json_decode($body,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($d))throw new HttpError(400,'Evento inválido.');
  if(($headers['event']??'')!=='payment.confirmed'||($d['status']??'')!=='APPROVED')return ['received'=>true,'processed'=>false];
  $external=(string)($d['correlationID']??$d['externalId']??'');$gatewayId=(string)($d['identifier']??$d['id']??'');
  if($external===''||$gatewayId===''||strlen($gatewayId)>190)throw new HttpError(422,'Referência de pagamento inválida.');
  try{$amount=Money::cents($d['amount']??'');}catch(\InvalidArgumentException){throw new HttpError(422,'Valor inválido.');}
  $pdo=db();$pdo->beginTransaction();
  try{
   $o=query('SELECT * FROM orders WHERE external_id=? FOR UPDATE',[$external])->fetch();
   if(!$o)throw new HttpError(404,'Pedido não encontrado.');
   if((int)$o['total_cents']!==$amount||($o['gateway_id']!==null&&!hash_equals($o['gateway_id'],$gatewayId)))throw new HttpError(409,'O pagamento não corresponde ao pedido.');
   query('INSERT IGNORE INTO webhook_events (event_hash,order_id) VALUES (?,?)',[hash('sha256',$body.'|'.$headers['timestamp'].'|'.$headers['signature']),$o['id']]);
   if($o['state']!=='paid'){
    $today=new \DateTimeImmutable('today',new \DateTimeZone('America/Sao_Paulo'));
    $min=self::businessDate($today,(int)$o['min_days']);$max=self::businessDate($today,(int)$o['max_days']);
    query("UPDATE orders SET state='paid',gateway_id=?,paid_at=UTC_TIMESTAMP(),eta_min=?,eta_max=?,inventory_attention=IF(stock_released_at IS NULL,0,1) WHERE id=?",[$gatewayId,$min,$max,$o['id']]);
   }
   query("INSERT IGNORE INTO email_outbox (order_id,recipient) VALUES (?,?)",[$o['id'],$o['customer_email']]);
   $pdo->commit();
  }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  return ['received'=>true,'processed'=>true];
 }
 public static function businessDate(\DateTimeImmutable $start,int $days):string {
  while($days>0){$start=$start->modify('+1 day');if((int)$start->format('N')<=5)$days--;}
  return $start->format('Y-m-d');
 }
}
