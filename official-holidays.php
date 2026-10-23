<?php
// Fonte: Decreto municipal 1.908/2025, anexo único. Escopo: administração municipal de Serra/ES, exercício 2026.
function officialCityCalendar(string $id,int $year): ?array {
 if($id!=='3205002'||$year!==2026)return null;
 $source='https://legislacao.serra.es.gov.br/Arquivo/Documents/legislacao/html/D19082025.html';
 $dates=[
 ['01-02','Ponto facultativo','facultativo'],
 ['02-16','Carnaval','facultativo'],['02-17','Carnaval','facultativo'],['02-18','Quarta-feira de Cinzas','facultativo'],
 ['04-02','Quinta-feira Santa','facultativo'],['04-03','Paixão de Cristo','municipal'],
 ['04-13','Nossa Senhora da Penha','estadual'],['04-20','Ponto facultativo','facultativo'],
 ['06-04','Corpus Christi','facultativo'],['06-05','Ponto facultativo','facultativo'],['06-29','São Pedro','municipal'],
 ['10-30','Dia do Servidor Público (transferido)','facultativo'],
 ['12-07','Ponto facultativo','facultativo'],['12-08','Nossa Senhora da Conceição','municipal'],
 ['12-24','Véspera de Natal','facultativo'],['12-26','Dia do Serrano','municipal'],['12-31','Véspera de Ano Novo','facultativo'],
 ];
 return ['source'=>$source,'label'=>'Serra/ES · calendário oficial 2026 · Decreto 1.908/2025','holidays'=>array_map(fn($d)=>['date'=>$year.'-'.$d[0],'name'=>$d[1],'type'=>$d[2],'source'=>$source],$dates)];
}
function nationalDates(int $year): array {
 $dates=['01-01'=>'Confraternização Universal','04-21'=>'Tiradentes','05-01'=>'Dia do Trabalho','09-07'=>'Independência do Brasil','10-12'=>'Nossa Senhora Aparecida','11-02'=>'Finados','11-15'=>'Proclamação da República','12-25'=>'Natal'];
 if($year>=2024)$dates['11-20']='Dia Nacional de Zumbi e da Consciência Negra';
 $result=[];foreach($dates as $d=>$name)$result[]=['date'=>$year.'-'.$d,'name'=>$name,'scope'=>'Nacional','type'=>'nacional'];return $result;
}
