<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/core.php';
$status=__DIR__.'/private/backup-status.json';
$lock=fopen(__DIR__.'/private/backup.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Backup já em execução.\n");exit(1);}
$exitCode=0;
try {
 $settings=__DIR__.'/backup-settings.php';
 $target=is_file($settings)?(require $settings)['directory']:$config['backup_dir'];
 $python=(getenv('LOCALAPPDATA')?:'').'/Programs/Python/Python311/python.exe';
 if(!is_file($python))throw new RuntimeException('Python 3.11 não encontrado. Confira a instalação do usuário responsável pelo backup.');
 if(!is_dir($target))throw new RuntimeException('Pasta de backup indisponível. Confira a unidade S: e as permissões.');
 $command=[$python,__DIR__.'/scripts/backup-tools/run-agenda.py','--destination',$target];
 $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Não foi possível iniciar o backup completo.');
 $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 if($code!==0)throw new RuntimeException('Backup completo falhou. Consulte o histórico em %LOCALAPPDATA%\\BackupsCompletos\\work. '.mb_substr(trim($errors),0,500));
 if(!preg_match('/BACKUP_OK=(.+)/',$output,$match))throw new RuntimeException('O utilitário não confirmou a conclusão do backup.');
 $archive=trim($match[1]);
 $data=['ok'=>true,'time'=>date(DATE_ATOM),'message'=>date('d/m/Y H:i').' — ZIP completo, banco e arquivos verificados.','archive'=>basename($archive),'complete'=>true];
 if(file_put_contents($status,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false)throw new RuntimeException('ZIP criado, mas não foi possível atualizar o status da agenda.');
 echo $output;
}catch(Throwable $e){
 file_put_contents($status,json_encode(['ok'=>false,'time'=>date(DATE_ATOM),'message'=>date('d/m/Y H:i').' — '.$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);
 fwrite(STDERR,$e->getMessage()."\n");$exitCode=1;
}finally{flock($lock,LOCK_UN);fclose($lock);}
exit($exitCode);
