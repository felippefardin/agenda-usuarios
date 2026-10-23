<?php
require __DIR__.'/core.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
sessionStart();
try {
 $a=$_GET['action']??'';$post=$_SERVER['REQUEST_METHOD']==='POST';
 if($post){if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Atualize a página e tente novamente.',403);$p=json_decode(file_get_contents('php://input'),true);if(!is_array($p))fail('Dados inválidos.');}
 elseif(!in_array($a,['session','events','note','notes','overdue_events','cities','holidays','status'],true))fail('Método não permitido.',405);
 if($a==='session'){ $u=null;if(isset($_SESSION['uid']))$u=publicUser(user());result(['csrf'=>$_SESSION['csrf'],'user'=>$u,'network'=>$config['base_url']]); }
 if($a==='register'){
 limit('register:'.($_SERVER['REMOTE_ADDR']??''),15);
 $name=clean($p['name']??'');$username=clean($p['username']??'',60);$email=trim($p['email']??'');$pw=$p['password']??'';passwordCheck($pw);
 if(!$name || !preg_match('/^[a-zA-Z0-9._-]{3,60}$/',$username) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>200)fail('Informe nome, usuário válido (3 a 60 letras, números, ponto, hífen ou sublinhado) e e-mail válido.');
 if($pw!==($p['confirm']??''))fail('As senhas não coincidem.');
 try{query('INSERT INTO users(name,username,email,cpf,registration,password) VALUES(?,?,?,?,?,?)',[$name,$username,$email,clean($p['cpf']??'',20),clean($p['registration']??'',50),password_hash($pw,PASSWORD_DEFAULT)]);}catch(PDOException $e){if($e->getCode()==='23000')fail('Usuário ou e-mail já cadastrado.');throw $e;}
 result(['message'=>'Conta criada. Entre com seu usuário e senha.']);
 }
 if($a==='login'){
 limit('login:'.($_SERVER['REMOTE_ADDR']??''),30);limit('account:'.strtolower(trim($p['login']??'')),15);
 $login=clean($p['login']??'');$rows=query('SELECT * FROM users WHERE username=? COLLATE NOCASE OR email=? COLLATE NOCASE OR (cpf<>"" AND cpf=?) OR (registration<>"" AND registration=?)',[$login,$login,$login,$login])->fetchAll();
 $match=null;foreach($rows as $r)if(password_verify($p['password']??'',$r['password'])){if($match)fail('Identificação ambígua. Use seu nome de usuário ou e-mail.');$match=$r;}
 if(!$match)fail('Usuário ou senha inválidos.',401);
 session_regenerate_id(true);$_SESSION['uid']=$match['id'];$_SESSION['version']=$match['version'];result(['user'=>publicUser($match)]);
 }
 if($a==='logout'){$_SESSION=[];session_destroy();result();}
 if($a==='forgot'){
 limit('forgot:'.($_SERVER['REMOTE_ADDR']??''),5);
 $email=trim($p['email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Informe um e-mail válido.');
 $r=query('SELECT * FROM users WHERE email=? COLLATE NOCASE',[$email])->fetch();
 if($r){$token=bin2hex(random_bytes(32));query('DELETE FROM resets WHERE user_id=? OR expires<?',[$r['id'],time()]);query('INSERT INTO resets VALUES(?,?,?)',[hash('sha256',$token),$r['id'],time()+1800]);
 require __DIR__.'/smtp.php';try{sendReset($r['email'],$config['base_url'].'/?reset='.$token);}catch(Throwable $e){error_log('Agenda: falha ao enviar recuperação SMTP.');query('DELETE FROM resets WHERE token=?',[hash('sha256',$token)]);}}
 result(['message'=>'Se o e-mail estiver cadastrado, enviaremos um link. Caso não receba, verifique o spam ou a configuração SMTP com o responsável.']);
 }
 if($a==='reset'){
 limit('reset:'.($_SERVER['REMOTE_ADDR']??''),15);$pw=$p['password']??'';passwordCheck($pw);if($pw!==($p['confirm']??''))fail('As senhas não coincidem.');
 db()->beginTransaction();$r=query('SELECT * FROM resets WHERE token=? AND expires>?',[hash('sha256',$p['token']??''),time()])->fetch();if(!$r){db()->rollBack();fail('Link inválido ou expirado. Solicite um novo link.');}
 query('UPDATE users SET password=?,version=version+1 WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$r['user_id']]);query('DELETE FROM resets WHERE user_id=?',[$r['user_id']]);db()->commit();result(['message'=>'Senha atualizada. Entre novamente.']);
 }
 $u=user();
 if($a==='profile'){
 $email=trim($p['email']??'');$name=clean($p['name']??'');if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>200)fail('Informe nome e e-mail válido.');
 if(strtolower($email)!==strtolower($u['email']) && !password_verify($p['current_password']??'',$u['password']))fail('Para alterar o e-mail, informe sua senha atual.');
 $avatar=$p['avatar']??$u['avatar'];if($avatar!==''&&!preg_match('/^avatar-(?:[1-9]|1[0-2])$/D',$avatar))fail('Avatar inválido.');
 $cities=$p['cities']??[];if(!is_array($cities) || count($cities)>30)fail('Selecione até 30 municípios.');$valid=[];
 foreach($cities as $c){$id=(string)($c['id']??'');if(!preg_match('/^\d{7}$/',$id))fail('Município inválido.');$url=trim($c['ics_url']??'');if($url!=='' && !preg_match('~^https://calendar\.google\.com/calendar/ical/[a-zA-Z0-9@%._+\-]+/public/basic\.ics$~D',$url))fail('Use o endereço público ICS do Google Agenda: https://calendar.google.com/calendar/ical/.../public/basic.ics');$valid[$id]=['id'=>$id,'name'=>clean($c['name']??'',100),'ics_url'=>$url,'color'=>cityColor($c,count($valid))];}
 try{query('UPDATE users SET name=?,email=?,cpf=?,registration=?,phone=?,department=?,cities=?,avatar=? WHERE id=?',[$name,$email,clean($p['cpf']??'',20),clean($p['registration']??'',50),clean($p['phone']??'',30),clean($p['department']??'',120),json_encode(array_values($valid)), $avatar, $u['id']]);}catch(PDOException $e){if($e->getCode()==='23000')fail('E-mail já cadastrado.');throw $e;}
 result(['user'=>publicUser(user())]);
 }
 if($a==='password'){
 if(!password_verify($p['current_password']??'',$u['password']))fail('Senha atual incorreta.');passwordCheck($p['password']??'');if($p['password']!==($p['confirm']??''))fail('As senhas não coincidem.');query('UPDATE users SET password=?,version=version+1 WHERE id=?',[password_hash($p['password'],PASSWORD_DEFAULT),$u['id']]);$_SESSION['version']++;session_regenerate_id(true);result();
 }
 if($a==='cities'){
 $uf=strtoupper($_GET['uf']??'');if(!preg_match('/^(AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)$/',$uf))fail('Estado inválido.');
 try{$data=cached('cities-'.$uf,fn()=>remoteJson('https://servicodados.ibge.gov.br/api/v1/localidades/estados/'.$uf.'/municipios?orderBy=nome'),604800);result(array_map(fn($c)=>['id'=>(string)$c['id'],'name'=>$c['nome'].' / '.$uf],$data));}catch(Throwable $e){fail('Não foi possível consultar o IBGE. Tente novamente.',503);}
 }
 if($a==='note'){
 $note=query('SELECT * FROM notes WHERE id=? AND user_id=?',[(int)($_GET['id']??0),$u['id']])->fetch();
 if(!$note)fail('Post-it não encontrado.',404);result(['note'=>$note]);
 }
 if($a==='move_event'){
 $event=query('SELECT * FROM events WHERE id=? AND user_id=?',[(int)($p['id']??0),$u['id']])->fetch();
 if(!$event)fail('Compromisso não encontrado.',404);
 $from=$p['from']??'';$to=$p['to']??'';
 if(!is_string($from)||!is_string($to)||!validDate($from.'T00:00')||!validDate($to.'T00:00'))fail('Data de destino inválida.');
 if($from<substr($event['start'],0,10)||$from>substr($event['end'],0,10))fail('Atualize a agenda antes de mover este compromisso.',409);
 $days=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
 $start=(new DateTimeImmutable($event['start']))->modify($days.' days')->format('Y-m-d\TH:i');
 $end=(new DateTimeImmutable($event['end']))->modify($days.' days')->format('Y-m-d\TH:i');
 if(substr($start,0,4)<'1900'||substr($end,0,4)>'2199')fail('Escolha uma data entre 1900 e 2199.');
 $updated=query('UPDATE events SET start=?,end=? WHERE id=? AND user_id=? AND start=? AND end=?',[$start,$end,$event['id'],$u['id'],$p['expected_start']??'',$p['expected_end']??'']);
 if($updated->rowCount()!==1)fail('O compromisso foi alterado em outra janela. Atualize a agenda e tente novamente.',409);
 result(['event'=>query('SELECT * FROM events WHERE id=? AND user_id=?',[$event['id'],$u['id']])->fetch()]);
 }
 if($a==='notes'){result(query('SELECT * FROM notes WHERE user_id=? ORDER BY id DESC',[$u['id']])->fetchAll());}
 if($a==='save_note'){
 $title=clean($p['title']??'');$body=clean($p['body']??'',4000);$color=$p['color']??'yellow';$id=(int)($p['id']??0);
 if(!$title||!in_array($color,['yellow','pink','blue','green'],true))fail('Preencha o título e escolha uma cor válida.');
 if($id){if(!query('SELECT id FROM notes WHERE id=? AND user_id=?',[$id,$u['id']])->fetch())fail('Post-it não encontrado.',404);query('UPDATE notes SET title=?,body=?,color=? WHERE id=? AND user_id=?',[$title,$body,$color,$id,$u['id']]);}
 else{query('INSERT INTO notes(user_id,title,body,color,created_at) VALUES(?,?,?,?,?)',[$u['id'],$title,$body,$color,date(DATE_ATOM)]);$id=(int)db()->lastInsertId();}
 result(['note'=>query('SELECT * FROM notes WHERE id=? AND user_id=?',[$id,$u['id']])->fetch()]);
 }
 if($a==='delete_note'){
 $id=(int)($p['id']??0);if(!query('SELECT id FROM notes WHERE id=? AND user_id=?',[$id,$u['id']])->fetch())fail('Post-it não encontrado.',404);
 query('DELETE FROM notes WHERE id=? AND user_id=?',[$id,$u['id']]);result();
 }
 if($a==='events'){
 $month=$_GET['month']??date('Y-m');if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))fail('Mês inválido.');$start=$month.'-01T00:00';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d\TH:i');result(query('SELECT * FROM events WHERE user_id=? AND start<? AND end>=? ORDER BY start',[$u['id'],$end,$start])->fetchAll());
 }
 if($a==='overdue_events'){
 result(query('SELECT * FROM events WHERE user_id=? AND done=0 AND end<? ORDER BY end,id',[$u['id'],date('Y-m-d').'T00:00'])->fetchAll());
 }
 if($a==='complete_event'){
 $id=(int)($p['id']??0);
 if(!query('SELECT id FROM events WHERE id=? AND user_id=?',[$id,$u['id']])->fetch())fail('Compromisso não encontrado.',404);
 query('UPDATE events SET done=1 WHERE id=? AND user_id=?',[$id,$u['id']]);
 result(['event'=>query('SELECT * FROM events WHERE id=? AND user_id=?',[$id,$u['id']])->fetch()]);
 }
 if($a==='save_event'){
 $title=clean($p['title']??'');$start=$p['start']??'';$end=$p['end']??'';$priority=$p['priority']??'';
 if(!$title || !validDate($start)||!validDate($end)||$end<$start||!in_array($priority,['low','normal','high','urgent'],true))fail('Preencha título, datas válidas e prioridade. O fim deve ser igual ou posterior ao início.');
 $icon=$p['icon']??'calendar-event';if(!in_array($icon,['calendar-event','briefcase','people','heart-pulse','book','gift','alarm','house','star','check2-circle'],true))fail('Ícone inválido.');
 $args=[$title,clean($p['description']??'',4000),clean($p['location']??'',200),$start,$end,$priority,!empty($p['done'])?1:0,$icon];
 if(!empty($p['id'])){if(!query('SELECT id FROM events WHERE id=? AND user_id=?',[(int)$p['id'],$u['id']])->fetch())fail('Compromisso não encontrado.',404);query('UPDATE events SET title=?,description=?,location=?,start=?,end=?,priority=?,done=?,icon=? WHERE id=? AND user_id=?',[...$args,(int)$p['id'],$u['id']]);}
 else query('INSERT INTO events(title,description,location,start,end,priority,done,icon,user_id) VALUES(?,?,?,?,?,?,?,?,?)',[...$args,$u['id']]);
 $eventId=!empty($p['id'])?(int)$p['id']:(int)db()->lastInsertId();
 $saved=query('SELECT * FROM events WHERE id=? AND user_id=?',[$eventId,$u['id']])->fetch();
 if(!$saved)fail('Não foi possível confirmar o compromisso salvo.',500);
 result(['event'=>$saved]);
 }
 if($a==='delete_event'){if(!query('SELECT id FROM events WHERE id=? AND user_id=?',[(int)($p['id']??0),$u['id']])->fetch())fail('Compromisso não encontrado.',404);query('DELETE FROM events WHERE id=? AND user_id=?',[(int)($p['id']??0),$u['id']]);result();}
 if($a==='holidays'){
 $year=(int)($_GET['year']??date('Y'));if($year<1900 || $year>2199)fail('Ano fora do intervalo 1900–2199.');require __DIR__.'/holidays.php';result(holidays($u,$year));
 }
 if($a==='import_ics'){
 $city=(string)($p['city']??'');if(!in_array($city,array_column(json_decode($u['cities'],true),'id'),true))fail('Salve o município no perfil antes de importar.');$text=$p['ics']??'';if(!is_string($text)||strlen($text)>2000000)fail('Use arquivo de até 2 MB.');require __DIR__.'/holidays.php';
 $format=$p['format']??'ics';if(!in_array($format,['ics','csv'],true))fail('Selecione um arquivo CSV ou ICS.');
 try{$items=$format==='csv'?parseHolidayCsv($text,(int)($p['year']??0)):parseIcs($text);}catch(RuntimeException $e){fail($e->getMessage(),400);}
 $added=0;
 db()->beginTransaction();foreach($items as $h){$q=query('INSERT OR IGNORE INTO local_holidays(user_id,city,date,name,type) VALUES(?,?,?,?,?)',[$u['id'],$city,$h['date'],$h['name'],$h['type']??'calendario']);$added+=$q->rowCount();}db()->commit();result(['message'=>$added.' datas adicionadas; '.(count($items)-$added).' já existentes. As datas ficam visíveis somente na sua agenda.','added'=>$added,'processed'=>count($items)]);
 }
 if($a==='clear_ics'){query('DELETE FROM local_holidays WHERE user_id=? AND city=?',[$u['id'],(string)($p['city']??'')]);result();}
 if($a==='status'){ $f=__DIR__.'/private/backup-status.json';result(is_file($f)?json_decode(file_get_contents($f),true):['ok'=>false,'message'=>'Backup ainda não executado. Instale a tarefa diária conforme o manual.']); }
 fail('Operação não encontrada.',404);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Agenda: '.$e->getMessage());fail('Não foi possível concluir. Verifique a configuração ou tente novamente.',500);}
