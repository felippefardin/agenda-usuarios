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
 foreach(query('SELECT date,name,type FROM local_holidays WHERE user_id=? AND city=? AND date LIKE ?',[$u['id'],$city['id'],$year.'-%'])->fetchAll() as $h){
  if($h['type']==='nacional'&&in_array($h['date'],array_column(nationalDates($year),'date'),true))continue;
  $items[]=$h+$cityMeta+['scope'=>$city['name'].' · arquivo importado pelo usuário'];
 }
 $official=officialCityCalendar((string)$city['id'],$year);
 if($official!==null){
 foreach($official['holidays'] as $h)$items[]=$h+$cityMeta+['scope'=>$city['name'].' · '.($h['type']==='facultativo'?'Ponto facultativo':ucfirst($h['type']))];
 $sources[]=['label'=>$official['label'],'url'=>$official['source']];
 $warnings[]='Serra/ES em 2026: base oficial para a administração municipal. Pontos facultativos têm identificação própria. Arquivos importados aparecem como datas adicionais, identificadas como importação do usuário. Não é uma regra geral de folga para o setor privado.';
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
 }elseif(empty($city['ics_url'])){$warnings[]='Feriados de '.$city['name'].': vincule um calendário público do Google, importe um arquivo CSV ou ICS no perfil ou configure a chave da FeriadosAPI (capitais gratuitas).';}

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

function csvKey(string $value): string {
 return strtr(mb_strtolower(trim($value),'UTF-8'),['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}
function csvHolidayDates(string $value,int $year): array {
 $value=csvKey(preg_replace('/\([^)]*\)/u','',$value));
 if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m))return checkedCsvDate((int)$m[1],(int)$m[2],(int)$m[3]);
 if(preg_match('~^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$~D',$value,$m))return checkedCsvDate(isset($m[3])?(int)$m[3]:$year,(int)$m[2],(int)$m[1]);
 $months=['janeiro'=>1,'fevereiro'=>2,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
 if(!preg_match('/^(\d{1,2}(?:\s*(?:e|,)\s*\d{1,2})*)\s+de\s+([a-z]+)(?:\s+de\s+(\d{4}))?$/D',$value,$m)||!isset($months[$m[2]]))throw new RuntimeException('Data não reconhecida: '.$value.'. Use dd/mm/aaaa, aaaa-mm-dd ou dias por extenso, como 16 e 17 de fevereiro.');
 $dates=[];foreach(preg_split('/\s*(?:e|,)\s*/',$m[1]) as $day)$dates=array_merge($dates,checkedCsvDate(isset($m[3])?(int)$m[3]:$year,$months[$m[2]],(int)$day));return $dates;
}
function checkedCsvDate(int $year,int $month,int $day): array {
 if($year<1900||$year>2199||!checkdate($month,$day,$year))throw new RuntimeException('Data inválida no arquivo. Confira o ano, o mês e o dia.');
 return [sprintf('%04d-%02d-%02d',$year,$month,$day)];
}
function parseHolidayCsv(string $text,int $year): array {
 if($year<1900||$year>2199)throw new RuntimeException('Informe o ano do calendário entre 1900 e 2199.');
 $text=preg_replace('/^\xEF\xBB\xBF/','',$text);
 if(!mb_check_encoding($text,'UTF-8'))$text=mb_convert_encoding($text,'UTF-8','Windows-1252');
 $file=fopen('php://temp','r+');fwrite($file,$text);rewind($file);$first=fgets($file);rewind($file);
 $delimiter=',';$count=0;foreach([',',';',"\t"] as $candidate){$fields=str_getcsv($first?:'',$candidate,'"','');if(count($fields)>$count){$count=count($fields);$delimiter=$candidate;}}
 $header=fgetcsv($file,0,$delimiter,'"','');if(!$header)throw new RuntimeException('Arquivo CSV vazio.');$header=array_map('csvKey',$header);
 $date=array_search('data',$header,true);$name=array_search('denominacao',$header,true);if($name===false)$name=array_search('nome',$header,true);$nature=array_search('natureza',$header,true);
 if($date===false||$name===false)throw new RuntimeException('O CSV precisa das colunas Data e Denominação (ou Nome). A coluna Natureza é opcional.');
 $items=[];$line=1;
 try{while(($row=fgetcsv($file,0,$delimiter,'"',''))!==false){$line++;if(count(array_filter($row,fn($v)=>trim($v??'')!==''))===0)continue;
  if(count($row)===1&&preg_match('/^\d+\s+ocorrencia(?:\(s\)|s)?$/D',csvKey($row[0])))continue;
  $title=clean($row[$name]??'',250);if($title==='')throw new RuntimeException('Título ausente.');
  $type=$nature===false?'':csvKey($row[$nature]??'');$type=str_contains($type,'facultativ')?'facultativo':(str_contains($type,'nacional')?'nacional':(str_contains($type,'municipal')?'municipal':(str_contains($type,'estadual')?'estadual':'calendario')));
  foreach(csvHolidayDates($row[$date]??'',$year) as $day)$items[]=['date'=>$day,'name'=>$title,'type'=>$type];
  if(count($items)>2000)throw new RuntimeException('Limite de 2.000 datas por importação.');
 }}catch(RuntimeException $e){throw new RuntimeException('Linha '.$line.': '.$e->getMessage());}finally{fclose($file);}
 if(!$items)throw new RuntimeException('Nenhuma data encontrada no CSV.');return $items;
}
