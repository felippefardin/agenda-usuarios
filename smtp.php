<?php
function sendReset(string $to,string $link): void {
 global $config;
 $socket=stream_socket_client('tcp://'.$config['smtp_host'].':'.$config['smtp_port'],$errno,$errstr,15);
 if(!$socket)throw new RuntimeException('SMTP indisponível.');
 stream_set_timeout($socket,15);
 $read=function(array $codes)use($socket){$last='';do{$line=fgets($socket,2048);if($line===false)throw new RuntimeException('Sem resposta SMTP.');$last=$line;}while(isset($line[3]) && $line[3]==='-');if(!in_array((int)substr($last,0,3),$codes,true))throw new RuntimeException('Falha SMTP: '.substr($last,0,3));};
 $cmd=function(string $text,array $codes)use($socket,$read){if(fwrite($socket,$text."\r\n")===false)throw new RuntimeException('Falha SMTP.');$read($codes);};
 try {
 $read([220]);$cmd('EHLO agenda.local',[250]);$cmd('STARTTLS',[220]);
 if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('TLS indisponível.');
 $cmd('EHLO agenda.local',[250]);$cmd('AUTH LOGIN',[334]);$cmd(base64_encode($config['smtp_user']),[334]);$cmd(base64_encode(str_replace(' ','',$config['smtp_password'])),[235]);
 $cmd('MAIL FROM:<'.$config['smtp_user'].'>',[250]);$cmd('RCPT TO:<'.$to.'>',[250,251]);$cmd('DATA',[354]);
 $body="Para redefinir sua senha, abra este link em até 30 minutos:\r\n".$link."\r\n\r\nSe não solicitou a alteração, ignore esta mensagem.";
 $message='From: Agenda <'.$config['smtp_user'].">\r\nTo: <".$to.">\r\nSubject: Redefinir senha da agenda\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body));
 $cmd($message."\r\n.",[250]);$cmd('QUIT',[221]);
 }finally{fclose($socket);}
}
