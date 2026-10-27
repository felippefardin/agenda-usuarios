<?php
declare(strict_types=1);

function agendaSchema(PDO $db): void {
    // Migração aditiva: não recria tabelas nem altera registros existentes.
    $tables = [
        'events' => ['deleted_at'=>'TEXT', 'category'=>"TEXT NOT NULL DEFAULT 'none'", 'reminder_minutes'=>'INTEGER', 'reminder_seen_at'=>'TEXT', 'series_id'=>'TEXT', 'recurrence'=>"TEXT NOT NULL DEFAULT 'none'", 'repeat_until'=>'TEXT'],
        'notes' => ['deleted_at'=>'TEXT'],
    ];
    foreach ($tables as $table=>$columns) {
        $existing = array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(), 'name');
        foreach ($columns as $column=>$definition) {
            if (!in_array($column,$existing,true)) {
                try {$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}
                catch (PDOException $e) {
                    // Outra requisição pode ter concluído a mesma migração.
                    if (!in_array($column,array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true)) throw $e;
                }
            }
        }
    }
    $db->exec('CREATE INDEX IF NOT EXISTS events_active_dates ON events(user_id,deleted_at,start,end); CREATE INDEX IF NOT EXISTS notes_trash ON notes(user_id,deleted_at);');
}

function agendaCategoryNames(): array {
    return ['none'=>'Sem categoria','work'=>'Trabalho','personal'=>'Pessoal','health'=>'Saúde','study'=>'Estudo','family'=>'Família','other'=>'Outros'];
}

function agendaEvent(int $id, int $owner): array {
    $event=query('SELECT * FROM events WHERE id=? AND user_id=? AND deleted_at IS NULL',[$id,$owner])->fetch();
    if (!$event) fail('Compromisso não encontrado.',404);
    return $event;
}

function agendaOccurrences(string $start,string $end,string $frequency,string $until): array {
    $first=new DateTimeImmutable($start);$last=new DateTimeImmutable($end);
    if ($frequency==='none') return [[$start,$end]];
    if (!in_array($frequency,['weekly','monthly','yearly'],true) || !validDate($until.'T00:00') || $until<substr($start,0,10) || $until>$first->modify('+5 years')->format('Y-m-d')) {
        fail('Escolha uma repetição válida e uma data final em até cinco anos.');
    }
    $rows=[];$duration=$first->diff($last);
    for ($i=0;$i<=1830;$i++) {
        if ($frequency==='weekly') $date=$first->modify('+'.($i*7).' days');
        else {
            // Preserva o dia original: dia 31 e 29/02 não transbordam para outro mês.
            $base=$first->modify('first day of this month')->modify('+'.($frequency==='yearly'?$i*12:$i).' months');
            if ($base->format('Y-m-d')>$until) break;
            $day=(int)$first->format('d');
            if (!checkdate((int)$base->format('m'),$day,(int)$base->format('Y'))) continue;
            $date=$base->setDate((int)$base->format('Y'),(int)$base->format('m'),$day);
        }
        if ($date->format('Y-m-d')>$until) break;
        $finish=$date->add($duration)->format('Y-m-d\TH:i');
        if (substr($finish,0,4)>'2199') fail('O término deve estar entre 1900 e 2199.');
        $rows[]=[$date->format('Y-m-d\TH:i'),$finish];
    }
    return $rows;
}

function agendaConflicts(int $owner,array $slots,int $exclude=0): array {
    if (!$slots) return [];
    $starts=array_column($slots,0);$ends=array_column($slots,1);
    $candidates=query('SELECT id,title,start,end FROM events WHERE user_id=? AND deleted_at IS NULL AND done=0 AND id<>? AND start<? AND end>? ORDER BY start',[$owner,$exclude,max($ends),min($starts)])->fetchAll();
    $conflicts=[];
    foreach ($slots as [$start,$end]) {
        if ($start===$end) continue;
        foreach ($candidates as $e) if ($e['start']<$end && $e['end']>$start && $e['start']!==$e['end']) $conflicts['event-'.$e['id']]=$e;
    }
    for ($i=1;$i<count($slots);$i++) if ($slots[$i][0]<$slots[$i-1][1]) {
        $conflicts['series']=['title'=>'As próprias ocorrências desta série se sobrepõem','start'=>$slots[$i][0],'end'=>$slots[$i-1][1]];break;
    }
    return array_values($conflicts);
}

