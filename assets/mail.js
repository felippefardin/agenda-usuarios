'use strict';
(() => {
  const dialog=document.querySelector('#mail-dialog');
  if(!dialog)return;
  const get=id=>document.getElementById(id);
  const providers={google:'Gmail',microsoft:'Outlook / Hotmail'};
  let epoch=0,accounts=[],provider='',folder='inbox',search='',next='',current=null,loading=false,blocked=false;
  let listRun=0,messageRun=0;
  const controllers=new Set();
  const compose=get('mail-compose-dialog');
  function wipe(close=true){
    epoch++;listRun++;messageRun++;for(const c of controllers)c.abort();controllers.clear();
    accounts=[];provider='';next='';current=null;loading=false;
    get('mail-account-list').replaceChildren();get('mail-messages').replaceChildren();get('mail-reader').replaceChildren();get('mail-account').replaceChildren();
    get('mail-compose-form').reset();get('mail-search').value='';get('mail-unread').textContent='';
    get('mail-connected-tools').hidden=true;get('mail-compose-submit').disabled=false;
    if(compose.open)compose.close();if(close&&dialog.open)dialog.close();
  }
  async function request(action,data={}){
    if(!me||blocked)throw Error('Entre novamente na agenda.');
    const owner=Number(me.id),version=epoch,c=new AbortController();controllers.add(c);
    try{
      const r=await fetch('mail.php?action='+encodeURIComponent(action),{method:'POST',cache:'no-store',credentials:'same-origin',signal:c.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Agenda-User':String(owner)},body:JSON.stringify(data)});
      let j;try{j=await r.json();}catch{throw Error('O módulo de e-mail não respondeu. Confira a instalação.');}
      if(version!==epoch||owner!==Number(me?.id))throw new DOMException('Sessão alterada','AbortError');
      if(r.status===401||r.status===409){blocked=true;wipe();throw Error('A sessão mudou. Atualize a página antes de abrir seus e-mails.');}
      if(!r.ok)throw Error(j.error||'Não foi possível concluir.');
      if(Number(j.owner)!==owner)throw Error('A resposta não pertence à sua sessão.');
      return j;
    }finally{controllers.delete(c);}
  }
  function report(e){if(e.name!=='AbortError')toast(e.message,true);}
  function el(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
  function button(text,fn,cls='secondary'){
    const b=el('button',text,cls);b.type='button';b.onclick=async()=>{if(b.disabled)return;b.disabled=true;try{await fn();}catch(e){report(e);}finally{b.disabled=false;}};return b;
  }
  function date(value){const d=new Date(value);return isNaN(d.getTime())?'':d.toLocaleString('pt-BR');}
  function account(){return accounts.find(a=>a.provider===provider);}
  function editable(){return account()?.mode==='manage';}
  function syncTools(){
    get('mail-connected-tools').hidden=!accounts.length;get('mail-new').disabled=!editable();
    get('mail-mode').textContent=account()?.mode==='readonly'?'Somente leitura':'Leitura, envio e lixeira';
  }
  async function status(){
    const version=epoch,data=await request('status');if(version!==epoch)return;
    accounts=data.accounts;const list=get('mail-account-list');list.replaceChildren();
    for(const [name,label] of Object.entries(providers)){
      const connected=accounts.find(a=>a.provider===name),card=el('div',undefined,'mail-connection');
      card.append(el('strong',label),el('span',connected?connected.address:(data.providers[name]?'Conta não conectada':'Configuração pendente')));
      if(data.providers[name])card.append(button(connected?'Trocar conta':'Conectar',async()=>{
        if(connected&&!confirm('Trocar a conta conectada ao seu usuário?'))return;
        const r=await request('connect',{provider:name});const url=new URL(r.url);
        if(url.protocol!=='https:'||!['accounts.google.com','login.microsoftonline.com'].includes(url.hostname))throw Error('Endereço de login inválido.');
        location.assign(url.href);
      }));
      if(connected)card.append(button('Desconectar',async()=>{
        if(!confirm('Desconectar esta conta do seu usuário da agenda? As mensagens serão preservadas no provedor.'))return;
        await request('disconnect',{provider:name});wipe(false);await status();if(provider)await loadList();
      }));
      list.append(card);
    }
    const select=get('mail-account');select.replaceChildren();
    for(const a of accounts){const o=el('option',providers[a.provider]+' — '+a.address);o.value=a.provider;select.append(o);}
    if(!accounts.some(a=>a.provider===provider))provider=accounts[0]?.provider||'';
    select.value=provider;syncTools();
    get('mail-setup-hint').hidden=Object.values(data.providers).some(Boolean);
  }
  async function open(){
    if(!me)return;wipe(false);blocked=false;folder='inbox';search='';get('mail-folder').value=folder;
    dialog.showModal();get('mail-feedback').textContent='Consultando suas conexões…';
    try{await status();get('mail-feedback').textContent=accounts.length?'':'Conecte sua conta para consultar seus e-mails.';if(provider)await loadList();}catch(e){get('mail-feedback').textContent=e.message;report(e);}
  }
  function resetReader(){messageRun++;current=null;get('mail-reader').replaceChildren(el('p','Selecione uma mensagem para ler.','muted'));}
  async function loadList(cursor=''){
    if(!provider)return;
    const run=++listRun,selected=provider;loading=true;get('mail-feedback').textContent='Carregando mensagens…';get('mail-more').disabled=true;
    if(!cursor){get('mail-messages').replaceChildren();resetReader();}
    try{
      const data=await request('list',{provider:selected,folder,search,cursor});
      if(run!==listRun||selected!==provider)return;
      const list=get('mail-messages');if(!cursor)list.replaceChildren();
      get('mail-unread').textContent=data.unread+' não lido(s) na caixa de entrada';
      for(const m of data.items){
        const b=button('',async()=>read(m.id),'mail-message'+(m.unread?' mail-message-unread':''));
        b.append(el('strong',m.subject),el('span',m.from),el('small',date(m.date)),el('span',m.snippet,'mail-snippet'));b.dataset.message=m.id;list.append(b);
      }
      if(!list.children.length)list.append(el('p','Nenhuma mensagem encontrada.','muted'));
      next=data.next;get('mail-more').hidden=!next;get('mail-feedback').textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR')+'.';
    }catch(e){if(run===listRun){get('mail-feedback').textContent=e.message;report(e);}}
    finally{if(run===listRun){loading=false;get('mail-more').disabled=false;}}
  }
  async function read(id){
    const run=++messageRun,selected=provider;get('mail-reader').replaceChildren(el('p','Abrindo mensagem…'));
    try{
      const r=await request('message',{provider:selected,id});if(run!==messageRun||selected!==provider)return;
      current=r.message;renderMessage();
      for(const b of get('mail-messages').querySelectorAll('button'))b.classList.toggle('mail-selected',b.dataset.message===id);
    }catch(e){if(run===messageRun){get('mail-reader').replaceChildren(el('p',e.message));report(e);}}
  }
  function renderMessage(){
    const m=current,selected=provider,version=epoch,reader=get('mail-reader');reader.replaceChildren();
    if(!m)return;
    reader.append(el('h3',m.subject),el('p','De: '+m.from),el('p','Para: '+m.to),el('small',date(m.date)));
    const actions=el('div',undefined,'mail-message-actions');
    if(editable()){
      actions.append(button('Responder',()=>startCompose(m)));
      actions.append(button(m.unread?'Marcar como lido':'Marcar como não lido',async()=>{
        await request('read',{provider:selected,id:m.id,read:!!m.unread});if(version!==epoch||selected!==provider)return;
        await loadList();await read(m.id);
      }));
      if(folder==='trash')actions.append(button('Restaurar',async()=>{
        if(!confirm('Restaurar esta mensagem na sua conta de e-mail?'))return;
        await request('restore',{provider:selected,id:m.id});if(version===epoch&&selected===provider)await loadList();
      }));
      else actions.append(button('Mover para lixeira',async()=>{
        if(!confirm('Mover esta mensagem para a lixeira da sua conta de e-mail?'))return;
        await request('trash',{provider:selected,id:m.id});if(version===epoch&&selected===provider)await loadList();
      }));
    }
    reader.append(actions,el('div',m.body||'(Sem conteúdo de texto)','mail-body'));
    if(m.attachments?.length){
      const list=el('div',undefined,'mail-attachments');list.append(el('strong','Anexos'));
      for(const f of m.attachments)list.append(button(f.name+' ('+Math.ceil(f.size/1024)+' KB)',async()=>{
        const r=await request('attachment',{provider:selected,id:m.id,attachment:f.id});if(version!==epoch||selected!==provider)return;
        const raw=atob(r.data),bytes=Uint8Array.from(raw,c=>c.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes],{type:'application/octet-stream'}));
        const link=el('a');link.href=url;link.download=r.name.replace(/[\\/\x00-\x1f]/g,'_')||'anexo';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
      }));reader.append(list);
    }
  }
  let draft=null;
  function startCompose(message=null){
    if(!editable())return;
    draft={owner:Number(me.id),provider,id:message?.id||'',epoch};const form=get('mail-compose-form');form.reset();
    get('mail-compose-title').textContent=message?'Responder e-mail':'Novo e-mail';
    form.elements.to.value=message?(message.replyTo||message.from):'';form.elements.to.readOnly=!!message;form.elements.to.type=message?'text':'email';
    form.elements.subject.value=message?(/^re:/i.test(message.subject)?message.subject:'Re: '+message.subject):'';form.elements.subject.readOnly=!!message;
    get('mail-compose-from').textContent='De: '+account().address;get('mail-compose-submit').disabled=false;compose.showModal();form.elements[message?'body':'to'].focus();
  }
  get('mail-compose-form').addEventListener('submit',async e=>{
    e.preventDefault();const b=get('mail-compose-submit');if(b.disabled||!draft)return;
    if(draft.owner!==Number(me?.id)||draft.epoch!==epoch||draft.provider!==provider){compose.close();return;}
    const form=e.target,data={...Object.fromEntries(new FormData(form)),provider:draft.provider,id:draft.id},version=epoch;
    if(!confirm('Enviar este e-mail pela conta '+account().address+'?'))return;
    b.disabled=true;
    try{await request(draft.id?'reply':'send',data);if(version!==epoch)return;compose.close();form.reset();draft=null;toast('Envio aceito pelo provedor.');await loadList();}
    catch(e){report(e);}finally{if(version===epoch)b.disabled=false;}
  });
  get('open-mail').addEventListener('click',open);
  get('mail-close').onclick=()=>dialog.close();get('mail-compose-close').onclick=()=>compose.close();get('mail-compose-cancel').onclick=()=>compose.close();
  get('mail-account').onchange=e=>{provider=e.target.value;next='';syncTools();loadList();};
  get('mail-folder').onchange=e=>{folder=e.target.value;next='';loadList();};
  get('mail-search-form').onsubmit=e=>{e.preventDefault();search=get('mail-search').value.trim();loadList();};
  get('mail-refresh').onclick=()=>{if(!loading)loadList();};get('mail-more').onclick=()=>{if(!loading&&next)loadList(next);};get('mail-new').onclick=()=>startCompose();
  compose.addEventListener('close',()=>{get('mail-compose-form').reset();draft=null;});
  dialog.addEventListener('close',()=>wipe(false));
  document.addEventListener('agenda:session',()=>{wipe();blocked=false;});
  // Nenhuma mensagem, token ou conta é persistida no navegador.
  let channel;try{channel=new BroadcastChannel('agenda-mail-session');channel.onmessage=e=>{if(Number(e.data?.owner)!==Number(me?.id||0)){blocked=true;wipe();}};}catch{}
  document.addEventListener('agenda:session',()=>{channel?.postMessage({owner:Number(me?.id||0)});});
  window.addEventListener('pageshow',e=>{if(e.persisted){wipe();location.reload();}});
  window.addEventListener('pagehide',()=>wipe());
  window.addEventListener('focus',()=>{if(dialog.open&&me)status().catch(report);});
  setInterval(()=>{if(dialog.open&&!compose.open&&!loading&&!document.hidden&&provider)loadList();},60000);
  if(new URLSearchParams(location.search).get('email')==='1'){
    const once=()=>{if(me){open();const url=new URL(location.href);url.searchParams.delete('email');history.replaceState(null,'',url);document.removeEventListener('agenda:session',once);}};
    document.addEventListener('agenda:session',once);if(me)once();
  }
})();
