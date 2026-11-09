'use strict';
(() => {
  const panel = document.querySelector('#reminder-panel');
  const trashContent = document.querySelector('#trash-content');
  const notified = new Set();
  let checking = false;
  const displayDate = value => value ? new Date(value).toLocaleString('pt-BR') : '';
  async function loadTrash() {
    trashContent.textContent = 'Carregando...';
    try {
      const data = await api('trash');
      trashContent.replaceChildren();
      const items = [...data.events.map(item => ({...item, kind:'event'})), ...data.notes.map(item => ({...item, kind:'note'}))];
      if (!items.length) { trashContent.textContent = 'A lixeira está vazia.'; return; }
      for (const item of items) {
        const row = document.createElement('div'); row.className = 'trash-item';
        const text = document.createElement('span');
        text.textContent = `${item.kind === 'event' ? 'Compromisso' : 'Post-it'}: ${item.title}${item.start ? ' — ' + displayDate(item.start) : ''}`;
        const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.textContent = 'Restaurar';
        button.addEventListener('click', async () => {
          button.disabled = true;
          try {
            try { await api('restore_item', {kind:item.kind, id:Number(item.id)}); }
            catch (error) {
              if (!error.message.includes('mesmo horário') || !confirm(error.message)) throw error;
              await api('restore_item', {kind:item.kind, id:Number(item.id), allow_conflicts:true});
            }
            await loadTrash(); await Promise.all([loadAgenda(), loadNotes()]); toast('Registro restaurado.');
          } catch (error) { toast(error.message, true); button.disabled = false; }
        });
        row.append(text, button); trashContent.append(row);
      }
    } catch (error) { trashContent.textContent = 'Não foi possível carregar a lixeira.'; toast(error.message, true); }
  }
  document.querySelector('#open-trash').addEventListener('click', () => { document.querySelector('#trash-dialog').showModal(); loadTrash(); });
  document.querySelector('#export-calendar').addEventListener('click', async () => {
    try {
      const response = await fetch('api.php?action=export_calendar', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:'{}'});
      if (!response.ok) { let msg='Não foi possível exportar.'; try { msg=(await response.json()).error || msg; } catch {} throw Error(msg); }
      const url=URL.createObjectURL(await response.blob()), link=document.createElement('a');
      link.href=url; link.download='minha-agenda.ics'; document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000); toast('Agenda exportada.');
    } catch (error) { toast(error.message,true); }
  });
  async function checkReminders() {
    if (!me || checking) return; checking=true;
    try {
      const items=await api('reminders'); panel.replaceChildren(); panel.hidden=!items.length;
      for (const item of items) {
        const row=document.createElement('div'); row.className='reminder-item';
        const text=document.createElement('span'); text.textContent=`Lembrete: ${item.title} — ${displayDate(item.start)}`;
        const button=document.createElement('button'); button.type='button'; button.className='secondary'; button.textContent='Dispensar';
        button.addEventListener('click',async()=>{ button.disabled=true; try {await api('dismiss_reminder',{id:Number(item.id), start:item.start, reminder_minutes:Number(item.reminder_minutes)}); await checkReminders();} catch(e){button.disabled=false;toast(e.message,true);} });
        row.append(text,button); panel.append(row);
        const key=`${item.id}:${item.start}:${item.reminder_minutes}`;
        if ('Notification' in window && Notification.permission==='granted' && !notified.has(key)) { notified.add(key); try {new Notification('Minha Agenda',{body:`${item.title} — ${displayDate(item.start)}`});} catch {} }
      }
    } catch(error) { console.error('Erro ao verificar lembretes:',error.message); } finally {checking=false;}
  }
  document.querySelector('#enable-reminders').addEventListener('click',async()=>{
    if (!('Notification' in window) || !window.isSecureContext) {toast('Notificações do navegador exigem localhost ou uma conexão HTTPS. Os lembretes continuam visíveis na agenda.',true); return;}
    try { const permission=await Notification.requestPermission(); if(permission==='granted'){toast('Notificações ativadas neste navegador.'); await checkReminders();} else toast('Permissão para notificações não concedida. Os lembretes continuam visíveis na agenda.',true); } catch(e){toast('Não foi possível ativar as notificações neste navegador.',true);}
  });
  document.addEventListener('agenda:session',()=>{notified.clear(); if(me)checkReminders(); else {panel.hidden=true;panel.replaceChildren();}});
  window.addEventListener('focus',checkReminders); setInterval(checkReminders,30000); if(me)checkReminders();
})();
