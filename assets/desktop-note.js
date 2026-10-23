
'use strict';
const $=s=>document.querySelector(s),noteId=Number(new URLSearchParams(location.search).get('id'));
let csrf='',owner=null,dirty=false,saving=false,loading=false,saveTimer,channel=null;
try{channel=new BroadcastChannel('agenda-private-notes');channel.onmessage=()=>refreshNote();}catch{}
function status(message){$('#note-status').textContent=message;}
function applyColor(){const color=$('#desktop-color').value;document.body.className='desktop-note-body note-'+(['yellow','pink','blue','green'].includes(color)?color:'yellow');}
async function request(action,data){const response=await fetch('api.php?action='+action,{cache:'no-store',method:data?'POST':'GET',headers:data?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:data?JSON.stringify(data):undefined});const result=await response.json();if(!response.ok){const error=Error(result.error||'Não foi possível acessar o post-it.');error.status=response.status;throw error;}return result;}
async function refreshNote(){
 if(loading||saving||dirty||!Number.isInteger(noteId)||noteId<1)return;
 loading=true;
 try{
  const session=await request('session');
  if(!session.user){$('#note-fields').disabled=true;status('Entre na agenda para acessar seu post-it.');return;}
  if(owner!==null&&owner!==session.user.id){$('#note-fields').disabled=true;$('#desktop-title').value='';$('#desktop-body').value='';status('A conta foi alterada. Abra o post-it novamente pela sua agenda.');return;}
  owner=session.user.id;csrf=session.csrf;const result=await request('note&id='+noteId);
  if(dirty)return;
  if(document.activeElement?.matches('#desktop-title,#desktop-body,#desktop-color')&&!$('#note-fields').disabled)return;
  $('#desktop-title').value=result.note.title;$('#desktop-body').value=result.note.body;$('#desktop-color').value=result.note.color;
  applyColor();$('#note-fields').disabled=false;document.title=result.note.title+' · Post-it';status('Sincronizado com a agenda local.');
 }catch(error){if([401,404].includes(error.status)){$('#note-fields').disabled=true;$('#desktop-title').value='';$('#desktop-body').value='';}status(error.message);}
 finally{loading=false;}
}
async function saveNote(){
 if(!dirty||saving||$('#note-fields').disabled||!$('#desktop-note-form').reportValidity())return;
 saving=true;clearTimeout(saveTimer);$('#desktop-save').disabled=true;
 const snapshot={id:noteId,title:$('#desktop-title').value,body:$('#desktop-body').value,color:$('#desktop-color').value};
 try{
  const session=await request('session');if(session.user?.id!==owner){$('#note-fields').disabled=true;throw Error('Entre com a conta proprietária antes de salvar.');}csrf=session.csrf;
  await request('save_note',snapshot);dirty=snapshot.title!==$('#desktop-title').value||snapshot.body!==$('#desktop-body').value||snapshot.color!==$('#desktop-color').value;
  document.title=snapshot.title+' · Post-it';channel?.postMessage({changed:true});status(dirty?'Alterações pendentes…':'Salvo na agenda local.');
 }catch(error){status('Não foi possível salvar. '+error.message);}
 finally{saving=false;$('#desktop-save').disabled=false;if(dirty&&!$('#note-fields').disabled)status('Alterações não salvas. Clique em Salvar para tentar novamente.');}
}
$('#desktop-note-form').addEventListener('submit',e=>{e.preventDefault();saveNote();});
$('#desktop-note-form').addEventListener('input',()=>{dirty=true;applyColor();status('Alterações pendentes…');clearTimeout(saveTimer);saveTimer=setTimeout(saveNote,1000);});
window.addEventListener('beforeunload',e=>{if(dirty||saving){e.preventDefault();e.returnValue='';}});
window.addEventListener('focus',refreshNote);setInterval(refreshNote,5000);
if(!Number.isInteger(noteId)||noteId<1)status('Post-it inválido. Abra esta janela pelo mural da agenda.');else refreshNote();
