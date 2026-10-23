<?php
function uiAvatarInitials(string $name): string {
 $words=preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY);
 return $words?mb_strtoupper(mb_substr($words[0],0,1).(count($words)>1?mb_substr($words[count($words)-1],0,1):''),'UTF-8'):'?';
}
function outputUiAvatar(array $u): never {
 $colors=['ui-blue'=>'0d6eaf','ui-teal'=>'087f8c','ui-purple'=>'7554a3','ui-rose'=>'ad3b63','ui-orange'=>'a85812','ui-slate'=>'495970'];
 if(!isset($colors[$u['avatar']]))fail('Escolha um avatar de iniciais no perfil.',400);
 $initials=uiAvatarInitials($u['name']);$color=$colors[$u['avatar']];$key='ui-avatar-'.hash('sha256',$initials.'|'.$color);
 session_write_close();
 $cached=query('SELECT value FROM cache WHERE key=? AND expires>?',[$key,time()])->fetch();
 if($cached){$png=base64_decode($cached['value'],true);if(is_string($png)&&str_starts_with($png,"\x89PNG\r\n\x1a\n")){header('Content-Type: image/png');echo $png;exit;}}
 try{
  // Apenas as iniciais são enviadas ao serviço; nome completo, e-mail e registros ficam no servidor local.
  $url='https://ui-avatars.com/api/?'.http_build_query(['name'=>$initials,'length'=>2,'size'=>96,'background'=>$color,'color'=>'ffffff','bold'=>'true','format'=>'png']);
  $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXFILESIZE=>100000]);
  $png=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
  if($code===200&&is_string($png)&&strlen($png)<100000&&str_starts_with($png,"\x89PNG\r\n\x1a\n")){
   query('INSERT OR REPLACE INTO cache(key,value,expires) VALUES(?,?,?)',[$key,base64_encode($png),time()+2592000]);header('Content-Type: image/png');echo $png;exit;
  }
 }catch(Throwable $e){}
 header('Content-Type: image/svg+xml; charset=utf-8');$text=htmlspecialchars($initials,ENT_QUOTES|ENT_XML1,'UTF-8');
 echo '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="#'.$color.'"/><text x="48" y="50" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-size="40" font-weight="700" fill="#fff">'.$text.'</text></svg>';exit;
}
