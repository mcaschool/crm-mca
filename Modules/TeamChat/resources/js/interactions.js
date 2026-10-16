import '../css/interactions.css';
export function installInteractions({root,config,api,el,selected,accept,paint,jump,error,body}) {
 const $=s=>root.querySelector(s),t=config.labels,drafts=new Map(),edits=new Map(),composerMap=new Map(),commands=new Map();
 const dialog=$('[data-interaction-dialog]'),panel=$('[data-interaction-panel]');
 let mode=null,query='',cursor=null,epoch=0,replyEpoch=0;
 const button=(text,fn,cls='')=>{const b=el('button',cls,text);b.type='button';if(text==='×')b.setAttribute('aria-label',t.close);b.onclick=fn;return b;};
 function encode(text,map){for(const [label,id] of [...map].sort((a,b)=>b[0].length-a[0].length)){text=text.replace(new RegExp(label.replace(/[.*+?^{}$()|[\]\\]/g,'\\$&')+'(?![\\p{L}\\p{N}_])','gu'),'@['+id+']');}return text;}
 function display(m){return (m.body||'').replace(/@\[(\d+)\]/g,(_,id)=>'@'+(m.mentions?.find(u=>u.id===Number(id))?.name||t.mention_unknown));}
 function renderBody(node,m){
  node.replaceChildren();if(m.deleted){node.textContent=t.message_deleted;return;}
  let at=0;for(const hit of (m.body||'').matchAll(/@\[(\d+)\]/g)){
   node.append(document.createTextNode(m.body.slice(at,hit.index)));
   const user=m.mentions?.find(u=>u.id===Number(hit[1]));
   node.append(el('mark','tc-mention','@'+(user?.name||t.mention_unknown)));at=hit.index+hit[0].length;
  }node.append(document.createTextNode((m.body||'').slice(at)));
 }
 function replyBar(){
  const node=$('[data-reply-draft]');node.replaceChildren();const reply=drafts.get(selected());node.hidden=!reply;
  if(reply){node.append(el('span','',t.reply_to+' '+(reply.author||t.reference_unavailable)+(reply.excerpt?' · '+reply.excerpt:'')),button('×',()=>{drafts.delete(selected());replyBar();}));}
 }
 async function chooseReply(m){
  const id=selected(),request=++replyEpoch;
  try{const p=await api('/conversations/'+id+'/messages?ids[]='+m.id+'&limit=1');if(id!==selected()||request!==replyEpoch)return;
   const fresh=p.messages[0];if(!fresh)throw Error('missing');drafts.set(id,{id:fresh.id,author:fresh.deleted?'':fresh.sender_name,excerpt:fresh.deleted?t.message_deleted:(display(fresh).slice(0,100)||t.attachment)});replyBar();body.focus();
  }catch(e){error(e);}
 }
 async function change(m,operation,data={}){
  const id=m.conversation_id,key=JSON.stringify([id,m.id,operation,data]);
  let command=commands.get(key);if(!command){command=crypto.randomUUID();commands.set(key,command);}
  try{
   const saved=await api('/conversations/'+id+'/messages/'+m.id+'/actions/'+operation,'POST',{command_id:command,...data});
   commands.delete(key);edits.delete(m.id);accept(id,[saved]);await paint(m.id);return saved;
  }catch(e){if(e.status===409){try{const current=await api('/conversations/'+id+'/messages?ids[]='+m.id+'&limit=1');accept(id,current.messages);await paint();}catch{}error(e);$('[data-error] span').textContent=t.edit_conflict;notice(t.edit_conflict);}else{notice(t.error);error(e);}throw e;}
 }
 function notice(text){const n=$('[data-interaction-error]');n.textContent=text;n.hidden=false;}
 function open(kind){mode=kind;epoch++;panel.replaceChildren();$('[data-interaction-error]').hidden=true;dialog.showModal();}
 function close(){epoch++;mode=null;dialog.close();panel.replaceChildren();}
 function confirmDelete(m){
  open('delete');panel.append(el('h3','',t.delete_message),el('p','',t.delete_confirm),
   button(t.cancel,close),button(t.delete_message,async()=>{try{await change(m,'delete',{version:m.version});close();}catch{}}));
 }
 function picker(m){
  open('reaction');panel.append(el('h3','',t.react));
  const list=el('div','tc-emoji-picker');
  for(const emoji of config.interactions.emojis){const own=m.reactions?.some(r=>r.emoji===emoji&&r.mine);
   const b=button(emoji,async()=>{try{await change(m,own?'unreact':'react',{emoji});close();}catch{}});b.setAttribute('aria-pressed',String(!!own));list.append(b);}
  panel.append(list);
 }
 function edit(m){const map=new Map((m.mentions||[]).map(u=>['@'+u.name,u.id]));edits.set(m.id,{text:display(m),version:m.version,map});paint();}
 function decorate(bubble,m){
  if(m.reply){
   const label=!m.reply.available?t.reference_unavailable:m.reply.deleted?t.message_deleted:(m.reply.author+' · '+(m.reply.body||t[m.reply.attachment==='voice'?'voice_name':'attachment']));
   const b=button(label,()=>jump(m.reply.id).catch(error),'tc-reply-reference');b.disabled=!m.reply.available;bubble.prepend(b);
  }
  if(m.edited_at&&!m.deleted)bubble.append(el('span','tc-edited',t.edited));
  if(m.pinned&&!m.deleted)bubble.append(el('span','tc-pin-mark','◆ '+t.pinned));
  if(m.id&&!m.deleted){
   const bar=el('div','tc-reactions');
   for(const r of m.reactions||[]){const b=button(r.emoji+' '+r.count,()=>change(m,r.mine?'unreact':'react',{emoji:r.emoji}).catch(()=>{}));
    b.title=r.users.map(u=>u.name).join(', ');b.setAttribute('aria-label',r.emoji+' '+r.count+' · '+b.title);b.setAttribute('aria-pressed',String(r.mine));bar.append(b);}
   bubble.append(bar);
   const menu=el('details','tc-message-actions'),summary=el('summary','','⋯');summary.setAttribute('aria-label',t.message_actions);menu.append(summary);
   menu.append(button(t.reply,()=>chooseReply(m)),button(t.react,()=>picker(m)));
   if(m.can_edit)menu.append(button(t.edit_message,()=>edit(m)));
   if(m.can_delete)menu.append(button(t.delete_message,()=>confirmDelete(m)));
   if(m.can_pin)menu.append(button(m.pinned?t.unpin:t.pin,()=>change(m,m.pinned?'unpin':'pin').catch(()=>{})));
   menu.addEventListener('click',e=>{if(e.target.closest('button'))menu.open=false;});bubble.append(menu);
  }
  const draft=edits.get(m.id);
  if(draft&&!m.deleted){
   const editor=el('div','tc-inline-editor'),input=el('textarea');input.value=draft.text;input.maxLength=5000;input.setAttribute('aria-label',t.edit_message);
   input.oninput=()=>draft.text=input.value;
   editor.append(input,button(t.save,async()=>{try{await change(m,'edit',{version:draft.version,body:encode(input.value,draft.map)});}catch{}}),
    button(t.cancel,()=>{edits.delete(m.id);paint();}));
   if(draft.version!==m.version)editor.append(el('p','',t.edit_conflict));
   bubble.append(editor);suggestions(input,draft.map,editor);
  }
 }
 function suggestions(input,map,container){
  const list=el('div','tc-mention-options');list.setAttribute('role','listbox');list.hidden=true;container.append(list);
  let options=[],index=0,range=null,request=0,timer;
  function draw(){list.replaceChildren();options.forEach((u,n)=>{const b=button(u.name,()=>select(n));b.setAttribute('role','option');b.setAttribute('aria-selected',String(n===index));b.onmousedown=e=>e.preventDefault();list.append(b);});list.hidden=!options.length;}
  function select(n){if(!range||!options[n])return;const u=options[n];let label='@'+u.name;if(map.has(label)&&map.get(label)!==u.id)label+=' ('+u.id+')';
   map.set(label,u.id);input.setRangeText(label+' ',range.start,range.end,'end');input.dispatchEvent(new Event('input',{bubbles:true}));clearTimeout(timer);request++;options=[];list.hidden=true;input.focus();}
  input.addEventListener('input',()=>{clearTimeout(timer);const hit=input.value.slice(0,input.selectionStart).match(/(?:^|\s)@([^@\n[\]]{0,60})$/u);const version=++request;
   if(!hit){options=[];list.hidden=true;return;}range={start:input.selectionStart-hit[1].length-1,end:input.selectionStart};const id=selected();
   timer=setTimeout(async()=>{try{const result=await api('/conversations/'+id+'/mention-members?q='+encodeURIComponent(hit[1]));
    if(version!==request||id!==selected()||!input.isConnected)return;options=result.users;index=0;draw();
   }catch{options=[];list.hidden=true;}},220);
  });
  input.addEventListener('keydown',e=>{
   if(list.hidden)return;
   if(['ArrowDown','ArrowUp','Enter','Escape'].includes(e.key)){e.preventDefault();e.stopImmediatePropagation();
    if(e.key==='Escape'){options=[];list.hidden=true;}else if(e.key==='Enter')select(index);else{index=(index+(e.key==='ArrowDown'?1:-1)+options.length)%options.length;draw();}
   }
  });
 }
 async function results(more=false){
  const id=selected(),version=++epoch;
  const url=mode==='pins'?'/pins':'/search?q='+encodeURIComponent(query)+(more&&cursor?'&cursor='+encodeURIComponent(cursor):'');
  panel.querySelector('[data-results-loading]')?.remove();const loading=el('p','',t.loading);loading.dataset.resultsLoading='';panel.append(loading);
  let result;try{result=await api('/conversations/'+id+url);}finally{loading.remove();}
  if(version!==epoch||id!==selected()||!dialog.open)return;
  let list=panel.querySelector('[data-message-results]');if(!list){list=el('div','tc-message-results');list.dataset.messageResults='';panel.append(list);}
  if(!more)list.replaceChildren();
  for(const m of result.messages){const b=button((m.sender_name||'')+' · '+new Date(m.created_at).toLocaleString(config.locale)+'\n'+(display(m)||t.attachment),()=>{close();jump(m.id).catch(error);});b.dataset.resultMessage=m.id;list.append(b);}
  if(!list.children.length)list.append(el('p','',t.no_results));
  cursor=result.next_cursor;panel.querySelector('[data-results-more]')?.remove();
  if(cursor){const moreButton=button(t.more,()=>results(true).catch(error));moreButton.dataset.resultsMore='';panel.append(moreButton);}
 }
 function search(){open('search');query='';cursor=null;
  const form=el('form','tc-message-search'),input=el('input');input.type='search';input.minLength=2;input.maxLength=100;input.required=true;input.setAttribute('aria-label',t.search_messages);
  const send=el('button','',t.search_messages);send.type='submit';form.append(input,send);form.onsubmit=e=>{e.preventDefault();query=input.value.trim();results().catch(e=>notice(e.status===429?t.rate_limit:t.error));};panel.append(form);input.focus();
 }
 $('[data-search-messages]').onclick=search;
 $('[data-show-pins]').onclick=()=>{open('pins');panel.append(el('h3','',t.pinned));results().catch(error);};
 $('[data-interaction-close]').onclick=close;dialog.addEventListener('cancel',close);
 suggestions(body,composerMap,$('[data-form]'));
 return {decorate,renderBody,encodeBody:text=>encode(text,composerMap),
  mentionMetadata:()=>[...composerMap].map(([label,id])=>({id,name:label.slice(1)})),
  takeReply:()=>{const r=drafts.get(selected());drafts.delete(selected());replyBar();return r?.id||null;},
  switched:()=>{replyEpoch++;close();replyBar();root.querySelectorAll('.tc-mention-options').forEach(n=>n.hidden=true);},
  reset:()=>{replyEpoch++;drafts.clear();edits.clear();composerMap.clear();commands.clear();$('[data-mention-notice]').hidden=true;close();replyBar();},
  refresh:()=>{const id=selected(),draft=drafts.get(id);if(draft)api('/conversations/'+id+'/messages?ids[]='+draft.id+'&limit=1').then(p=>{if(id!==selected()||drafts.get(id)!==draft)return;const m=p.messages[0];draft.author=m&&!m.deleted?m.sender_name:'';draft.excerpt=!m?t.reference_unavailable:m.deleted?t.message_deleted:(display(m).slice(0,100)||t.attachment);replyBar();}).catch(()=>{drafts.delete(id);replyBar();});if(dialog.open&&['search','pins'].includes(mode)&&(mode==='pins'||query.length>=2))results().catch(()=>close());},
  notified:(conversation,event)=>{const n=$('[data-mention-notice]');n.replaceChildren(button(t.mentioned_you,async()=>{try{const m=await api('/conversations/'+conversation+'/mention-target/'+event);n.hidden=true;await jump(m.id,conversation);}catch(e){n.hidden=true;error(e);}}),button('×',()=>n.hidden=true));n.hidden=false;},
 };
}
