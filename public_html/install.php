<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
\PF\secureHeaders();
$error='';$done=false;
try {
 $c=\PF\config();
 if(($c['https_required']??true)&&($_SERVER['HTTPS']??'')!=='on')throw new RuntimeException('Abra o instalador por HTTPS.');
 \PF\startSession();
 $pdo=\PF\db();
 try{$installed=(int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()>0;}catch(PDOException){$installed=false;}
 if($installed){http_response_code(404);exit('A instalação já foi concluída. Acesse /admin.');}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Atualize a página.');
  $token=(string)($c['install_token']??'');
  if(strlen($token)<24||str_contains($token,'CRIE-')||!hash_equals($token,(string)($_POST['token']??'')))throw new RuntimeException('Token de instalação incorreto. Confira o arquivo privado.');
  $email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($password)<14||strlen($password)>200)throw new RuntimeException('Use um e-mail válido e senha com pelo menos 14 caracteres.');
  $sql=file_get_contents(__DIR__.'/app/schema.sql');
  foreach(explode(';',$sql)as$statement)if(trim($statement)!=='')$pdo->exec($statement);
  $seed=json_decode(file_get_contents(__DIR__.'/app/catalog.json'),true,32,JSON_THROW_ON_ERROR);
  $pdo->beginTransaction();
  // O lock evita dois administradores iniciais em pedidos de instalação concorrentes.
  $pdo->query("SELECT setting_key FROM settings WHERE setting_key='checkout_enabled' FOR UPDATE");
  if((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()>0)throw new RuntimeException('A instalação já foi concluída.');
  $s=$pdo->prepare('INSERT IGNORE INTO products (id,title,category,description,image,price_cents,sort_order) VALUES (?,?,?,?,?,?,?)');
  $v=$pdo->prepare('INSERT IGNORE INTO variants (product_id,size,color,stock) VALUES (?,?,?,0)');
  foreach($seed as$i=>$p){
   $s->execute([$p['id'],$p['title'],$p['category'],$p['description'],$p['image'],0,$i]);
   foreach($p['sizes']as$size)$v->execute([$p['id'],$size,$p['color']]);
  }
  $s=$pdo->prepare('INSERT INTO admin_users (email,password_hash) VALUES (?,?)');$s->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
  $pdo->commit();$done=true;
 }
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage()==='SETUP_REQUIRED'?'Crie pf-private/config.php ao lado de public_html e preencha a configuração privada antes de instalar.':($e instanceof PDOException?'Confira as credenciais MySQL no arquivo privado.':$e->getMessage());}
?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar PequenosFrutos</title><link rel="stylesheet" href="/assets/store.css"><main class="setup shell"><a class="brand" href="/">PequenosFrutos<span>pequenos detalhes, grandes descobertas</span></a><h1>Instalar a loja</h1>
<?php if($error):?><p role="alert" class="notice"><?=\PF\h($error)?></p><?php endif;?>
<?php if($done):?><p>Instalação concluída. Configure preços, estoque, frete e pagamento no painel.</p><a class="button" href="/admin">Abrir painel</a>
<?php elseif(isset($c)&&$error===''):?><p>O token fica no arquivo privado de configuração. Escolha o acesso do administrador.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=\PF\h($_SESSION['csrf'])?>"><label>Token da instalação<input name="token" type="password" required autocomplete="off"></label><label>E-mail do administrador<input name="email" type="email" required autocomplete="username"></label><label>Senha do administrador<input name="password" type="password" minlength="14" required autocomplete="new-password"></label><button>Concluir instalação</button></form><?php endif;?></main></html>