function agendaCheckConflicts(int $owner,array $slots,array $payload,int $exclude=0): void {
    $conflicts=agendaConflicts($owner,$slots,$exclude);
    if ($conflicts && ($payload['allow_conflicts']??false)!==true) {
        if(db()->inTransaction()) db()->rollBack();
        http_response_code(409);
        result(['error'=>'Existem compromissos no mesmo horário. Deseja salvar mesmo assim?','conflicts'=>array_slice($conflicts,0,10),'conflict_count'=>count($conflicts)]);
    }
}

function agendaIcsText(string $value): string {
    return str_replace(["\\","\r\n","\r","\n",';',','],["\\\\","\\n","\\n","\\n","\\;","\\,"],$value);
}

function agendaIcsFold(string $line): string {
    $parts=[];
    while(strlen($line)>75) {
        $part=mb_strcut($line,0,75,'UTF-8');$parts[]=$part;$line=' '.substr($line,strlen($part));
    }
    $parts[]=$line;return implode("\r\n",$parts);
}

function agendaIcs(array $events): string {
    $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Agenda Usuarios//PT-BR','CALSCALE:GREGORIAN','METHOD:PUBLISH','X-WR-CALNAME:Minha Agenda'];
    $categories=agendaCategoryNames();$stamp=gmdate('Ymd\THis\Z');
    foreach($events as $e) {
        $lines[]='BEGIN:VEVENT';$lines[]='UID:agenda-'.$e['user_id'].'-'.$e['id'].'@agenda-usuarios.local';$lines[]='DTSTAMP:'.$stamp;
        foreach(['DTSTART'=>'start','DTEND'=>'end'] as $key=>$field) $lines[]=$key.':'.(new DateTimeImmutable($e[$field],new DateTimeZone('America/Sao_Paulo')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lines[]='SUMMARY:'.agendaIcsText($e['title']);$lines[]='DESCRIPTION:'.agendaIcsText($e['description']??'');$lines[]='LOCATION:'.agendaIcsText($e['location']??'');
        $lines[]='CATEGORIES:'.agendaIcsText($categories[$e['category']]??'Sem categoria');
        $lines[]='STATUS:CONFIRMED';
        if($e['reminder_minutes']!==null && !$e['done']) {
            $lines[]='BEGIN:VALARM';$lines[]='TRIGGER:-PT'.(int)$e['reminder_minutes'].'M';$lines[]='ACTION:DISPLAY';$lines[]='DESCRIPTION:'.agendaIcsText($e['title']);$lines[]='END:VALARM';
        }
        // Ocorrências exportadas individualmente preservam edições e exclusões isoladas.
        $lines[]='END:VEVENT';
    }
    $lines[]='END:VCALENDAR';return implode("\r\n",array_map('agendaIcsFold',$lines))."\r\n";
}

function agendaRoutes(string $action,array $u,array $p): void {
    $owner=(int)$u['id'];
    if ($action==='events') {
        if(isset($_GET['from'],$_GET['to'])) {
            $from=$_GET['from'];$to=$_GET['to'];
            if(!is_string($from)||!is_string($to)||!validDate($from.'T00:00')||!validDate($to.'T00:00')||$to<=$from||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>62) fail('Intervalo inválido (máximo de 62 dias).');
            $start=$from.'T00:00';$end=$to.'T00:00';
        } else {
            $month=$_GET['month']??date('Y-m');
            if(!is_string($month)||!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$month)) fail('Mês inválido.');
            $start=$month.'-01T00:00';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d\TH:i');
        }
        result(query('SELECT * FROM events WHERE user_id=? AND deleted_at IS NULL AND start<? AND end>=? ORDER BY start,id',[$owner,$end,$start])->fetchAll());
    }
    if ($action==='save_event') {
        $id=(int)($p['id']??0);$existing=$id?agendaEvent($id,$owner):null;
        $title=clean($p['title']??'');$start=$p['start']??'';$end=$p['end']??'';$priority=$p['priority']??'normal';
        if(!$title||!is_string($start)||!is_string($end)||!validDate($start)||!validDate($end)||$end<$start||substr($start,0,4)<'1900'||substr($end,0,4)>'2199'||!in_array($priority,['low','normal','high','urgent'],true)) fail('Preencha título, datas entre 1900 e 2199 e prioridade. O fim deve ser igual ou posterior ao início.');
        $icon=$p['icon']??'calendar-event';if(!in_array($icon,['calendar-event','briefcase','people','heart-pulse','book','gift','alarm','house','star','check2-circle'],true)) fail('Ícone inválido.');
        $category=$p['category']??'none';if(!is_string($category)||!array_key_exists($category,agendaCategoryNames())) fail('Categoria inválida.');
        $reminder=$p['reminder_minutes']??'';
        if($reminder===''||$reminder===null) $reminder=null;
        elseif(filter_var($reminder,FILTER_VALIDATE_INT)===false||(int)$reminder<0||(int)$reminder>10080) fail('O lembrete deve ter entre 0 e 10080 minutos de antecedência.');
        else $reminder=(int)$reminder;
        $frequency=$id?'none':($p['recurrence']??'none');$until=$p['repeat_until']??'';
        if(!is_string($frequency)||!is_string($until)) fail('Repetição inválida.');
        $slots=agendaOccurrences($start,$end,$frequency,$until);$done=!empty($p['done'])?1:0;
        $description=clean($p['description']??'',4000);$location=clean($p['location']??'',200);
        db()->beginTransaction();
        if(!$done) agendaCheckConflicts($owner,$slots,$p,$id);
        if($id) {
            // Cada ocorrência tem seu próprio ID: a edição nunca altera as demais.
            $seen=($existing['start']===$start && $existing['end']===$end && $existing['reminder_minutes']===$reminder && (int)$existing['done']===$done)?$existing['reminder_seen_at']:null;
            query('UPDATE events SET title=?,description=?,location=?,start=?,end=?,priority=?,done=?,icon=?,category=?,reminder_minutes=?,reminder_seen_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL',[$title,$description,$location,$start,$end,$priority,$done,$icon,$category,$reminder,$seen,$id,$owner]);
        } else {
            $series=$frequency==='none'?null:bin2hex(random_bytes(16));
            foreach($slots as [$occStart,$occEnd]) {
                query('INSERT INTO events(user_id,title,description,location,start,end,priority,done,icon,category,reminder_minutes,series_id,recurrence,repeat_until) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$owner,$title,$description,$location,$occStart,$occEnd,$priority,$done,$icon,$category,$reminder,$series,$frequency,$frequency==='none'?null:$until]);
                if(!$id) $id=(int)db()->lastInsertId();
            }
        }
        $saved=agendaEvent($id,$owner);db()->commit();result(['event'=>$saved,'occurrences'=>count($slots)]);
    }
    if($action==='move_event') {
        $e=agendaEvent((int)($p['id']??0),$owner);$from=$p['from']??'';$to=$p['to']??'';
        if(!is_string($from)||!is_string($to)||!validDate($from.'T00:00')||!validDate($to.'T00:00')) fail('Data de destino inválida.');
        if($from<substr($e['start'],0,10)||$from>substr($e['end'],0,10)) fail('Atualize a agenda antes de mover este compromisso.',409);
        $days=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
        $start=(new DateTimeImmutable($e['start']))->modify($days.' days')->format('Y-m-d\TH:i');$end=(new DateTimeImmutable($e['end']))->modify($days.' days')->format('Y-m-d\TH:i');
        if(substr($start,0,4)<'1900'||substr($end,0,4)>'2199') fail('Escolha uma data entre 1900 e 2199.');
        db()->beginTransaction();if(!$e['done']) agendaCheckConflicts($owner,[[$start,$end]],$p,(int)$e['id']);
        $updated=query('UPDATE events SET start=?,end=?,reminder_seen_at=NULL WHERE id=? AND user_id=? AND deleted_at IS NULL AND start=? AND end=?',[$start,$end,$e['id'],$owner,$p['expected_start']??'',$p['expected_end']??'']);
        if($updated->rowCount()!==1){db()->rollBack();fail('O compromisso foi alterado em outra janela. Atualize a agenda.',409);}
        $saved=agendaEvent((int)$e['id'],$owner);db()->commit();result(['event'=>$saved]);
    }
    if($action==='trash') {
        $events=query('SELECT id,title,start,deleted_at FROM events WHERE user_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC,id DESC',[$owner])->fetchAll();
        $notes=query('SELECT id,title,deleted_at FROM notes WHERE user_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC,id DESC',[$owner])->fetchAll();result(['events'=>$events,'notes'=>$notes]);
    }
    if($action==='restore_item') {
        $kind=$p['kind']??'';if(!in_array($kind,['event','note'],true)) fail('Tipo inválido.');$table=$kind==='event'?'events':'notes';$id=(int)($p['id']??0);
        db()->beginTransaction();$item=query('SELECT * FROM '.$table.' WHERE id=? AND user_id=? AND deleted_at IS NOT NULL',[$id,$owner])->fetch();
        if(!$item){db()->rollBack();fail('Registro não encontrado na lixeira.',404);}
        if($kind==='event'&&!$item['done']) agendaCheckConflicts($owner,[[$item['start'],$item['end']]],$p,$id);
        query('UPDATE '.$table.' SET deleted_at=NULL'.($kind==='event'?',reminder_seen_at=NULL':'').' WHERE id=? AND user_id=?',[$id,$owner]);db()->commit();result(['restored'=>true]);
    }
    if($action==='reminders') {
        // Lembretes dentro da agenda: disponíveis enquanto a página estiver aberta.
        $now=date('Y-m-d\TH:i');$latest=(new DateTimeImmutable())->modify('+7 days')->format('Y-m-d\TH:i');
        $items=query('SELECT id,title,start,reminder_minutes FROM events WHERE user_id=? AND deleted_at IS NULL AND done=0 AND reminder_minutes IS NOT NULL AND reminder_seen_at IS NULL AND start>=? AND start<=? ORDER BY start',[$owner,$now,$latest])->fetchAll();
        result(array_values(array_filter($items,fn($e)=>(new DateTimeImmutable($e['start']))->modify('-'.(int)$e['reminder_minutes'].' minutes')->getTimestamp()<=time())));
    }
    if($action==='dismiss_reminder') {
        $event=agendaEvent((int)($p['id']??0),$owner);
        query('UPDATE events SET reminder_seen_at=? WHERE id=? AND user_id=? AND start=? AND reminder_minutes=?',[date(DATE_ATOM),$event['id'],$owner,$p['start']??'',$p['reminder_minutes']??-1]);result();
    }
    if($action==='export_calendar') {
        // POST + CSRF: o navegador recebe o arquivo sem expor tokens na URL.
        $items=query('SELECT * FROM events WHERE user_id=? AND deleted_at IS NULL ORDER BY start,id',[$owner])->fetchAll();
        header('Content-Type: text/calendar; charset=utf-8');header('Content-Disposition: attachment; filename="minha-agenda.ics"');echo agendaIcs($items);exit;
    }
}
