<?php
function positivePeriod(int $hour): string {return $hour>=5&&$hour<12?'morning':($hour>=12&&$hour<18?'afternoon':'night');}
function positiveQuote(string $period,string $day): array {
 $key='positive-'.$day.'-'.$period;
 $cached=query('SELECT value FROM cache WHERE key=? AND expires>?',[$key,time()])->fetch();
 if($cached){$data=json_decode($cached['value'],true);if(is_array($data)&&isset($data['text']))return $data;}
 $local=[
 'morning'=>['Um passo de cada vez também é progresso. Escolha uma prioridade e comece com calma.','Hoje é uma nova oportunidade de cuidar do que importa para você.'],
 'afternoon'=>['Você pode ajustar o ritmo e seguir em frente. Pequenos avanços fazem diferença.','Reconheça o que já realizou e escolha seu próximo passo com tranquilidade.'],
 'night'=>['O seu esforço de hoje merece reconhecimento. Reserve um momento para descansar.','Nem tudo precisa ficar pronto hoje. Amanhã você pode continuar, um passo de cada vez.']];
 $options=$local[$period];$data=['text'=>$options[(int)date('z')%count($options)],'author'=>'Minha Agenda','source'=>'Mensagem local'];$ttl=300;
 try{
  $curl=curl_init('https://www.ferramentasdaweb.com.br/mensagens-motivacionais/api/v1/frases.php/aleatoria');
  curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXFILESIZE=>100000,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'MinhaAgenda/1.0']);
  $raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
  if($code===200&&is_string($raw)&&strlen($raw)<=100000){$response=json_decode($raw,true);$quote=$response['data']['frase']??null;$author=$response['data']['autor']??'';
   if(($response['success']??false)&&is_string($quote)&&mb_strlen($quote)>=5&&mb_strlen($quote)<=500){$data=['text'=>strip_tags($quote),'author'=>is_string($author)?mb_substr(strip_tags($author),0,120):'','source'=>'Frases Motivacionais · Ferramentas da Web'];$ttl=43200;}
  }
 }catch(Throwable $e){}
 query('INSERT OR REPLACE INTO cache(key,value,expires) VALUES(?,?,?)',[$key,json_encode($data,JSON_UNESCAPED_UNICODE),time()+$ttl]);return $data;
}
