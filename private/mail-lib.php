<?php
declare(strict_types=1);
// Incluído exclusivamente por mail.php. Nunca aceita user_id do navegador.
function mailSettings(): array {
    static $settings;
    if ($settings !== null) return $settings;
    $file=__DIR__.'/mail-config.php';
    $settings=is_file($file)?require $file:[];
    return is_array($settings)?$settings:[];
}
function mailKey(): string {
    $encoded=getenv('AGENDA_MAIL_KEY') ?: (mailSettings()['encryption_key']??'');
    $key=base64_decode($encoded,true);
    if (!is_string($key)||strlen($key)!==32) throw new RuntimeException('Configure a chave de proteção do e-mail conforme o manual.');
    return $key;
}
function mailProvider(string $name): array {
    if (!in_array($name,['google','microsoft'],true)) throw new InvalidArgumentException('Provedor inválido.');
    $c=mailSettings()[$name]??[];
    if (empty($c['client_id'])||empty($c['client_secret'])||empty($c['redirect_uri'])) throw new RuntimeException('Este provedor ainda não foi configurado. Consulte o manual de instalação.');
    $uri=parse_url($c['redirect_uri']);
    if (!$uri||!isset($uri['host'])||isset($uri['user'])||isset($uri['pass'])||isset($uri['fragment'])||!in_array($uri['scheme']??'',['http','https'],true)||(($uri['scheme']??'')==='http'&&!in_array(strtolower($uri['host']),['localhost','127.0.0.1','[::1]'],true))) throw new RuntimeException('O retorno do login exige HTTPS, exceto em localhost.');
    mailKey();
    $mode=($c['mode']??'manage')==='readonly'?'readonly':'manage';
    $tenant=$c['tenant']??'common';
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D',$tenant)) throw new RuntimeException('Tenant Microsoft inválido.');
    return $c+[
        'mode'=>$mode,
        'authorize'=>$name==='google'?'https://accounts.google.com/o/oauth2/v2/auth':'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/authorize',
        'token'=>$name==='google'?'https://oauth2.googleapis.com/token':'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',
        'scope'=>$name==='google'?($mode==='readonly'?'https://www.googleapis.com/auth/gmail.readonly':'https://www.googleapis.com/auth/gmail.modify'):'offline_access User.Read '.($mode==='readonly'?'Mail.Read':'Mail.ReadWrite Mail.Send'),
    ];
}
function mailSchema(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS mail_accounts (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        provider TEXT NOT NULL, subject TEXT NOT NULL, address TEXT NOT NULL,
        tokens TEXT NOT NULL, mode TEXT NOT NULL, updated_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,provider), UNIQUE(provider,subject)
    )');
}
function mailB64(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
function mailUnb64(string $value): string {
    $raw=base64_decode(strtr($value,'-_','+/'),true);
    if ($raw===false) throw new InvalidArgumentException('Conteúdo inválido.');
    return $raw;
}
function mailSeal(array $tokens,int $owner,string $provider): string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt(json_encode($tokens,JSON_THROW_ON_ERROR),'aes-256-gcm',mailKey(),OPENSSL_RAW_DATA,$iv,$tag,$owner.':'.$provider);
    if ($cipher===false) throw new RuntimeException('Não foi possível proteger a conexão.');
    return base64_encode($iv.$tag.$cipher);
}
function mailOpen(string $value,int $owner,string $provider): array {
    $raw=base64_decode($value,true);
    if ($raw===false||strlen($raw)<29) throw new RuntimeException('Reconecte sua conta de e-mail.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',mailKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$owner.':'.$provider);
    if ($plain===false) throw new RuntimeException('Não foi possível abrir sua conexão. Reconecte o e-mail.');
    return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
}
function mailAccount(int $owner,string $provider): array {
    $r=query('SELECT * FROM mail_accounts WHERE user_id=? AND provider=?',[$owner,$provider])->fetch();
    if (!$r) throw new InvalidArgumentException('Conecte sua conta de e-mail primeiro.');
    return $r;
}
function mailHttp(string $url,string $method='GET',array $headers=[],?string $body=null): array {
    $host=parse_url($url,PHP_URL_HOST);
    if (parse_url($url,PHP_URL_SCHEME)!=='https'||!in_array($host,['gmail.googleapis.com','graph.microsoft.com','oauth2.googleapis.com','login.microsoftonline.com'],true)) throw new RuntimeException('Destino de integração inválido.');
    $ch=curl_init($url);$raw='';$tooLarge=false;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>35,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$raw,&$tooLarge){if(strlen($raw)+strlen($chunk)>16000000){$tooLarge=true;return 0;}$raw.=$chunk;return strlen($chunk);}]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($tooLarge) throw new RuntimeException('Mensagem ou anexo acima do limite desta integração. Abra no provedor.');
    if ($ok===false) throw new RuntimeException('Não foi possível contatar o provedor. Se estava enviando, confira os Enviados antes de tentar novamente.');
    $data=$raw===''?[]:json_decode($raw,true);
    if (!is_array($data)) throw new RuntimeException('Resposta inválida do provedor.');
    if ($status<200||$status>=300) {
        if ($status===401) throw new RuntimeException('A autorização expirou. Reconecte sua conta.');
        if ($status===403) throw new RuntimeException('O provedor recusou a permissão. Verifique o consentimento ou fale com o administrador.');
        if ($status===404) throw new InvalidArgumentException('Mensagem não encontrada. Atualize a lista.');
        if ($status===429) throw new RuntimeException('Limite temporário do provedor. Aguarde antes de atualizar.');
        if (($data['error']??'')==='invalid_grant') throw new RuntimeException('A autorização expirou ou foi revogada. Reconecte sua conta.');
        throw new RuntimeException('O provedor não concluiu a operação (HTTP '.$status.'). Se enviou, confira os Enviados antes de repetir.');
    }
    return $data;
}
function mailTokenRequest(string $provider,array $params): array {
    $c=mailProvider($provider);
    return mailHttp($c['token'],'POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query($params+['client_id'=>$c['client_id'],'client_secret'=>$c['client_secret']],'','&',PHP_QUERY_RFC3986));
}
function mailAuthStart(int $owner,string $provider): string {
    $c=mailProvider($provider);$state=mailB64(random_bytes(32));$verifier=mailB64(random_bytes(48));
    $_SESSION['mail_oauth']=['owner'=>$owner,'provider'=>$provider,'state'=>$state,'verifier'=>$verifier,'expires'=>time()+600];
    $params=['client_id'=>$c['client_id'],'redirect_uri'=>$c['redirect_uri'],'response_type'=>'code','scope'=>$c['scope'],'state'=>$state,'code_challenge'=>mailB64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256'];
    if ($provider==='google') $params+=['access_type'=>'offline','prompt'=>'consent select_account'];
    else $params+=['response_mode'=>'query','prompt'=>'select_account'];
    return $c['authorize'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
function mailAuthPending(int $owner,string $provider,string $state): array {
    $pending=$_SESSION['mail_oauth']??[];
    if (($pending['owner']??0)!==$owner||($pending['provider']??'')!==$provider||($pending['expires']??0)<time()||!is_string($pending['state']??null)||$state===''||!hash_equals($pending['state'],$state)) throw new InvalidArgumentException('Este login expirou ou pertence a outra sessão. Volte à agenda e conecte novamente.');
    unset($_SESSION['mail_oauth']);return $pending;
}
function mailAuthFinish(int $owner,string $provider,array $pending,string $code): void {
    $c=mailProvider($provider);
    $tokens=mailTokenRequest($provider,['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$c['redirect_uri'],'code_verifier'=>$pending['verifier']]);
    if (empty($tokens['access_token'])) throw new RuntimeException('O provedor não concedeu a conexão.');
    $granted=strtolower($tokens['scope']??'');
    if($granted!=='') {
        $required=$provider==='google'?[$c['mode']==='readonly'?'gmail.readonly':'gmail.modify']:($c['mode']==='readonly'?['mail.read','user.read']:['mail.readwrite','mail.send','user.read']);
        foreach($required as $permission)if(!preg_match('~(?:^|[/ ])'.preg_quote($permission,'~').'(?: |$)~',$granted))throw new RuntimeException('Autorize todas as permissões solicitadas ou configure o modo somente leitura.');
    }
    $headers=['Authorization: Bearer '.$tokens['access_token']];
    $profile=mailHttp($provider==='google'?'https://gmail.googleapis.com/gmail/v1/users/me/profile':'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName','GET',$headers);
    $address=$provider==='google'?($profile['emailAddress']??''):($profile['mail']??$profile['userPrincipalName']??'');
    $subject=$provider==='google'?strtolower($address):($profile['id']??'');
    if (!$subject||!filter_var($address,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Não foi possível identificar a caixa de e-mail.');
    if (query('SELECT 1 FROM mail_accounts WHERE provider=? AND subject=? AND user_id<>?',[$provider,$subject,$owner])->fetch()) throw new InvalidArgumentException('Esta caixa já está conectada a outro usuário da agenda.');
    $old=query('SELECT * FROM mail_accounts WHERE user_id=? AND provider=?',[$owner,$provider])->fetch();
    if (empty($tokens['refresh_token'])&&$old&&$old['subject']===$subject) $tokens['refresh_token']=mailOpen($old['tokens'],$owner,$provider)['refresh_token']??'';
    if (empty($tokens['refresh_token'])) throw new RuntimeException('Não foi concedida conexão duradoura. Remova a autorização da agenda no provedor e conecte novamente.');
    $tokens['expires_at']=time()+(int)($tokens['expires_in']??3600);
    // Guarda somente tokens necessários, nunca id_token ou senha.
    $saved=array_intersect_key($tokens,array_flip(['access_token','refresh_token','expires_at']));
    try { query('INSERT INTO mail_accounts(user_id,provider,subject,address,tokens,mode,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id,provider) DO UPDATE SET subject=excluded.subject,address=excluded.address,tokens=excluded.tokens,mode=excluded.mode,updated_at=excluded.updated_at',[$owner,$provider,$subject,$address,mailSeal($saved,$owner,$provider),$c['mode'],time()]); }
    catch (PDOException $e) {throw new RuntimeException('Não foi possível vincular a caixa. Ela pode estar conectada a outro usuário.');}
}
function mailAccess(array $account): string {
    $owner=(int)$account['user_id'];$provider=$account['provider'];$t=mailOpen($account['tokens'],$owner,$provider);
    if (($t['expires_at']??0)>time()+90) return $t['access_token'];
    $r=mailTokenRequest($provider,['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token']]);
    if (empty($r['access_token'])) throw new RuntimeException('Reconecte sua conta de e-mail.');
    $t=['access_token'=>$r['access_token'],'refresh_token'=>$r['refresh_token']??$t['refresh_token'],'expires_at'=>time()+(int)($r['expires_in']??3600)];
    query('UPDATE mail_accounts SET tokens=?,updated_at=? WHERE user_id=? AND provider=? AND subject=?',[mailSeal($t,$owner,$provider),time(),$owner,$provider,$account['subject']]);
    return $t['access_token'];
}
function mailApi(array $a,string $endpoint,string $method='GET',?array $payload=null): array {
    $base=$a['provider']==='google'?'https://gmail.googleapis.com/gmail/v1/users/me/':'https://graph.microsoft.com/v1.0/me/';
    if (str_starts_with($endpoint,'https://')) {
        if (!str_starts_with($endpoint,$base)) throw new InvalidArgumentException('Página inválida.');
        $url=$endpoint;
    } else $url=$base.$endpoint;
    $headers=['Authorization: Bearer '.mailAccess($a),'Prefer: outlook.body-content-type="text"'];
    if ($payload!==null) $headers[]='Content-Type: application/json';
    return mailHttp($url,$method,$headers,$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR));
}
function mailCursor(array $a,string $folder,string $q,string $next): string {
    $p=mailB64(json_encode(['u'=>(int)$a['user_id'],'p'=>$a['provider'],'s'=>$a['subject'],'f'=>$folder,'q'=>$q,'next'=>$next,'exp'=>time()+1800],JSON_THROW_ON_ERROR));
    return $p.'.'.mailB64(hash_hmac('sha256',$p,mailKey(),true));
}
function mailCursorRead(array $a,string $folder,string $q,string $cursor): string {
    if (strlen($cursor)>12000) throw new InvalidArgumentException('Página inválida.');
    $parts=explode('.',$cursor);
    if (count($parts)!==2||!hash_equals(mailB64(hash_hmac('sha256',$parts[0],mailKey(),true)),$parts[1])) throw new InvalidArgumentException('Página inválida.');
    $d=json_decode(mailUnb64($parts[0]),true);
    if (!is_array($d)||($d['u']??0)!==(int)$a['user_id']||($d['p']??'')!==$a['provider']||($d['s']??'')!==$a['subject']||($d['f']??'')!==$folder||($d['q']??'')!==$q||($d['exp']??0)<time()) throw new InvalidArgumentException('Página expirada. Atualize sua caixa.');
    return $d['next'];
}
function mailId(mixed $id): string {
    if (!is_string($id)||!preg_match('/^[a-zA-Z0-9_+=\/-]{1,1500}$/D',$id)) throw new InvalidArgumentException('Mensagem inválida.');
    return rawurlencode($id);
}
function mailHtmlText(string $html): string {
    $html=preg_replace('~<(script|style|head)\b[^>]*>.*?</\1\s*>~is','',$html);
    $html=preg_replace('~<(br|/p|/div|/tr|/li)\b[^>]*>~i',"\n",$html);
    return trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}
function mailHeaders(array $m): array {
    $headers=[];foreach($m['payload']['headers']??[] as $h) $headers[strtolower($h['name'])]=$h['value'];return $headers;
}
function mailParts(array $part,array &$plain,array &$html,array &$attachments): void {
    $b=$part['body']??[];$filename=$part['filename']??'';$mime=$part['mimeType']??'';
    if ($filename!=='') {
        if (!empty($b['attachmentId'])) $attachments[]=['id'=>$b['attachmentId'],'name'=>$filename,'size'=>(int)($b['size']??0)];
    } elseif(isset($b['data'])&&in_array($mime,['text/plain','text/html'],true)) {
        $text=mailUnb64($b['data']);$charset='UTF-8';
        foreach($part['headers']??[] as $h) if(strtolower($h['name'])==='content-type'&&preg_match('/charset=["\x27]?([^;"\x27\s]+)/i',$h['value'],$match))$charset=$match[1];
        if (strtoupper($charset)!=='UTF-8') {try{$text=mb_convert_encoding($text,'UTF-8',$charset);}catch(ValueError $e){}}
        if($mime==='text/plain')$plain[]=$text;else $html[]=mailHtmlText($text);
    }
    foreach($part['parts']??[] as $child) mailParts($child,$plain,$html,$attachments);
}
function mailNormalizeGoogle(array $m,bool $full=false): array {
    $h=mailHeaders($m);$r=['id'=>$m['id'],'subject'=>$h['subject']??'(Sem assunto)','from'=>$h['from']??'','to'=>$h['to']??'','date'=>isset($m['internalDate'])?gmdate(DATE_ATOM,(int)($m['internalDate']/1000)):($h['date']??''),'snippet'=>html_entity_decode($m['snippet']??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),'unread'=>in_array('UNREAD',$m['labelIds']??[],true)];
    if($full){$plain=[];$html=[];$attachments=[];mailParts($m['payload']??[],$plain,$html,$attachments);$r['replyTo']=$h['reply-to']??$h['from']??'';$r['body']=implode("\n\n",$plain?:$html);$r['attachments']=$attachments;}
    return $r;
}
function mailNormalizeMicrosoft(array $m,bool $full=false): array {
    $r=['id'=>$m['id'],'subject'=>$m['subject']?:'(Sem assunto)','from'=>$m['from']['emailAddress']['address']??'','to'=>implode(', ',array_map(fn($r)=>$r['emailAddress']['address']??'',$m['toRecipients']??[])),'date'=>$m['receivedDateTime']??$m['sentDateTime']??'','snippet'=>$m['bodyPreview']??'','unread'=>empty($m['isRead'])];
    if($full) $r['body']=strtolower($m['body']['contentType']??'')==='html'?mailHtmlText($m['body']['content']??''):($m['body']['content']??'');
    return $r;
}
function mailList(array $a,string $folder,string $search,string $cursor): array {
    if (!in_array($folder,['inbox','sent','trash'],true)) throw new InvalidArgumentException('Pasta inválida.');
    if(mb_strlen($search)>200)throw new InvalidArgumentException('Pesquisa muito longa.');
    $next=$cursor!==''?mailCursorRead($a,$folder,$search,$cursor):'';
    if ($a['provider']==='google') {
        $label=['inbox'=>'INBOX','sent'=>'SENT','trash'=>'TRASH'][$folder];
        $params=['maxResults'=>15,'labelIds'=>$label,'includeSpamTrash'=>'true'];
        if($search!=='')$params['q']=$search;if($next!=='')$params['pageToken']=$next;
        $data=mailApi($a,'messages?'.http_build_query($params));$items=[];
        foreach($data['messages']??[] as $m) $items[]=mailNormalizeGoogle(mailApi($a,'messages/'.mailId($m['id']).'?format=metadata&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Subject&metadataHeaders=Date'));
        $counts=mailApi($a,'labels/INBOX');$next=$data['nextPageToken']??'';
        $unread=(int)($counts['messagesUnread']??0);
    } else {
        $name=['inbox'=>'inbox','sent'=>'sentitems','trash'=>'deleteditems'][$folder];
        $params=['$top'=>15,'$select'=>'id,subject,from,toRecipients,receivedDateTime,sentDateTime,bodyPreview,isRead'];
        if($search!=='')$params['$search']='"'.str_replace(['\\','"'],['\\\\','\\"'],$search).'"';else $params['$orderby']='receivedDateTime desc';
        $url=$next!==''?$next:'mailFolders/'.$name.'/messages?'.http_build_query($params);
        $data=mailApi($a,$url);$items=array_map('mailNormalizeMicrosoft',$data['value']??[]);
        $counts=mailApi($a,'mailFolders/inbox?$select=unreadItemCount');$unread=(int)($counts['unreadItemCount']??0);$next=$data['@odata.nextLink']??'';
    }
    return ['items'=>$items,'unread'=>$unread,'next'=>$next!==''?mailCursor($a,$folder,$search,$next):''];
}
function mailMessage(array $a,string $id): array {
    $id=mailId($id);
    if($a['provider']==='google')return mailNormalizeGoogle(mailApi($a,'messages/'.$id.'?format=full'),true);
    $m=mailApi($a,'messages/'.$id.'?$select=id,subject,from,toRecipients,receivedDateTime,sentDateTime,bodyPreview,body,isRead,hasAttachments,replyTo');$r=mailNormalizeMicrosoft($m,true);$r['replyTo']=implode(', ',array_map(fn($r)=>$r['emailAddress']['address']??'',$m['replyTo']??[]))?:$r['from'];$r['attachments']=[];
    if(!empty($m['hasAttachments'])) {
        $attachments=mailApi($a,'messages/'.$id.'/attachments?$select=id,name,size,isInline');
        foreach($attachments['value']??[] as $f)if(($f['@odata.type']??'')==='#microsoft.graph.fileAttachment'&&empty($f['isInline']))$r['attachments'][]=['id'=>$f['id'],'name'=>$f['name'],'size'=>(int)$f['size']];
    }
    return $r;
}
function mailManage(array $a): void {
    if($a['mode']!=='manage'||mailProvider($a['provider'])['mode']!=='manage')throw new InvalidArgumentException('Esta conexão permite somente leitura.');
}
function mailAddress(string $s): string {
    if(preg_match('/[\r\n]/',$s)||strlen($s)>254||!filter_var($s,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe um destinatário válido, sem nome ou separadores.');return $s;
}
function mailReplyAddress(string $header): string {
    if(preg_match('/<([^<>]+)>/',$header,$match))return mailAddress(trim($match[1]));return mailAddress(trim($header));
}
function mailSend(array $a,array $p,bool $reply): void {
    mailManage($a);$text=$p['body']??'';$subject=$p['subject']??'';
    if(!is_string($text)||trim($text)===''||mb_strlen($text)>50000)throw new InvalidArgumentException('Escreva uma mensagem de até 50 mil caracteres.');
    if(!is_string($subject)||mb_strlen($subject)>200||preg_match('/[\r\n]/',$subject))throw new InvalidArgumentException('Assunto inválido (até 200 caracteres).');
    $id=$reply?mailId($p['id']??null):'';
    if($a['provider']==='microsoft') {
        if($reply)mailApi($a,'messages/'.$id.'/reply','POST',['message'=>['body'=>['contentType'=>'Text','content'=>$text]]]);
        else mailApi($a,'sendMail','POST',['message'=>['subject'=>$subject,'body'=>['contentType'=>'Text','content'=>$text],'toRecipients'=>[['emailAddress'=>['address'=>mailAddress(trim($p['to']??''))]]]],'saveToSentItems'=>true]);
        return;
    }
    $thread=null;$headers=[];
    if($reply) {
        $original=mailApi($a,'messages/'.$id.'?format=metadata&metadataHeaders=Reply-To&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Message-ID&metadataHeaders=References');$h=mailHeaders($original);
        $to=mailReplyAddress($h['reply-to']??$h['from']??'');$subject=$h['subject']??'';
        if(!preg_match('/^re:/i',$subject))$subject='Re: '.$subject;
        $subject=preg_replace('/[\r\n]+/',' ',$subject);$thread=$original['threadId']??null;
        $messageId=$h['message-id']??'';
        if(preg_match('/^<[^<>\s]+>$/D',$messageId)) {
            $headers[]='In-Reply-To: '.$messageId;
            $refs=preg_replace('/[\r\n]+/',' ',$h['references']??'');preg_match_all('/<[^<>\s]+>/',$refs,$matches);
            $headers[]='References: '.implode(' ',array_slice(array_merge($matches[0],[$messageId]),-20));
        }
    } else $to=mailAddress(trim($p['to']??''));
    $headers=array_merge(['From: '.mailAddress($a['address']),'To: '.$to,'Subject: '.mb_encode_mimeheader($subject,'UTF-8','B',"\r\n"),'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'],$headers);
    $raw=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($text),76,"\r\n");
    $payload=['raw'=>mailB64($raw)];if($thread)$payload['threadId']=$thread;
    mailApi($a,'messages/send','POST',$payload);
}
function mailDownload(array $a,string $messageId,string $attachmentId): array {
    $message=mailMessage($a,$messageId);$found=null;
    foreach($message['attachments']??[] as $file)if($file['id']===$attachmentId)$found=$file;
    if(!$found)throw new InvalidArgumentException('Anexo não encontrado nesta mensagem.');
    if($found['size']>10000000)throw new InvalidArgumentException('Abra anexos maiores que 10 MB diretamente no provedor.');
    $id=mailId($messageId);$file=mailId($attachmentId);
    if($a['provider']==='google'){$data=mailApi($a,'messages/'.$id.'/attachments/'.$file);$bytes=mailUnb64($data['data']??'');}
    else {$data=mailApi($a,'messages/'.$id.'/attachments/'.$file);$bytes=base64_decode($data['contentBytes']??'',true);}
    if(!is_string($bytes)||strlen($bytes)>10000000)throw new RuntimeException('Anexo indisponível ou acima de 10 MB.');
    return ['name'=>preg_replace('/[\\\\\/\x00-\x1f]/','_',$found['name']),'data'=>base64_encode($bytes)];
}
