'use strict';
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
let notes=[],notesLoadId=0;
let csrf='',me=null,events=[],holidays=[],cities=[],availableCities=[],month=new Date(new Date().getFullYear(),new Date().getMonth(),1),listMode=false,loadId=0;
try{const saved=sessionStorage.getItem('agenda-month');if(/^\d{4}-(0[1-9]|1[0-2])$/.test(saved||'')){const [y,m]=saved.split('-').map(Number);month=new Date(y,m-1,1);}}catch{}
const cityPalette={teal:'Turquesa',blue:'Azul',violet:'Violeta',orange:'Laranja',pink:'Rosa',green:'Verde',indigo:'Anil',cyan:'Ciano',red:'Vermelho',olive:'Oliva',brown:'Marrom',slate:'Cinza azulado'};
const cityColor=(c,i=0)=>Object.hasOwn(cityPalette,c.color)?c.color:Object.keys(cityPalette)[i%12];
const holidayClass=h=>h.city_id?'municipal city-'+cityColor(h):'holiday';
const priorityNames={low:'Baixa',normal:'Normal',high:'Alta',urgent:'Urgente'};
const dateKey=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const monthKey=()=>dateKey(month).slice(0,7);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(text,error=false){const t=$('#toast');t.innerHTML=`<i class="bi bi-${error?'exclamation-triangle-fill':'check-circle-fill'}" aria-hidden="true"></i><div><span>${esc(text)}</span></div><button type="button" aria-label="Fechar mensagem"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;t.hidden=false;t.setAttribute('role',error?'alert':'status');t.setAttribute('aria-live',error?'assertive':'polite');t.classList.toggle('error-toast',error);t.querySelector('button').onclick=()=>t.hidden=true;clearTimeout(toast.timer);toast.timer=setTimeout(()=>t.hidden=true,4000);}
async function api(action,data,params=''){
 const r=await fetch('api.php?action='+action+params,{method:data===undefined?'GET':'POST',cache:'no-store',headers:data===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:data===undefined?undefined:JSON.stringify(data)});
 let j;try{j=await r.json();}catch{throw Error('O servidor não respondeu corretamente. Verifique o PHP no XAMPP.');}
 if(!r.ok){if(r.status===401 && me){me=null;showScreen();}throw Error(j.error||'Não foi possível concluir.');}return j;
}
function bindForm(id,fn){$(id).addEventListener('submit',async e=>{e.preventDefault();const b=e.submitter||$(id).querySelector('button');b.disabled=true;try{await fn(Object.fromEntries(new FormData(e.target)),e.target);}catch(err){toast(err.message,true);}finally{b.disabled=false;}});}
function updateHeader(){ $('#header-nav').hidden=!me;$('#header-user').hidden=!me;if(me){$('#user-name').textContent=me.name.split(' ')[0];$('#user-avatar').innerHTML=me.avatar?.startsWith('ui-')?`<img src="api.php?action=avatar_image&amp;v=${encodeURIComponent(me.id+'-'+me.avatar+'-'+avatarInitials(me.name))}" alt="">`:me.avatar?`<img src="assets/avatars/${esc(me.avatar)}.svg" alt="">`:esc(me.name.trim().charAt(0).toLocaleUpperCase('pt-BR'));} }
function showScreen(){ ++loadId;++notesLoadId;events=[];holidays=[];notes=[];render();renderNotes();if(!me){overdueDeferred.clear();overdueCurrent=null;$$('dialog[open]').forEach(d=>d.close());$('#event-form').reset();$('#note-form').reset();}updateHeader();$('#auth').hidden=!!me;$('#workspace').hidden=!me;if(me){updateHeader();$('#greeting').textContent='Bem-vindo, '+me.name.split(' ')[0]+'. Este é o seu espaço para organizar o dia.';fillProfile();loadAgenda();loadNotes();checkOverdue();} document.dispatchEvent(new Event('agenda:session')); }
function switchView(profile){$('#notes-area').hidden=profile;$('#page-header').hidden=!profile;$('#profile-view').hidden=!profile;$('#agenda-view').hidden=profile;$('#new-event').hidden=profile;$('#page-title').textContent=profile?'Meu perfil':'Minha agenda';$('#nav-profile').classList.toggle('active',profile);$('#nav-agenda').classList.toggle('active',!profile);$('#nav-profile').toggleAttribute('aria-current',profile);$('#nav-agenda').toggleAttribute('aria-current',!profile);if(profile)$('#nav-profile').setAttribute('aria-current','page');else $('#nav-agenda').setAttribute('aria-current','page');if(profile)api('status').then(s=>{$('#backup-status').textContent=(s.ok?'Último backup concluído: ':'Atenção: ')+(s.message||s.time||'');}).catch(e=>toast(e.message,true));}
async function loadAgenda(){
 const id=++loadId,selectedMonth=monthKey(),year=month.getFullYear(),owner=me?.id;
 try{sessionStorage.setItem('agenda-month',selectedMonth);}catch{}
 $('#loading').hidden=false;$('#month-label').textContent=month.toLocaleDateString('pt-BR',{month:'long',year:'numeric'});
 // Compromissos não dependem da disponibilidade dos serviços de feriados.
 const eventRequest=api('events',undefined,'&month='+selectedMonth).then(ev=>{
  if(id!==loadId||owner!==me?.id)return;
  events=ev.map(e=>({...e,id:Number(e.id),done:e.done===true||e.done===1||e.done==='1'}));render();
 }).catch(e=>{if(id===loadId)toast('Não foi possível atualizar os compromissos. '+e.message,true);});
 const holidayRequest=api('holidays',undefined,'&year='+year).then(h=>{
  if(id!==loadId||owner!==me?.id)return;
  holidays=h.holidays;$('#holiday-warnings').innerHTML=(h.warnings||[]).map(w=>'<p>'+esc(w)+'</p>').join('')+(h.sources||[]).map(s=>`<a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> ${esc(s.label)}</a>`).join('');
  $('#holiday-warnings').hidden=!(h.warnings||[]).length&&!(h.sources||[]).length;render();
 }).catch(e=>{if(id===loadId){$('#holiday-warnings').textContent='Não foi possível atualizar os feriados. Seus compromissos continuam disponíveis. '+e.message;$('#holiday-warnings').hidden=false;}});
 await Promise.all([eventRequest,holidayRequest]);if(id===loadId)$('#loading').hidden=true;
}
function filtered(){const p=$('#filter-priority').value,s=$('#filter-status').value;return events.filter(e=>(!p||e.priority===p)&&(s===''||String(Number(e.done))===s));}
function shownHolidays(){return holidays.filter(h=>$('#show-optional').checked||h.type!=='facultativo');}
function holidayType(h){return ({facultativo:'Ponto facultativo',municipal:'Feriado municipal',estadual:'Feriado estadual',nacional:'Feriado nacional',calendario:'Calendário não verificado'})[h.type]||'Calendário não verificado';}
function render(){
 $('#stat-total').textContent=events.length;$('#stat-urgent').textContent=events.filter(e=>['high','urgent'].includes(e.priority)&&!e.done).length;$('#stat-done').textContent=events.filter(e=>e.done).length;
 $('#municipal-legend').innerHTML=(me?.cities||[]).map((c,i)=>`<span class="municipal city-${cityColor(c,i)}">● ${esc(c.name)}</span>`).join('');
 $('#calendar-wrap').hidden=listMode;$('#event-list').hidden=!listMode;
 const ev=filtered();let html='';const first=new Date(month);first.setDate(1-first.getDay());const today=dateKey(new Date());
 for(let i=0;i<42;i++){const d=new Date(first);d.setDate(first.getDate()+i);const key=dateKey(d);html+=`<div data-drop-date="${key}" class="day ${d.getMonth()!==month.getMonth()?'outside':''} ${key===today?'today':''}"><span class="date-button">${d.getDate()}</span>`;
 for(const h of shownHolidays().filter(h=>h.date===key)){html+=`<div class="event-chip ${holidayClass(h)} ${h.type==='facultativo'?'optional-holiday':''}" title="${esc(holidayType(h)+' · '+h.scope)}">${h.type==='facultativo'?'PF · ':''}${esc(h.name)}</div>`;}
 for(const e of ev.filter(e=>e.start.slice(0,10)<=key && e.end.slice(0,10)>=key)){html+=`<button class="event-chip ${e.priority} ${e.done?'done':''}" data-event="${e.id}" draggable="true" title="${esc(priorityNames[e.priority]+': '+e.title)}">${eventIcon(e)} ${e.done?'<i class="bi bi-check2" aria-hidden="true"></i> ':''}${e.start.slice(0,10)===key?esc(e.start.slice(11,16))+' ':''}${esc(e.title)}</button>`;}html+='</div>';
 }$('#calendar').innerHTML=html;
 const appointments=[...ev].sort((a,b)=>a.start.localeCompare(b.start)||a.id-b.id);
 const holidayRows=shownHolidays().filter(h=>h.date.slice(0,7)===monthKey()).sort((a,b)=>a.date.localeCompare(b.date));
 const appointmentHTML=appointments.map(e=>`<div class="event-row"><span class="pill ${e.priority}">${esc(priorityNames[e.priority])}</span><div class="details ${e.done?'done':''}"><h3>${eventIcon(e)} ${esc(e.title)}</h3><p>${esc(formatTime(e.start))} — ${esc(formatTime(e.end))}</p><small>${e.done?'Concluído':'Pendente'}${e.location?' · '+esc(e.location):''}</small></div><button class="secondary" data-event="${e.id}">Editar</button></div>`).join('');
 const holidayHTML=holidayRows.map(h=>`<div class="event-row"><span class="pill ${holidayClass(h)}">${esc(h.city_name||'Nacional')}</span><div class="details"><h3>${esc(h.name)}</h3><small>${esc(h.date.split('-').reverse().join('/'))} · ${esc(holidayType(h))} · ${esc(h.scope)}</small></div></div>`).join('');
 $('#event-list').innerHTML=`<section aria-labelledby="list-appointments-title"><h2 id="list-appointments-title">Compromissos agendados (${appointments.length})</h2>${appointmentHTML||'<p class="empty">Nenhum compromisso encontrado neste mês com os filtros selecionados.</p>'}</section><section aria-labelledby="list-holidays-title"><h2 id="list-holidays-title">Feriados e pontos facultativos (${holidayRows.length})</h2>${holidayHTML||'<p class="muted">Nenhuma data encontrada neste mês com os filtros selecionados.</p>'}</section>`;
 $('#view-list').setAttribute('aria-pressed',String(listMode));$('#view-month').setAttribute('aria-pressed',String(!listMode));
}
function formatTime(value){return new Date(value).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function openEvent(id,date,record){const f=$('#event-form');f.reset();f.elements.id.value='';const e=record||events.find(e=>e.id===Number(id));if(e){for(const k of ['id','title','description','location','start','end','priority','icon'])f.elements[k].value=e[k]??(k==='icon'?'calendar-event':'');f.elements.done.checked=!!e.done;}else{f.elements.start.value=(date||dateKey(new Date()))+'T09:00';f.elements.end.value=(date||dateKey(new Date()))+'T10:00';}$('#event-title').textContent=e?'Editar compromisso':'Novo compromisso';$('#delete-event').hidden=!e;$('#event-dialog').showModal();}
function fillProfile(){renderAvatarChoices();for(const key of ['name','email','cpf','registration','phone','department'])$('#profile').elements[key].value=me[key]||'';$('#profile').elements.current_password.value='';cities=structuredClone(me.cities).map((c,i)=>({...c,color:cityColor(c,i)}));renderCities();}
function renderCities(){ $('#selected-cities').innerHTML=cities.map(c=>`<div class="city-source"><span class="city-tag municipal city-${cityColor(c)}">${esc(c.name)}<button type="button" data-remove-city="${esc(c.id)}" aria-label="Remover ${esc(c.name)}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span><label>Cor deste município<select data-city-color="${esc(c.id)}">${Object.entries(cityPalette).map(([key,name])=>`<option value="${key}" ${key===cityColor(c)?'selected':''}>${name}</option>`).join('')}</select></label><label>Calendário público de feriados (opcional)<input type="url" data-city-feed="${esc(c.id)}" value="${esc(c.ics_url||'')}" placeholder="https://calendar.google.com/calendar/ical/.../public/basic.ics"><small>Cole o endereço público ICS do Google Agenda. Atualização automática a cada 24 horas ao acessar a agenda.</small></label></div>`).join('');$('#import-city').innerHTML=me.cities.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join(''); }
$('#selected-cities').addEventListener('change',e=>{if(e.target.dataset.cityColor){const c=cities.find(c=>c.id===e.target.dataset.cityColor);if(c){c.color=e.target.value;renderCities();}}});
$('#selected-cities').addEventListener('input',e=>{if(e.target.dataset.cityFeed){const c=cities.find(c=>c.id===e.target.dataset.cityFeed);if(c)c.ics_url=e.target.value.trim();}});
function themeLabel(){const dark=document.body.classList.contains('dark');$('#theme').innerHTML=`<i class="bi bi-${dark?'sun':'moon-stars'}" aria-hidden="true"></i> ${dark?'Modo claro':'Modo escuro'}`;$('#theme').setAttribute('aria-pressed',String(dark));document.documentElement.setAttribute('data-bs-theme',dark?'dark':'light');}
let accessPrefs={font:0,contrast:false,motion:false};try{Object.assign(accessPrefs,JSON.parse(localStorage.getItem('agenda-access')||'null')||{});}catch{}
function applyAccess(){accessPrefs.font=Math.max(-4,Math.min(10,Math.round(Number(accessPrefs.font)||0)));for(const c of [...document.body.classList])if(c==='text-large'||c==='text-larger'||c.startsWith('text-size-'))document.body.classList.remove(c);document.body.classList.add('text-size-'+accessPrefs.font);$('#font-level').textContent=(100+accessPrefs.font*10)+'%';$('#font-down').disabled=accessPrefs.font<=-4;$('#font-up').disabled=accessPrefs.font>=10;document.body.classList.toggle('high-contrast',accessPrefs.contrast);document.body.classList.toggle('reduced-motion',accessPrefs.motion);$('#contrast').setAttribute('aria-pressed',String(accessPrefs.contrast));$('#reduce-motion').setAttribute('aria-pressed',String(accessPrefs.motion));try{localStorage.setItem('agenda-access',JSON.stringify(accessPrefs));}catch{}}
$('#theme').onclick=()=>{document.body.classList.toggle('dark');try{localStorage.setItem('agenda-theme',document.body.classList.contains('dark')?'dark':'light');}catch{}themeLabel();};
$('#font-up').onclick=()=>{accessPrefs.font=Math.min(10,accessPrefs.font+1);applyAccess();};$('#font-down').onclick=()=>{accessPrefs.font=Math.max(-4,accessPrefs.font-1);applyAccess();};$('#contrast').onclick=()=>{accessPrefs.contrast=!accessPrefs.contrast;applyAccess();};$('#reduce-motion').onclick=()=>{accessPrefs.motion=!accessPrefs.motion;applyAccess();};$('#access-reset').onclick=()=>{Object.assign(accessPrefs,{font:0,contrast:false,motion:false});applyAccess();};
try{if(localStorage.getItem('agenda-theme')==='dark')document.body.classList.add('dark');}catch{}themeLabel();applyAccess();
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('#access-menu').open){$('#access-menu').open=false;$('#access-menu summary').focus();}});
document.addEventListener('click',e=>{if(!$('#access-menu').contains(e.target))$('#access-menu').open=false;});
$('#show-optional').onchange=render;
document.addEventListener('click',e=>{const eye=e.target.closest('.eye');if(eye){const i=eye.previousElementSibling;i.type=i.type==='password'?'text':'password';eye.setAttribute('aria-label',i.type==='password'?'Mostrar senha':'Ocultar senha');eye.innerHTML=`<i class="bi bi-${i.type==='password'?'eye':'eye-slash'}" aria-hidden="true"></i>`;}const close=e.target.closest('.close');if(close)close.closest('dialog').close();const event=e.target.closest('[data-event]');if(event)openEvent(event.dataset.event);const city=e.target.closest('[data-remove-city]');if(city){cities=cities.filter(c=>c.id!==city.dataset.removeCity);renderCities();}});
bindForm('#login',async p=>{const r=await api('login',p);me=r.user;$('#login').reset();showScreen();toast('Login realizado com sucesso. Bem-vindo, '+me.name.split(' ')[0]+'!');});
bindForm('#register',async(p,f)=>{const r=await api('register',p);f.reset();toast(r.message);$('#login input').focus();});
$('#logout').onclick=async()=>{try{await api('logout',{});location.href='./';}catch(e){toast(e.message,true);}};
$('#forgot-open').onclick=()=>$('#forgot-dialog').showModal();bindForm('#forgot',async(p,f)=>{const r=await api('forgot',p);$('#forgot-dialog').close();f.reset();toast(r.message);});
const resetToken=new URLSearchParams(location.search).get('reset');bindForm('#reset',async p=>{const r=await api('reset',{...p,token:resetToken});$('#reset-dialog').close();history.replaceState({},'',location.pathname);toast(r.message);});
$('#copy-network').onclick=async()=>{try{await navigator.clipboard.writeText($('#network-url').textContent);toast('Link copiado.');}catch{const range=document.createRange();range.selectNodeContents($('#network-url'));window.getSelection().removeAllRanges();window.getSelection().addRange(range);toast('Link selecionado. Pressione Ctrl+C para copiar.');}};
$('#nav-profile').onclick=()=>switchView(true);$('#nav-agenda').onclick=()=>switchView(false);$('#new-event').onclick=()=>openEvent();
$('#prev').onclick=()=>{month.setMonth(month.getMonth()-1);loadAgenda();};$('#next').onclick=()=>{month.setMonth(month.getMonth()+1);loadAgenda();};$('#today').onclick=()=>{month=new Date(new Date().getFullYear(),new Date().getMonth(),1);loadAgenda();};
$('#view-list').onclick=()=>{listMode=true;render();loadAgenda();};$('#view-month').onclick=()=>{listMode=false;render();};for(const s of ['#filter-priority','#filter-status'])$(s).addEventListener('change',render);
bindForm('#event-form',async(p,f)=>{p.done=f.elements.done.checked;const saved=await api('save_event',p);if(!saved.event?.id)throw Error('O servidor não confirmou o registro. Atualize a página antes de tentar novamente.');const [y,m]=saved.event.start.slice(0,7).split('-').map(Number);month=new Date(y,m-1,1);$('#filter-priority').value='';$('#filter-status').value='';$('#event-dialog').close();toast('Compromisso salvo com sucesso.');await loadAgenda();});
$('#delete-event').onclick=async()=>{if(!confirm('Remover este compromisso?'))return;try{await api('delete_event',{id:$('#event-form').elements.id.value});$('#event-dialog').close();toast('Compromisso removido.');await loadAgenda();}catch(e){toast(e.message,true);}};
bindForm('#profile',async p=>{const r=await api('profile',{...p,cities});me=r.user;fillProfile();updateHeader();toast('Perfil salvo com sucesso. Seus municípios e cores foram atualizados.');loadAgenda();});
bindForm('#change-password',async(p,f)=>{await api('password',p);f.reset();toast('Senha atualizada.');});
$('#uf').innerHTML='<option value="">Selecione</option>'+['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].map(s=>`<option>${s}</option>`).join('');
$('#uf').onchange=async()=>{const uf=$('#uf').value;availableCities=[];$('#city').innerHTML='<option value="">Carregando…</option>';try{const result=uf?await api('cities',undefined,'&uf='+uf):[];if($('#uf').value!==uf)return;availableCities=result;$('#city').innerHTML='<option value="">Selecione</option>'+availableCities.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');}catch(e){$('#city').innerHTML='<option value="">Tente selecionar o estado novamente</option>';toast(e.message,true);}};
$('#add-city').onclick=()=>{const selected=[...$('#city').selectedOptions].map(o=>availableCities.find(c=>c.id===o.value)).filter(Boolean);if(!selected.length)return toast('Selecione um ou mais municípios.',true);const fresh=selected.filter(c=>!cities.some(x=>x.id===c.id));if(cities.length+fresh.length>30)return toast('Limite de 30 municípios.',true);for(const c of fresh){const used=new Set(cities.map(x=>x.color));const color=Object.keys(cityPalette).find(key=>!used.has(key))||cityColor({},cities.length);cities.push({...c,color});}renderCities();toast(fresh.length?'Municípios adicionados. Escolha as cores e salve o perfil.':'Os municípios selecionados já foram adicionados.');};
$('#user-name').onclick=()=>{switchView(true);$('#profile input[name="name"]').focus();};
$('#ics-file').onchange=()=>{const file=$('#ics-file').files[0];const year=file?.name.match(/(?:19|20|21)\d{2}/);if(year)$('#import-year').value=year[0];$('#import-feedback').hidden=true;};
bindForm('#import',async()=>{
 const f=$('#ics-file').files[0];if(!f||f.size>2000000)throw Error('Selecione um arquivo CSV ou ICS de até 2 MB.');
 if(!$('#import-city').value)throw Error('Adicione e salve um município no seu perfil antes de importar.');
 const format=f.name.split('.').pop().toLowerCase();if(!['csv','ics'].includes(format))throw Error('Formato não suportado. Use CSV ou ICS.');
 const bytes=await f.arrayBuffer();let text;try{text=new TextDecoder('utf-8',{fatal:true}).decode(bytes);}catch{text=new TextDecoder('windows-1252').decode(bytes);}
 const r=await api('import_ics',{city:$('#import-city').value,ics:text,format,year:Number($('#import-year').value)});
 $('#import-feedback').textContent=r.message;$('#import-feedback').hidden=false;toast(r.message);$('#ics-file').value='';await loadAgenda();
});
$('#clear-ics').onclick=async()=>{if(!$('#import-city').value||!confirm('Remover todas as datas importadas deste município?'))return;try{await api('clear_ics',{city:$('#import-city').value});toast('Datas importadas removidas.');loadAgenda();}catch(e){toast(e.message,true);}};
(async()=>{try{const s=await api('session');csrf=s.csrf;me=s.user;$('#network-url').textContent=s.network+'/';showScreen();if(resetToken)$('#reset-dialog').showModal();}catch(e){$('#auth').hidden=false;toast(e.message,true);}})();

// Revisa pendências após a virada do dia, inclusive de meses anteriores.
let overdueChecking=false,overdueCurrent=null,overdueSaving=false;
const overdueDeferred=new Set();
async function checkOverdue(){
 if(!me||document.hidden||overdueChecking||overdueSaving||document.querySelector('dialog[open]'))return;
 overdueChecking=true;
 try{
  const owner=me.id;const pending=await api('overdue_events');if(owner!==me?.id)return;
  if(!me||document.hidden||document.querySelector('dialog[open]'))return;
  overdueCurrent=pending.find(e=>!overdueDeferred.has(Number(e.id)))||null;
  if(!overdueCurrent)return;
  $('#overdue-question').textContent='A tarefa “'+overdueCurrent.title+'” foi concluída ou deseja reagendar?';
  $('#overdue-date').textContent='Término agendado: '+formatTime(overdueCurrent.end);
  $('#overdue-error').hidden=true;$('#overdue-dialog').showModal();
 }catch(e){console.warn('Não foi possível consultar as tarefas pendentes.',e);}finally{overdueChecking=false;}
}
$('#overdue-complete').onclick=async()=>{
 if(!overdueCurrent||overdueSaving)return;
 overdueSaving=true;$('#overdue-complete').disabled=true;$('#overdue-reschedule').disabled=true;
 try{
  const saved=await api('complete_event',{id:overdueCurrent.id});
  if(!saved.event||Number(saved.event.done)!==1)throw Error('Não foi possível confirmar a conclusão.');
  overdueCurrent=null;$('#overdue-dialog').close();await loadAgenda();
 }catch(e){$('#overdue-error').textContent=e.message;$('#overdue-error').hidden=false;}
 finally{overdueSaving=false;$('#overdue-complete').disabled=false;$('#overdue-reschedule').disabled=false;checkOverdue();}
};
$('#overdue-reschedule').onclick=()=>{
 if(!overdueCurrent||overdueSaving)return;
 const event=overdueCurrent;overdueDeferred.add(Number(event.id));overdueCurrent=null;
 $('#overdue-dialog').close();openEvent(event.id,null,event);
 $('#event-title').textContent='Reagendar compromisso';$('#event-form').elements.start.focus();
};
$('#overdue-dialog').addEventListener('cancel',e=>{if(overdueSaving){e.preventDefault();return;}if(overdueCurrent)overdueDeferred.add(Number(overdueCurrent.id));});
document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('close',()=>setTimeout(checkOverdue,0)));
document.addEventListener('visibilitychange',()=>{if(!document.hidden)checkOverdue();});
window.addEventListener('focus',checkOverdue);
setInterval(checkOverdue,60000);

const eventIcons={'calendar-event':'Agenda','briefcase':'Trabalho','people':'Reunião','heart-pulse':'Saúde','book':'Estudo','gift':'Aniversário','alarm':'Lembrete','house':'Pessoal','star':'Destaque','check2-circle':'Tarefa'};
function eventIcon(e){const icon=Object.hasOwn(eventIcons,e.icon)?e.icon:'calendar-event';return `<i class="bi bi-${icon}" aria-hidden="true"></i>`;}
$('#event-icon').innerHTML=Object.entries(eventIcons).map(([value,label])=>`<option value="${value}">${label}</option>`).join('');
function avatarInitials(name){const words=String(name||'').trim().split(/\s+/).filter(Boolean);return words.length?Array.from(words[0])[0].toLocaleUpperCase('pt-BR')+(words.length>1?Array.from(words[words.length-1])[0].toLocaleUpperCase('pt-BR'):''):'?';}
function renderAvatarChoices(){
 const initials=avatarInitials(me.name),palette={blue:'Azul',teal:'Turquesa',purple:'Violeta',rose:'Rosa',orange:'Laranja',slate:'Cinza'};
 $('#avatar-options').innerHTML=`<label class="avatar-choice"><input type="radio" name="avatar" value="" ${!me.avatar?'checked':''}><span class="avatar-preview">${esc(me.name.trim().charAt(0))}</span><span>Inicial</span></label>`+Array.from({length:12},(_,i)=>`<label class="avatar-choice"><input type="radio" name="avatar" value="avatar-${i+1}" ${me.avatar==='avatar-'+(i+1)?'checked':''}><img src="assets/avatars/avatar-${i+1}.svg" alt=""><span>DiceBear ${i+1}</span></label>`).join('')+`<div class="ui-avatar-heading"><strong>UI Avatars · suas iniciais</strong><small>Escolha uma cor e salve o perfil. As imagens ficam em cache no servidor e têm alternativa local sem internet.</small></div>`+Object.entries(palette).map(([key,label])=>`<label class="avatar-choice"><input type="radio" name="avatar" value="ui-${key}" ${me.avatar==='ui-'+key?'checked':''}><span class="avatar-preview ui-initials ui-${key}" aria-hidden="true">${esc(initials)}</span><span>${label}</span></label>`).join('');
}
$('#profile input[name="name"]').addEventListener('input',e=>{$$('.ui-initials').forEach(preview=>preview.textContent=avatarInitials(e.target.value));});
function renderNotes(){
 $('#notes-board').innerHTML=notes.length?notes.map(n=>`<article class="sticky-note note-${esc(n.color)}"><h3>${esc(n.title)}</h3><p>${esc(n.body)}</p><div class="note-actions"><button type="button" class="secondary" data-note="${n.id}"><i class="bi bi-pencil" aria-hidden="true"></i> Editar</button><button type="button" class="secondary" data-schedule-note="${n.id}"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Agendar</button><button type="button" class="secondary note-remove" data-delete-note="${n.id}"><i class="bi bi-trash" aria-hidden="true"></i> Remover</button></div></article>`).join(''):'<p class="muted">Seu espaço de anotações está livre. Crie seu primeiro post-it.</p>';
}
async function loadNotes(){
 const request=++notesLoadId,owner=me?.id;
 try{const result=await api('notes');if(request!==notesLoadId||owner!==me?.id)return;notes=result;renderNotes();$('#notes-feedback').hidden=true;}
 catch(e){if(request===notesLoadId&&me?.id===owner){$('#notes-feedback').textContent='Não foi possível carregar seus post-its. '+e.message;$('#notes-feedback').hidden=false;}}
}
function openNote(note){const f=$('#note-form');f.reset();if(note)for(const key of ['id','title','body','color'])f.elements[key].value=note[key];$('#note-title').textContent=note?'Editar post-it':'Novo post-it';$('#delete-note').hidden=!note;$('#note-dialog').showModal();}
$('#new-note').onclick=()=>openNote();
document.addEventListener('click',e=>{const edit=e.target.closest('[data-note]'),schedule=e.target.closest('[data-schedule-note]');if(edit){const note=notes.find(n=>Number(n.id)===Number(edit.dataset.note));if(note)openNote(note);}if(schedule){const note=notes.find(n=>Number(n.id)===Number(schedule.dataset.scheduleNote));if(note){openEvent();const f=$('#event-form');f.elements.title.value=note.title;f.elements.description.value=note.body;$('#event-title').textContent='Agendar a partir do post-it';}}});
bindForm('#note-form',async p=>{await api('save_note',p);$('#note-dialog').close();await loadNotes();notifyNotes();toast('Post-it salvo.');});
$('#delete-note').onclick=async()=>{if(!confirm('Remover este post-it?'))return;try{await api('delete_note',{id:$('#note-form').elements.id.value});$('#note-dialog').close();await loadNotes();notifyNotes();}catch(e){toast(e.message,true);}};

// Apenas a soltura em uma data válida grava o deslocamento do compromisso.
let draggedEvent=null,moveSaving=false,suppressCalendarClick=0;
function clearDropTargets(){$$('#calendar .drop-target').forEach(day=>day.classList.remove('drop-target'));}
$('#calendar').addEventListener('dragstart',e=>{
 const chip=e.target.closest('button[data-event]'),day=chip?.closest('[data-drop-date]');
 if(!chip||!day||moveSaving){e.preventDefault();return;}
 const event=events.find(item=>Number(item.id)===Number(chip.dataset.event));if(!event){e.preventDefault();return;}
 draggedEvent={id:event.id,from:day.dataset.dropDate,expected_start:event.start,expected_end:event.end,owner:me?.id};
 e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(event.id));chip.classList.add('dragging');suppressCalendarClick=Date.now()+600;
});
$('#calendar').addEventListener('dragover',e=>{
 const day=e.target.closest('[data-drop-date]');if(!draggedEvent||moveSaving||!day)return;
 e.preventDefault();e.dataTransfer.dropEffect='move';clearDropTargets();day.classList.add('drop-target');
});
$('#calendar').addEventListener('dragleave',e=>{const day=e.target.closest('[data-drop-date]');if(day&&!day.contains(e.relatedTarget))day.classList.remove('drop-target');});
$('#calendar').addEventListener('dragend',()=>{draggedEvent=null;suppressCalendarClick=Date.now()+400;clearDropTargets();$$('#calendar .dragging').forEach(chip=>chip.classList.remove('dragging'));});
$('#calendar').addEventListener('click',e=>{if(Date.now()<suppressCalendarClick){e.preventDefault();e.stopImmediatePropagation();}},true);
$('#calendar').addEventListener('drop',async e=>{
 const day=e.target.closest('[data-drop-date]'),drag=draggedEvent;if(!drag||!day||moveSaving)return;
 e.preventDefault();const to=day.dataset.dropDate;draggedEvent=null;clearDropTargets();suppressCalendarClick=Date.now()+500;
 if(to===drag.from||drag.owner!==me?.id)return;
 moveSaving=true;$('#calendar').setAttribute('aria-busy','true');
 try{
  const saved=await api('move_event',{...drag,to});if(drag.owner!==me?.id)return;
  if(!saved.event?.id)throw Error('O servidor não confirmou a nova data.');
  events=events.map(item=>Number(item.id)===Number(saved.event.id)?{...saved.event,id:Number(saved.event.id),done:Number(saved.event.done)===1}:item);
  const [year,targetMonth]=to.split('-').map(Number);month=new Date(year,targetMonth-1,1);render();toast('Data do compromisso atualizada.');await loadAgenda();
 }catch(error){if(drag.owner===me?.id){toast(error.message,true);await loadAgenda();}}
 finally{moveSaving=false;$('#calendar').setAttribute('aria-busy','false');}
});
let noteChannel=null;try{noteChannel=new BroadcastChannel('agenda-private-notes');noteChannel.onmessage=()=>{if(me)loadNotes();};}catch{}
function notifyNotes(){noteChannel?.postMessage({changed:true});}
window.addEventListener('focus',()=>{if(me)loadNotes();});
document.addEventListener('click',async e=>{
 const remove=e.target.closest('[data-delete-note]');
 if(remove){if(remove.disabled||!confirm('Remover este post-it?'))return;remove.disabled=true;try{await api('delete_note',{id:Number(remove.dataset.deleteNote)});await loadNotes();notifyNotes();}catch(error){toast(error.message,true);}finally{remove.disabled=false;}}
});
