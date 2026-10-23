'use strict';
(() => {
 const select=s=>document.querySelector(s),tour=select('#tutorial-dialog');
 const steps=[
  {title:'Bem-vindo à sua agenda',target:'.privacy-hint',text:'Aqui você organiza compromissos e anotações. Seus registros pertencem à sua conta. Use Próximo para conhecer os recursos; você pode encerrar e reabrir este tutorial quando quiser.'},
  {title:'Navegue entre os meses',target:'.toolbar .compact',text:'As setas ao lado do mês mostram o período anterior ou seguinte. Hoje volta ao mês atual. Os três indicadores resumem os compromissos do mês, os prioritários e os concluídos.'},
  {title:'Crie um Novo Registro',target:'#new-event',text:'Clique em Novo Registro. Informe título, início e fim; escolha prioridade e ícone. Local e descrição são opcionais. Clique em Salvar compromisso para gravar. Experimente abaixo: este exemplo não será salvo.',demo:'create'},
  {title:'Edite e reagende',target:'#calendar-wrap',text:'Clique em um compromisso para editar. No computador, arraste o compromisso e solte sobre outra data: os horários e a duração são preservados e a nova data é salva automaticamente. Feriados não podem ser arrastados.',demo:'drag'},
  {title:'Busque e filtre',target:'.filters',text:'Pesquise pelo título, descrição ou local. Combine Prioridade e Situação para localizar pendências ou concluídos. Se um registro não aparecer, confira o mês e os filtros selecionados.',demo:'filter'},
  {title:'Alterne Calendário e Lista',target:'.view-actions',text:'Calendário mostra os registros por dia. Lista mostra primeiro os compromissos e depois os feriados do mês. O botão Editar na lista abre o mesmo formulário de edição.'},
  {title:'Conclua tarefas pendentes',target:'#filter-status',text:'No dia seguinte ao término de um compromisso ainda pendente, a agenda pergunta se ele foi concluído ou deve ser reagendado. Concluída atualiza a situação; Reagendar abre a edição. Você também pode marcar Compromisso concluído ao editar. Use Situação: Concluídos para localizar essas tarefas.',demo:'done'},
  {title:'Entenda os feriados',target:'.optional-filter',text:'As cores identificam os municípios do seu perfil. PF significa ponto facultativo. Use Mostrar pontos facultativos para exibir ou ocultar essas datas. As importações feitas por você ficam na sua conta.'},
  {title:'Organize seus post-its',target:'#notes-area',text:'Clique em Novo post-it, escreva título e anotação, escolha a cor e salve. Editar altera a nota. Agendar preenche um novo compromisso com o texto do post-it; confira as datas e salve. Remover pede confirmação antes de excluir a nota.'},
  {title:'Personalize seu perfil',target:'.avatar-field',profile:true,text:'Meu perfil e o seu nome no cabeçalho abrem esta tela. Escolha um avatar, revise seus dados e clique em Salvar perfil. Os avatares ficam no servidor da rede local.'},
  {title:'Importe datas de um documento',target:'#import',profile:true,text:'Adicione e salve seu município primeiro. Em Calendário municipal gratuito, selecione o município, informe o ano, escolha um arquivo CSV ou ICS de até 2 MB e clique em Importar calendário. CSV aceita Data, Denominação e Natureza, inclusive 16 e 17 de fevereiro. O resultado informa quantas datas foram adicionadas.'},
  {title:'Ajuste a leitura',target:'#access-menu',text:'O botão Aa permite aumentar ou diminuir os textos, ativar alto contraste e reduzir movimentos. Modo escuro altera as cores da agenda. Você também pode navegar com Tab e usar o zoom do navegador.'},
  {title:'Tudo pronto para começar',target:'#tutorial-open',text:'Use Novo Registro para compromissos e Novo post-it para anotações rápidas. Uma mensagem positiva aparece no primeiro acesso de cada período do dia. Para rever este guia, clique em Tutorial. Ao concluir, voltaremos à tela em que você estava.'}
 ];
 let step=0,highlight=null,previousView=false,previousList=false,returnFocus=null;
 function clearHighlight(){highlight?.classList.remove('tutorial-highlight');highlight=null;}
 function showStep(){
  clearHighlight();const item=steps[step];switchView(!!item.profile);if(!item.profile){listMode=false;render();}
  select('#tutorial-title').textContent=item.title;select('#tutorial-description').textContent=item.text;
  select('#tutorial-progress').textContent=`Passo ${step+1} de ${steps.length}`;
  select('#tutorial-prev').disabled=step===0;select('#tutorial-next').textContent=step===steps.length-1?'Concluir':'Próximo';
  highlight=select(item.target);if(highlight){highlight.classList.add('tutorial-highlight');highlight.scrollIntoView({block:'start',behavior:'instant'});}
  const demo=select('#tutorial-demo');demo.replaceChildren();
  if(item.demo==='create'){
   demo.innerHTML='<label>Título de exemplo<input id="tour-sample-title" placeholder="Ex.: Reunião da equipe" maxlength="80"></label><button type="button" id="tour-sample-save">Simular criação</button><output id="tour-result" aria-live="polite"></output>';
   select('#tour-sample-save').onclick=()=>{select('#tour-result').textContent=select('#tour-sample-title').value.trim()?'Exemplo pronto! Na agenda, preencha também início e fim e use Salvar compromisso.':'Digite um título para experimentar.';};
  }
  if(item.demo==='drag'){
   demo.innerHTML='<div class="tutorial-example" id="tour-drag-item" draggable="true">Reunião de exemplo · dia 10, 9h às 10h</div><div class="tutorial-drop" id="tour-drop">Dia 12: solte o exemplo aqui</div><button type="button" id="tour-move">Simular mudança para o dia 12</button><output id="tour-result" aria-live="polite"></output>';
   const move=()=>{select('#tour-drag-item').textContent='Reunião de exemplo · dia 12, 9h às 10h';select('#tour-result').textContent='Data alterada no exemplo. O horário e a duração permanecem iguais.';};
   select('#tour-drag-item').ondragstart=e=>{e.dataTransfer.setData('text/plain','tutorial-example');};select('#tour-drop').ondragover=e=>e.preventDefault();select('#tour-drop').ondrop=e=>{e.preventDefault();if(e.dataTransfer.getData('text/plain')==='tutorial-example')move();};select('#tour-move').onclick=move;
  }
  if(item.demo==='filter'){
   demo.innerHTML='<label>Pesquise no exemplo<input id="tour-search" placeholder="Digite reunião"></label><div id="tour-search-result" class="tutorial-example" aria-live="polite">Reunião da equipe · Pendente</div>';
   select('#tour-search').oninput=()=>{select('#tour-search-result').textContent='reunião da equipe'.includes(select('#tour-search').value.toLocaleLowerCase('pt-BR'))?'Reunião da equipe · Pendente':'Nenhum exemplo encontrado. Apague a pesquisa para voltar.';};
  }
  if(item.demo==='done'){
   demo.innerHTML='<div class="tutorial-example" id="tour-task">Tarefa de exemplo · Pendente</div><button type="button" id="tour-done">Marcar exemplo como concluído</button><output id="tour-result" aria-live="polite"></output>';
   select('#tour-done').onclick=()=>{select('#tour-task').textContent='✓ Tarefa de exemplo · Concluída';select('#tour-result').textContent='Somente o exemplo foi alterado. Na agenda, o registro fica disponível em Situação: Concluídos.';};
  }
  select('#tutorial-title').focus();
 }
 function endTour(){clearHighlight();if(!me)return;switchView(previousView);listMode=previousList;render();returnFocus?.focus();}
 select('#tutorial-open').onclick=()=>{
  if(!me)return;if(document.querySelector('dialog[open]')){toast('Feche a janela atual antes de abrir o tutorial.');return;}
  previousView=!select('#profile-view').hidden;previousList=listMode;returnFocus=document.activeElement;step=0;tour.showModal();showStep();
 };
 select('#tutorial-prev').onclick=()=>{if(step>0){step--;showStep();}};
 select('#tutorial-next').onclick=()=>{if(step===steps.length-1)tour.close();else{step++;showStep();}};
 select('#tutorial-close').onclick=()=>tour.close();tour.addEventListener('close',endTour);
 tour.addEventListener('keydown',e=>{if(e.target.matches('input,textarea,select'))return;if(e.key==='ArrowRight'){e.preventDefault();select('#tutorial-next').click();}if(e.key==='ArrowLeft'){e.preventDefault();select('#tutorial-prev').click();}});

 // O servidor controla a exibição por usuário, data e período, inclusive entre abas.
 const positive=select('#positive-dialog');let requesting=false,pending=null,lastCheck=0;
 function showPending(){
  if(!pending||pending.owner!==me?.id||document.hidden||document.querySelector('dialog[open]'))return;
  select('#positive-title').textContent=({morning:'Bom dia',afternoon:'Boa tarde',night:'Boa noite'}[pending.period]||'Olá')+', '+me.name.split(' ')[0]+'!';
  select('#positive-text').textContent=pending.text;select('#positive-author').textContent=pending.author;select('#positive-source').textContent=pending.source;positive.showModal();pending=null;
 }
 async function checkPositive(){
  if(!me||document.hidden)return;if(pending){showPending();return;}if(requesting||Date.now()-lastCheck<60000||document.querySelector('dialog[open]'))return;
  requesting=true;const owner=me.id;
  try{
   const message=await api('positive_message');if(owner!==me?.id)return;lastCheck=Date.now();if(!message.available)return;
   if(document.querySelector('dialog[open]')){lastCheck=0;return;}
   const claim=await api('positive_claim',{day:message.day,period:message.period});
   if(owner!==me?.id||!claim.show)return;pending={...message,owner};showPending();
  }catch(error){console.warn('Mensagem positiva indisponível.',error);}finally{requesting=false;}
 }
 select('#positive-close').onclick=()=>positive.close();
 document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('close',()=>setTimeout(checkPositive,0)));
 document.addEventListener('agenda:session',()=>{pending=null;lastCheck=0;setTimeout(checkPositive,0);});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)checkPositive();});window.addEventListener('focus',checkPositive);setInterval(checkPositive,60000);checkPositive();
})();
