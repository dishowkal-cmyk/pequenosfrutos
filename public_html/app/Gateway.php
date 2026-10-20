<?php
declare(strict_types=1);
namespace PF;
final class Gateway {
 private \Closure $transport;
 public function __construct(private array $cfg,?callable $transport=null){$this->transport=$transport?\Closure::fromCallable($transport):\Closure::fromCallable([self::class,'request']);}
 public static function signature(string $timestamp,string $nonce,string $body,string $secret):string{return hash_hmac('sha256',$timestamp.'.'.$nonce.'.'.$body,$secret);}
 public static function webhookSignature(string $timestamp,string $rawBody,string $secret):string{return hash_hmac('sha256',$timestamp.'.'.$rawBody,$secret);}
 public static function verifyWebhook(string $timestamp,string $signature,string $body,string $secret,?int $now=null):bool {
  if($secret===''||!ctype_digit($timestamp)||abs(($now??time())-(int)$timestamp)>300)return false;
  $signature=preg_replace('/^sha256=/i','',$signature);
  return preg_match('/^[a-f0-9]{64}$/iD',$signature)===1&&hash_equals(self::webhookSignature($timestamp,$body,$secret),strtolower($signature));
 }
 public static function request(string $url,array $headers,string $body):array {
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HEADER=>false]);
  $reply=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_errno($ch);curl_close($ch);
  return ['http'=>$code,'body'=>is_string($reply)?$reply:'','network_error'=>$err!==0];
 }
 private static function paymentEvidence(array $d,int $depth=0):bool {
  if($depth>5)return true;
  foreach($d as $key=>$v){
   if(in_array((string)$key,['id','identifier','transactionId','paymentId','pixCopiaECola','pixCopyPaste','qrCodeBase64'],true)&&$v!==null&&$v!=='')return true;
   if(is_array($v)&&self::paymentEvidence($v,$depth+1))return true;
  }return false;
 }
 public static function explicitUnavailable(int $http,array $d):bool {
  if($http!==422||self::paymentEvidence($d))return false;
  $texts=[];array_walk_recursive($d,function($v)use(&$texts){if(is_string($v))$texts[]=$v;});
  $message=mb_strtolower(implode(' ',$texts));
  return str_contains($message,'depósito indisponível nesta adquirente no momento')||str_contains($message,'deposito indisponivel nesta adquirente no momento');
 }
 public function create(array $order,string $cpf,callable $journal):array {
  if(empty($this->cfg['enabled'])||empty($this->cfg['api_key'])||empty($this->cfg['secret']))throw new HttpError(503,'O pagamento Pix está temporariamente indisponível.');
  $total=(int)$order['total_cents'];Money::pixRange($total);
  $compatible=array_values(array_filter($this->cfg['acquirers']??[],fn($a)=>$total>=(int)$a['min']&&$total<=(int)$a['max']));
  if(!$compatible)throw new HttpError(422,'Não há adquirente compatível com esse total.');
  foreach($compatible as $acquirer){
   $payload=['amount'=>(float)Money::decimal($total),'callbackUrl'=>$order['callback_url'],'externalId'=>$order['external_id'],'acquirer_code'=>$acquirer['code'],'payerName'=>$order['payer_name'],'payerDocument'=>$cpf];
   $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
   $timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
   $headers=['Content-Type: application/json','Authorization: Bearer '.$this->cfg['api_key'],'X-C7-Timestamp: '.$timestamp,'X-C7-Nonce: '.$nonce,'X-C7-Signature: '.self::signature($timestamp,$nonce,$body,$this->cfg['secret'])];
   $journal('started',['acquirer'=>(string)$acquirer['code']]);
   try{$r=($this->transport)(rtrim($this->cfg['base_url'],'/').'/payment/create',$headers,$body);}catch(\Throwable){$r=['http'=>0,'body'=>'','network_error'=>true];}
   $http=(int)($r['http']??0);$data=json_decode((string)($r['body']??''),true);
   if(!is_array($data))$data=[];
   $payment=$data;
   foreach(['data','payment']as$key)if(isset($payment[$key])&&is_array($payment[$key]))$payment=$payment[$key];
   $id=(string)($payment['id']??$payment['identifier']??$payment['transactionId']??'');
   $pix=(string)($payment['pixCopiaECola']??$payment['pixCopyPaste']??'');
   $info=['http'=>$http,'gateway_id'=>mb_substr($id,0,190),'pix'=>$pix,'expires_at'=>isset($payment['expiresAt'])?(string)$payment['expiresAt']:null];
   if(!empty($r['network_error'])||$http===0||$http>=500||($http>=300&&$http<400)){
    $journal('unknown',$info);return ['state'=>'unknown']+$info;
   }
   if($http>=200&&$http<300&&$id!==''&&$pix!==''&&PixPayload::valid($pix,$total)){
    if(isset($payment['amount'])){try{$amount=Money::cents($payment['amount']);}catch(\InvalidArgumentException){$amount=-1;}if($amount!==$total){$journal('unknown',$info);return ['state'=>'unknown']+$info;}}
    $journal('created',$info);return ['state'=>'awaiting_payment']+$info;
   }
   if(self::explicitUnavailable($http,$data)){$journal('unavailable',['http'=>$http]);continue;}
   if(self::paymentEvidence($data)||($http>=200&&$http<300)){$journal('unknown',$info);return ['state'=>'unknown']+$info;}
   // Apenas erros explícitos sem evidência de transação liberam a reserva.
   if($http>=400&&$http<500){$journal('rejected',['http'=>$http]);return ['state'=>'rejected','http'=>$http];}
   $journal('unknown',$info);return ['state'=>'unknown']+$info;
  }
  return ['state'=>'rejected','http'=>422];
 }
}
