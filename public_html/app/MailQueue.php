<?php
declare(strict_types=1);
namespace PF;
final class MailQueue {
 public static function message(array $o,array $items,array $store):array {
  $a=json_decode($o['shipping_json'],true);
  $text="Pagamento confirmado — ".$o['external_id']."\n\n";
  $html='<h1>Pagamento confirmado</h1><p>Seu pedido <strong>'.h($o['external_id']).'</strong> foi confirmado.</p><ul>';
  foreach($items as$i){$line=$i['quantity'].' × '.$i['title'].' — tamanho '.$i['size'].', cor '.$i['color'].' — '.Money::brl((int)$i['unit_cents']*(int)$i['quantity']);$text.=$line."\n";$html.='<li>'.h($line).'</li>';}
  $html.='</ul>';
  $rows=['Produtos'=>Money::brl((int)$o['subtotal_cents']),'Desconto'=>Money::brl((int)$o['discount_cents']),'Frete'=>Money::brl((int)$o['shipping_cents']),'Total pago'=>Money::brl((int)$o['total_cents']),'Prazo estimado'=>$o['min_days'].' a '.$o['max_days'].' dias úteis após a confirmação'];
  foreach($rows as$label=>$value){$text.=$label.': '.$value."\n";$html.='<p><strong>'.h($label).':</strong> '.h($value).'</p>';}
  $address=$a['recipient'].' — '.$a['street'].', '.$a['number'].($a['complement']?' — '.$a['complement']:'').' — '.$a['district'].' — '.$a['city'].'/'.$a['state'].' — CEP '.$a['cep'];
  $text.="\nEntrega: ".$address."\n";
  $url=rtrim(config()['site_url'],'/').'/?pedido='.$o['access_token'];
  $text.="\nAcompanhe: ".$url."\n";
  $html.='<h2>Entrega</h2><p>'.h($address).'</p><p><a href="'.h($url).'">Acompanhar meu pedido</a></p><p>'.h($store['name']??'PequenosFrutos').'</p>';
  return ['subject'=>'Pagamento confirmado — '.$o['external_id'],'html'=>$html,'text'=>$text];
 }
 public static function run(int $limit=10):array {
  $cfg=config()['smtp']??[];
  if(empty($cfg['enabled']))return ['sent'=>0,'pending'=>'SMTP não configurado'];
  $autoload=dirname(__DIR__).'/vendor/autoload.php';
  if(!is_file($autoload))throw new \RuntimeException('Instale as dependências de e-mail antes de executar a fila.');
  require_once $autoload;$sent=0;$failed=0;
  for($n=0;$n<$limit;$n++){
   $pdo=db();$pdo->beginTransaction();
   $job=query("SELECT * FROM email_outbox WHERE state<>'sent' AND attempts<8 AND next_attempt_at<=UTC_TIMESTAMP() AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
   if(!$job){$pdo->commit();break;}
   query("UPDATE email_outbox SET state='sending',attempts=attempts+1,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=?",[$job['id']]);$pdo->commit();
   try{
    $o=query("SELECT * FROM orders WHERE id=? AND state='paid'",[$job['order_id']])->fetch();
    if(!$o)throw new \RuntimeException('Pedido não confirmado.');
    $items=query('SELECT * FROM order_items WHERE order_id=?',[$o['id']])->fetchAll();$msg=self::message($o,$items,setting('store',[]));
    $m=new \PHPMailer\PHPMailer\PHPMailer(true);$m->isSMTP();$m->Host=(string)$cfg['host'];$m->Port=(int)$cfg['port'];
    $m->SMTPAuth=true;$m->Username=(string)$cfg['username'];$m->Password=(string)$cfg['password'];
    $security=$cfg['security']??'tls';
    if(!in_array($security,['tls','ssl'],true))throw new \RuntimeException('Use SMTP com TLS.');
    $m->SMTPSecure=$security==='ssl'?\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS:\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $m->SMTPDebug=0;$m->Timeout=30;$m->CharSet='UTF-8';$m->setFrom((string)$cfg['from_email'],(string)($cfg['from_name']??'PequenosFrutos'));
    $m->addAddress($job['recipient']);$m->isHTML(true);$m->Subject=$msg['subject'];$m->Body=$msg['html'];$m->AltBody=$msg['text'];
    $domain=parse_url(config()['site_url'],PHP_URL_HOST);$m->MessageID='<pf-order-'.$o['id'].'@'.$domain.'>';
    $m->send();
    query("UPDATE email_outbox SET state='sent',sent_at=UTC_TIMESTAMP(),locked_until=NULL,last_error=NULL WHERE id=?",[$job['id']]);$sent++;
   }catch(\Throwable){
    // Não persistir mensagens SMTP que possam carregar credenciais ou dados pessoais.
    $delay=min(3600,60*(2**min(6,(int)$job['attempts'])));
    query("UPDATE email_outbox SET state='retry',locked_until=NULL,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error='Falha no envio. Confira o SMTP privado.' WHERE id=?",[$delay,$job['id']]);$failed++;
   }
  }
  query('DELETE FROM rate_limits WHERE expires_at<UTC_TIMESTAMP()');
  return ['sent'=>$sent,'failed'=>$failed];
 }
}
