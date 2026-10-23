<?php
require_once __DIR__.'/official-holidays.php';
function holidays(array $u,int $year): array {
 global $config;
 $items=[];$warnings=[];$sources=[];
 // Restringe a base nacional a datas nacionais: Carnaval e Corpus Christi não são feriados nacionais.
 $allowed=array_column(nationalDates($year),'date');
 try { $national=cached('national-'.$year,fn()=>remoteJson('https://brasilapi.com.br/api/feriados/v1/'.$year));foreach($national as $h)if(in_array($h['date'],$allowed,true))$items[]=['date'=>$h['date'],'name'=>$h['name'],'scope'=>'Nacional','type'=>'nacional']; }
 catch(Throwable $e){$warnings[]='Fonte nacional indisponível; exibindo a base nacional incluída na aplicação.';}
 // Mantém as datas nacionais quando a fonte estiver incompleta ou sem acesso à rede.
 foreach(nationalDates($year) as $h)if(!in_array($h['date'],array_column($items,'date'),true))$items[]=$h;
 foreach(json_decode($u['cities'],true) as $index=>$city) {
 $cityMeta=['city_id'=>(string)$city['id'],'city_name'=>$city['name'],'color'=>cityColor($city,$index)];
 $official=officialCityCalendar((string)$city['id'],$year);
 if($official!==null){
 foreach($official['holidays'] as $h)$items[]=$h+$cityMeta+['scope'=>$city['name'].' · '.($h['type']==='facultativo'?'Ponto facultativo':ucfirst($h['type']))];
 $sources[]=['label'=>$official['label'],'url'=>$official['source']];
 $warnings[]='Serra/ES em 2026: base oficial para a administração municipal. Pontos facultativos têm identificação própria. Calendários externos e arquivos importados desta cidade não substituem essa base neste ano. Não é uma regra geral de folga para o setor privado.';
 continue;
 }
 if(!empty($city['ics_url'])) {
 try {
 $feed=cached('ics-'.hash('sha256',$city['ics_url']),function()use($city){
 $ch=curl_init($city['ics_url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXFILESIZE=>2000000,CURLOPT_USERAGENT=>'AgendaUsuarios/1.0']);$text=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($code!==200||!is_string($text)||strlen($text)>2000000)throw new RuntimeException('Calendário indisponível.');return parseIcs($text);
 });foreach($feed as $h)if(substr($h['date'],0,4)===(string)$year)$items[]=$h+$cityMeta+['type'=>'calendario','scope'=>$city['name'].' · calendário público (não verificado)'];
 }catch(Throwable $e){$warnings[]='Calendário público de '.$city['name'].' indisponível ou incompatível. Confira o link público e use datas sem repetição.';}
 }
 if($config['holiday_api_key']!=='') {
 try {
 $data=cached('municipal-'.$city['id'].'-'.$year,fn()=>remoteJson('https://feriadosapi.com/api/v1/feriados/cidade/'.$city['id'].'?ano='.$year.'&limit=100',['Authorization: Bearer '.$config['holiday_api_key']]));
 foreach($data['feriados']??[] as $h){if(($h['tipo']??'')==='NACIONAL')continue;$date=DateTimeImmutable::createFromFormat('!d/m/Y',$h['data']);if($date && $date->format('d/m/Y')===$h['data'])$items[]=['date'=>$date->format('Y-m-d'),'name'=>$h['nome'],'type'=>strtolower($h['tipo']??'calendario'),'scope'=>$city['name'].' · '.$h['tipo']]+$cityMeta;}
 }catch(Throwable $e){$warnings[]='Feriados de '.$city['name'].': fonte indisponível ou cidade fora do plano gratuito. Importe um calendário ICS no perfil.';}
 }elseif(empty($city['ics_url'])){$warnings[]='Feriados de '.$city['name'].': vincule um calendário público do Google, importe um arquivo ICS no perfil ou configure a chave da FeriadosAPI (capitais gratuitas).';}
 foreach(query('SELECT date,name FROM local_holidays WHERE user_id=? AND city=? AND date LIKE ?',[$u['id'],$city['id'],$year.'-%'])->fetchAll() as $h)$items[]=$h+$cityMeta+['type'=>'calendario','scope'=>$city['name'].' · calendário importado (não verificado)'];
 }
 $unique=[];foreach($items as $h){$key=$h['date'].'|'.$h['name'].'|'.$h['scope'];$unique[$key]=$h;}
 return ['holidays'=>array_values($unique),'warnings'=>$warnings,'sources'=>$sources];
}
function parseIcs(string $text): array {
 if(!str_contains($text,'BEGIN:VCALENDAR'))throw new RuntimeException('Arquivo ICS inválido.');
 $text=preg_replace('/\r?\n[ \t]/','',$text);$events=[];
 preg_match_all('/BEGIN:VEVENT\s*(.*?)END:VEVENT/s',$text,$blocks);
 foreach($blocks[1] as $b){
 if(preg_match('/^RRULE:/m',$b))throw new RuntimeException('Exporte o calendário com datas individuais, sem regras de repetição.');
 if(preg_match('/^DTSTART[^:]*:(\d{4})(\d{2})(\d{2})(?:T[^\r\n]*)?\r?$/m',$b,$d) && preg_match('/^SUMMARY[^:]*:(.+)\r?$/m',$b,$n)){
 if(!checkdate((int)$d[2],(int)$d[3],(int)$d[1]))continue;
 $name=str_replace(['\\n','\\N','\\,','\\;','\\\\'],[" "," ",',',';','\\'],trim($n[1]));
 $events[]=['date'=>"$d[1]-$d[2]-$d[3]",'name'=>clean($name,250)];
 }}
 if(!$events)throw new RuntimeException('Nenhum evento com data válida encontrado.');return $events;
}
