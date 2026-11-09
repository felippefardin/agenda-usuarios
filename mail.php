<?php
declare(strict_types=1);
require __DIR__.'/core.php';
require __DIR__.'/private/mail-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
sessionStart();
$callback=($_GET['action']??'')==='callback';
function mailCallbackPage(string $message): never {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conexão de e-mail</title><style>body{font:18px Arial,sans-serif;max-width:600px;margin:12vh auto;padding:24px;line-height:1.6;color:#18344c}a{color:#007e9e}</style><h1>Conexão de e-mail</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><a href="./?email=1">Voltar à minha agenda</a></p></html>';exit;
}
try {
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    $host=strtolower((string)parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST));
    $local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&in_array($host,['localhost','127.0.0.1','[::1]'],true);
    if(!$https&&!$local)throw new RuntimeException('Para abrir seus e-mails, use HTTPS. No computador que hospeda a agenda, você também pode usar http://localhost/agenda-usuarios/.');
    $u=user();$owner=(int)$u['id'];
    if(!$callback) {
        if($_SERVER['REQUEST_METHOD']!=='POST')fail('Método não permitido.',405);
        if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Atualize a agenda antes de continuar.',403);
        // Aba antiga nunca opera como o novo usuário que entrou em outra aba.
        if((string)$owner!==($_SERVER['HTTP_X_AGENDA_USER']??''))fail('A sessão mudou. Atualize a página para acessar seu e-mail.',409);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>220000)fail('Solicitação muito grande.',413);
        $p=json_decode(file_get_contents('php://input'),true);
        if(!is_array($p))fail('Dados inválidos.');
    } else {
        if($_SERVER['REQUEST_METHOD']!=='GET')fail('Método não permitido.',405);
        $p=[];
    }
    mailSchema();$action=$_GET['action']??'';
    if($callback) {
        $provider=(string)($_GET['provider']??'');$pending=mailAuthPending($owner,$provider,(string)($_GET['state']??''));
        if(isset($_GET['error']))throw new InvalidArgumentException('A conexão não foi autorizada. Você pode tentar novamente pela agenda.');
        $code=$_GET['code']??'';if(!is_string($code)||$code===''||strlen($code)>12000)throw new InvalidArgumentException('Retorno de login inválido.');
        mailAuthFinish($owner,$provider,$pending,$code);
        mailCallbackPage('Conta conectada somente ao seu usuário da agenda. Volte para abrir seus e-mails.');
    }
    if($action==='status') {
        $providers=[];foreach(['google','microsoft'] as $provider){$ready=true;try{mailProvider($provider);}catch(Throwable $e){$ready=false;}$providers[$provider]=$ready;}
        $accounts=query('SELECT provider,address,mode FROM mail_accounts WHERE user_id=? ORDER BY provider',[$owner])->fetchAll();
        result(['owner'=>$owner,'providers'=>$providers,'accounts'=>$accounts]);
    }
    $provider=$p['provider']??'';if(!is_string($provider)||!in_array($provider,['google','microsoft'],true))throw new InvalidArgumentException('Selecione Gmail ou Outlook.');
    if($action==='connect') {limit('mail-connect:'.$owner,20);result(['owner'=>$owner,'url'=>mailAuthStart($owner,$provider)]);}
    if($action==='disconnect') {
        query('DELETE FROM mail_accounts WHERE user_id=? AND provider=?',[$owner,$provider]);unset($_SESSION['mail_oauth']);
        result(['owner'=>$owner,'ok'=>true]);
    }
    $a=mailAccount($owner,$provider);
    if($action==='list')result(['owner'=>$owner]+mailList($a,(string)($p['folder']??'inbox'),trim((string)($p['search']??'')),(string)($p['cursor']??'')));
    if($action==='message')result(['owner'=>$owner,'message'=>mailMessage($a,(string)($p['id']??''))]);
    if($action==='attachment')result(['owner'=>$owner]+mailDownload($a,(string)($p['id']??''),(string)($p['attachment']??'')));
    if(in_array($action,['read','trash','restore'],true)) {
        mailManage($a);$id=mailId($p['id']??null);
        if($action==='read') {
            if(!is_bool($p['read']??null))throw new InvalidArgumentException('Estado de leitura inválido.');
            if($provider==='google')mailApi($a,'messages/'.$id.'/modify','POST',[$p['read']?'removeLabelIds':'addLabelIds'=>['UNREAD']]);
            else mailApi($a,'messages/'.$id,'PATCH',['isRead'=>$p['read']]);
        } elseif($provider==='google')mailApi($a,'messages/'.$id.'/'.($action==='trash'?'trash':'untrash'),'POST');
        else mailApi($a,'messages/'.$id.'/move','POST',['destinationId'=>$action==='trash'?'deleteditems':'inbox']);
        result(['owner'=>$owner,'ok'=>true]);
    }
    if(in_array($action,['send','reply'],true)) {
        limit('mail-send:'.$owner,30);mailSend($a,$p,$action==='reply');result(['owner'=>$owner,'ok'=>true]);
    }
    fail('Operação não encontrada.',404);
} catch(InvalidArgumentException $e) {
    if($callback)mailCallbackPage($e->getMessage());fail($e->getMessage(),400);
} catch(RuntimeException $e) {
    if($callback)mailCallbackPage($e->getMessage());fail($e->getMessage(),502);
} catch(Throwable $e) {
    // Não registra tokens, códigos de login, conteúdo de e-mail ou resposta do provedor.
    error_log('Agenda: falha na integração de e-mail ('.get_class($e).').');
    if($callback)mailCallbackPage('Não foi possível conectar. Verifique a configuração com o responsável pela agenda.');
    fail('Não foi possível concluir a operação de e-mail. Verifique a configuração.',500);
}
