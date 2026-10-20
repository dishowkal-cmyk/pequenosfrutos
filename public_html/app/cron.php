<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
try{echo json_encode(\PF\MailQueue::run(20),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;}
catch(Throwable){fwrite(STDERR,"Falha na fila. Confira a configuração privada.\n");exit(1);}
