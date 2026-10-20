<?php
// Copie este arquivo para a pasta pf-private/config.php, AO LADO de public_html.
// Edite somente a cópia privada. Nunca publique credenciais no GitHub.
return [
 'database'=>['host'=>'localhost','port'=>3306,'name'=>'','user'=>'','password'=>''],
 'site_url'=>'https://SEU-DOMINIO.com.br',
 'app_key'=>'TROQUE-POR-UMA-SENHA-ALEATORIA-COM-PELO-MENOS-32-CARACTERES',
 'install_token'=>'CRIE-OUTRA-SENHA-LONGA-PARA-A-INSTALACAO',
 'https_required'=>true,
 'c7'=>[
   'enabled'=>false,
   'base_url'=>'https://api.carteirado7.com/v2',
   'api_key'=>'',
   'secret'=>'',
   'webhook_secret'=>'',
   'acquirers'=>[
     ['code'=>1,'min'=>1000,'max'=>20000],
     ['code'=>2,'min'=>1000,'max'=>35000],
   ],
 ],
 'smtp'=>['enabled'=>false,'host'=>'','port'=>587,'security'=>'tls','username'=>'','password'=>'','from_email'=>'','from_name'=>'PequenosFrutos'],
];
