<?php
declare(strict_types=1);
namespace PF;
class HttpError extends \RuntimeException {public function __construct(public int $status,string $message){parent::__construct($message);}}
final class Money {
 public static function cents(mixed $value): int {
  if(!is_string($value)&&!is_int($value)&&!is_float($value))throw new \InvalidArgumentException('Valor inválido.');
  $s=(string)$value;
  if(!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$s))throw new \InvalidArgumentException('Valor inválido.');
  [$a,$b]=array_pad(explode('.',$s,2),2,'');return (int)$a*100+(int)str_pad($b,2,'0');
 }
 public static function decimal(int $c):string{return intdiv($c,100).'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);}
 public static function brl(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
 public static function pixRange(int $c):void{if($c<1000||$c>35000)throw new HttpError(422,'O total para pagamento Pix deve ficar entre R$ 10,00 e R$ 350,00.');}
}
final class Identity {
 public static function cpf(string $value):string {
  $v=preg_replace('/\D/','',$value);
  if(strlen($v)!==11||preg_match('/^(\d)\1{10}$/',$v))throw new HttpError(422,'Informe um CPF válido do pagador.');
  for($n=9;$n<11;$n++){ $sum=0;for($i=0;$i<$n;$i++)$sum+=(int)$v[$i]*($n+1-$i);$digit=(10*$sum)%11;if($digit===10)$digit=0;if($digit!==(int)$v[$n])throw new HttpError(422,'Informe um CPF válido do pagador.'); }
  return $v;
 }
 public static function name(string $s):string {
  $s=trim(preg_replace('/\s+/u',' ',$s));
  if(mb_strlen($s)<5||mb_strlen($s)>120||!preg_match('/^\p{L}[\p{L}\p{M}\' .-]+ \p{L}[\p{L}\p{M}\' .-]*$/u',$s))throw new HttpError(422,'Informe nome e sobrenome.');
  return $s;
 }
 public static function text(mixed $s,int $max=150,bool $required=true):string {
  if(!is_scalar($s))throw new HttpError(422,'Campo inválido.');
  $s=trim((string)$s);
  if(($required&&$s==='')||mb_strlen($s)>$max||preg_match('/[\x00-\x1F]/u',$s))throw new HttpError(422,'Confira os campos do endereço.');
  return $s;
 }
}
final class QuoteToken {
 public static function sign(array $value,string $key):string {
  $p=rtrim(strtr(base64_encode(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'=');
  return $p.'.'.hash_hmac('sha256',$p,$key);
 }
 public static function verify(string $token,string $key):array {
  $parts=explode('.',$token);
  if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],$key),$parts[1]))throw new HttpError(409,'Recalcule a entrega e confira o pedido.');
  $d=json_decode(base64_decode(strtr($parts[0],'-_','+/'),true)?:'',true);
  if(!is_array($d)||($d['expires']??0)<time())throw new HttpError(409,'A conferência expirou. Confira novamente o pedido.');
  return $d;
 }
}
final class PixPayload {
 public static function valid(string $s,?int $expectedCents=null):bool {
  if(strlen($s)<20||strlen($s)>2000||!str_starts_with($s,'000201')||!preg_match('/6304[0-9A-Fa-f]{4}$/D',$s))return false;
  $crc=0xffff; $base=substr($s,0,-4);
  for($i=0;$i<strlen($base);$i++){ $crc^=ord($base[$i])<<8;for($j=0;$j<8;$j++)$crc=(($crc&0x8000)?($crc<<1)^0x1021:$crc<<1)&0xffff; }
  if(strtoupper(substr($s,-4))!==sprintf('%04X',$crc))return false;
  $pos=0;$fields=[];
  while($pos<strlen($s)){
   if($pos+4>strlen($s)||!ctype_digit(substr($s,$pos,4)))return false;
   $id=substr($s,$pos,2);$len=(int)substr($s,$pos+2,2);$pos+=4;
   if($pos+$len>strlen($s)||isset($fields[$id]))return false;
   $fields[$id]=substr($s,$pos,$len);$pos+=$len;
  }
  if(($fields['53']??'')!=='986'||($fields['58']??'')!=='BR')return false;
  if($expectedCents!==null&&isset($fields['54'])){try{if(Money::cents($fields['54'])!==$expectedCents)return false;}catch(\InvalidArgumentException){return false;}}
  return true;
 }
}
