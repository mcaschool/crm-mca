// Installed Chrome + isolated MySQL/HTTP/Reverb. Microphone uses Chrome's simulated device.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const fixture=JSON.parse(fs.readFileSync('.testing/teamchat-browser.json','utf8'));
const php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl','zip'].flatMap(v=>['-d','extension='+v])];
const code=()=>execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim();
const base='http://127.0.0.1:33319';let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function wait(fn,label,timeout=60000){const end=Date.now()+timeout;while(Date.now()<end){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,200));}throw Error('Timeout: '+label);}
async function api(p,url,method='GET',body=null){return p.evaluate(async({url,method,body})=>{const r=await fetch('/api/v1/collaboration'+url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{url,method,body});}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--use-fake-device-for-media-stream']});
 const errors=[],frames=[];
 async function login(who){
  const ctx=await browser.newContext({permissions:['microphone'],viewport:{width:1440,height:1000}}),p=await ctx.newPage();
  p.on('pageerror',e=>errors.push(e.message));p.on('websocket',s=>s.on('framereceived',f=>{if(String(f.payload).includes('collaboration.sync.required'))frames.push(String(f.payload));}));
  await p.goto(base+'/colaboracion');await p.locator('[name=email]').fill(who+'@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');
  await p.locator('button[type=submit]').click();await p.locator('[name=code]').fill(code());await p.locator('button[type=submit]').click();await p.waitForSelector('#teamchat[data-online=true]');return p;
 }
 const message=(p,id)=>p.locator('[data-message="'+id+'"]');
 async function action(p,id,label){const n=message(p,id);await n.locator('summary').click();await n.getByRole('button',{name:label,exact:true}).click();}
 async function send(p,text){await p.locator('[data-body]').fill(text);await p.locator('[data-form] button[type=submit]').click();}
 async function find(p,c,body){const messages=(await api(p,'/conversations/'+c+'/messages')).body.data.messages;return messages.find(m=>m.body===body)?.id;}
 try{
  const a=await login('alice'),b=await login('bob'),other=await login('bob');check(true,'two accounts and three independent MFA device sessions');
  await a.locator('[data-search]').fill('Bruno');await a.locator('[data-list] [data-contact]').first().click();await a.locator('#teamchat.tc-selected').waitFor();
  const direct=(await api(a,'/conversations')).body.data.conversations.find(c=>c.type==='direct').id;
  await b.locator('[data-contact="'+direct+'"]').click();await other.locator('[data-contact="'+direct+'"]').click();
  await send(a,'Plan inicial de colaboración');
  await wait(()=>b.locator('.tc-message-body').filter({hasText:'Plan inicial de colaboración'}).count().then(v=>v===1),'text delivered through shared engine');
  const original=await find(a,direct,'Plan inicial de colaboración');
  await action(b,original,'Responder');await wait(()=>b.locator('[data-reply-draft]').isVisible(),'reply preview appears');
  check((await b.locator('[data-reply-draft]').innerText()).includes('Plan inicial'),'reply preview includes authorized fragment');
  await b.locator('[data-reply-draft] button').click();check(await b.locator('[data-reply-draft]').isHidden(),'reply cancellation');
  await action(b,original,'Responder');await wait(()=>b.locator('[data-reply-draft]').isVisible(),'second reply preview ready');await send(b,'Respuesta al plan');
  await wait(()=>a.locator('.tc-reply-reference').count().then(v=>v===1),'reply received by peer');
  const reply=await find(b,direct,'Respuesta al plan');
  await message(a,reply).locator('.tc-reply-reference').click();
  await wait(()=>message(a,original).evaluate(n=>n.classList.contains('tc-message-highlight')),'reply navigates to original');
  await action(b,reply,'Editar');await message(b,reply).locator('textarea').fill('Respuesta actualizada');await message(b,reply).getByRole('button',{name:'Guardar',exact:true}).click();
  await wait(()=>message(other,reply).locator('.tc-message-body').textContent().then(v=>v==='Respuesta actualizada'),'own edit synchronized to second device');
  await action(b,original,'Reaccionar');await b.locator('.tc-emoji-picker button').filter({hasText:'👍'}).click();
  await wait(()=>message(a,original).locator('.tc-reactions button').count().then(v=>v===1),'reaction synchronized to other account');
  await wait(()=>message(other,original).locator('.tc-reactions button[aria-pressed=true]').count().then(v=>v===1),'own reaction synchronized to second device');
  check((await message(a,original).locator('.tc-reactions button').getAttribute('title')).includes('Bruno'),'authorized reaction participant names');
  await action(a,original,'Editar');await message(a,original).locator('textarea').fill('Plan actualizado de colaboración');
  await message(a,original).getByRole('button',{name:'Guardar',exact:true}).click();
  await wait(()=>message(b,original).locator('.tc-message-body').textContent().then(v=>v==='Plan actualizado de colaboración'),'edit synchronized to peer');
  await wait(()=>message(b,reply).locator('.tc-reply-reference').textContent().then(v=>v.includes('Plan actualizado')),'reply reference refreshes edited original');
  check(await message(b,original).locator('.tc-edited').count()===1,'edited indicator visible');
  await action(a,original,'Fijar');await wait(()=>message(b,original).locator('.tc-pin-mark').count().then(v=>v===1),'pin synchronized');
  await b.locator('[data-show-pins]').click();await wait(()=>b.locator('[data-result-message="'+original+'"]').count().then(v=>v===1),'authorized pin list');
  await b.locator('[data-interaction-close]').click();
  await b.locator('[data-search-messages]').click();await b.locator('.tc-message-search input').fill('actualizado');await b.locator('.tc-message-search button').click();
  await wait(()=>b.locator('[data-result-message="'+original+'"]').count().then(v=>v===1),'fragment search returns authorized result');
  await b.locator('[data-result-message="'+original+'"]').click();
  await wait(()=>message(b,original).evaluate(n=>n.classList.contains('tc-message-highlight')),'search result navigates to message');
  await action(a,original,'Eliminar');await a.locator('[data-interaction-panel]').getByRole('button',{name:'Cancelar',exact:true}).click();
  check(await message(a,original).locator('.tc-message-body').textContent()==='Plan actualizado de colaboración','delete confirmation can be cancelled');
  await action(a,original,'Eliminar');await a.locator('[data-interaction-panel]').getByRole('button',{name:'Eliminar',exact:true}).click();
  await wait(()=>message(b,original).locator('.tc-message-body').textContent().then(v=>v==='Mensaje eliminado'),'soft deletion synchronized');
  await wait(()=>message(other,original).locator('.tc-message-body').textContent().then(v=>v==='Mensaje eliminado'),'soft deletion reaches second device');
  check((await message(b,reply).locator('.tc-reply-reference').textContent()).includes('Mensaje eliminado'),'reply neutralizes deleted original');
  check(await message(b,original).locator('.tc-reactions button').count()===0,'deleted message loses reactions');
  await b.locator('[data-show-pins]').click();await wait(()=>b.locator('[data-message-results]').count().then(v=>v===1),'pin panel refreshed');
  check(await b.locator('[data-result-message]').count()===0,'deleted message removed from pins');await b.locator('[data-interaction-close]').click();
  const group=(await api(a,'/spaces','POST',{kind:'group',command_id:'phase7-group',name:'Coordinación avanzada',description:'',members:[fixture.bob,fixture.carol]})).body.data.id;
  for(const p of [a,b,other]){await p.locator('[data-space-tab=group]').click();await p.locator('[data-contact="'+group+'"]').click();}
  await a.locator('[data-body]').fill('Consulta @Bru');
  await wait(()=>a.locator('[data-form] .tc-mention-options button').count().then(v=>v>0),'mention member suggestions');
  await a.locator('[data-body]').press('ArrowDown');await a.locator('[data-body]').press('Enter');
  check((await a.locator('[data-body]').inputValue()).includes('@Bruno Martínez'),'keyboard inserts readable mention');
  await a.locator('[data-form] button[type=submit]').click();
  await wait(()=>b.locator('.tc-message-body .tc-mention').count().then(v=>v===1),'mention rendered on receiving session');
  const mentioned=(await api(a,'/conversations/'+group+'/messages')).body.data.messages.find(m=>m.mentions.length).id;
  await wait(()=>b.locator('[data-mention-notice]').isVisible(),'shared mention notification shown');
  await b.locator('[data-mention-notice] button').first().click();
  await wait(()=>message(b,mentioned).evaluate(n=>n.classList.contains('tc-message-highlight')),'mention notification navigates to message');
  await message(b,mentioned).locator('summary').click();check(await message(b,mentioned).getByRole('button',{name:'Fijar',exact:true}).count()===0,'ordinary group member has no pin action');await message(b,mentioned).locator('summary').click();
  await b.context().setOffline(true);
  await action(a,mentioned,'Editar');await message(a,mentioned).locator('textarea').fill('Consulta actualizada @Bruno Martínez');await message(a,mentioned).getByRole('button',{name:'Guardar',exact:true}).click();
  await wait(()=>message(a,mentioned).locator('.tc-message-body').textContent().then(v=>v.includes('actualizada')),'edit persists while recipient offline');
  await b.context().setOffline(false);await wait(()=>message(b,mentioned).locator('.tc-message-body').textContent().then(v=>v.includes('actualizada')),'reconnect recovers edited state');
  const mentions=(await api(b,'/realtime/notifications')).body.data.filter(n=>n.type==='teamchat.message.mentioned');
  check(mentions.length===1,'editing retained mention does not duplicate notification');
  await a.locator('[data-file-input]').setInputFiles({name:'Acuerdo.txt',mimeType:'text/plain',buffer:Buffer.from('Documento privado para fijar y eliminar.')});
  await a.locator('[data-form] button[type=submit]').click();await wait(()=>b.locator('[data-attachment=document]').count().then(v=>v===1),'phase6 document still delivered');
  const document=(await api(a,'/conversations/'+group+'/messages')).body.data.messages.find(m=>m.attachments.some(f=>f.kind==='document'));
  await action(a,document.id,'Fijar');await wait(()=>message(b,document.id).locator('.tc-pin-mark').count().then(v=>v===1),'document can be pinned');
  const oldUrl=document.attachments[0].content_url;
  await action(a,document.id,'Eliminar');await a.locator('[data-interaction-panel]').getByRole('button',{name:'Eliminar',exact:true}).click();
  await wait(()=>message(b,document.id).locator('.tc-message-body').textContent().then(v=>v==='Mensaje eliminado'),'attachment message becomes tombstone');
  check((await b.request.get(base+oldUrl)).status()===404,'deleted attachment old URL denied');
  await a.screenshot({path:'.testing/teamchat-phase7-desktop.png',fullPage:true,animations:'disabled'});
  await a.setViewportSize({width:390,height:844});await wait(()=>a.locator('.mca-sidebar').evaluate(n=>n.getBoundingClientRect().right<=1),'mobile shell does not obscure conversation');
  await a.screenshot({path:'.testing/teamchat-phase7-mobile.png',fullPage:true,animations:'disabled'});
  check(await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile viewport has no horizontal overflow');
  await action(a,mentioned,'Responder');await wait(()=>a.locator('[data-reply-draft]').isVisible(),'mobile reply action usable');await a.locator('[data-reply-draft] button').click();
  check(frames.length>0,'real Reverb wake events observed');check(errors.length===0,'no uncaught JavaScript errors: '+errors.join(';'));
  console.log('TEAMCHAT PHASE 7 CHROME PASS '+checks);
 }catch(e){console.error(e);for(const context of browser.contexts())for(const p of context.pages()){console.error(await p.locator('[data-error]').textContent().catch(()=>''));await p.screenshot({path:'.testing/phase7-failure-'+browser.contexts().indexOf(context)+'.png',animations:'disabled'}).catch(()=>{});}process.exitCode=1;}
 finally{await browser.close();}
})();
