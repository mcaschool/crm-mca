const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const fixture=JSON.parse(fs.readFileSync('.testing/teamchat-browser.json','utf8'));
const php=process.env.TEAMCHAT_PHP||'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl'].flatMap(v=>['-d','extension='+v])];
const code=()=>execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim();
const base='http://127.0.0.1:33319';let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function wait(fn,label,timeout=45000){const until=Date.now()+timeout;while(Date.now()<until){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,200));}throw Error('Timeout: '+label);}
async function api(page,url,method='GET',body=null){return page.evaluate(async({url,method,body})=>{const r=await fetch('/api/v1/collaboration'+url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{url,method,body});}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[],frames=[];
 async function login(who){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();
  p.on('pageerror',e=>errors.push(e.message));p.on('dialog',d=>d.accept());
  p.on('websocket',w=>w.on('framereceived',f=>{if(String(f.payload).includes('collaboration.sync.required'))frames.push(String(f.payload));}));
  await p.goto(base+'/colaboracion');await p.locator('[name=email]').fill(who+'@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');
  await p.locator('button[type=submit]').click();await p.locator('[name=code]').fill(code());await p.locator('button[type=submit]').click();
  await p.waitForURL('**/colaboracion');await p.waitForSelector('#teamchat[data-online=true]');return p;
 }
 async function send(p,text){await p.bringToFront();await p.locator('[data-body]').fill(text);await p.locator('[data-body]').press('Enter');await wait(async()=>await p.locator('.tc-message-body').filter({hasText:text}).count()===1,'sender persists '+text);}
 async function pick(p,id){await p.locator('[data-pick-user="'+id+'"]').click();}
 async function submit(p){await p.locator('[data-space-dialog] button[type=submit]').click();await p.waitForFunction(()=>!document.querySelector('[data-space-dialog]').open);}
 async function close(p){await p.locator('.tc-dialog-header button').click();}
 try{
  const a=await login('alice'),b=await login('bob'),c=await login('carol'),d=await login('diana');
  check(true,'four independent normal login and MFA sessions');
  await a.locator('[data-create-group]').click();await a.locator('[data-space-dialog] [name=name]').fill('Coordinación académica');
  await pick(a,fixture.bob);await pick(a,fixture.carol);await submit(a);
  const groups=(await api(a,'/conversations')).body.data.conversations;const group=groups.find(g=>g.type==='group').id;
  check(true,'group created through UI with owner and two participants');
  for(const p of [b,c]){await p.locator('[data-space-tab=group]').click();await p.locator('[data-contact="'+group+'"]').click();}
  await send(a,'Plan de trabajo del equipo');
  await wait(async()=>await b.locator('.tc-message-body').filter({hasText:'Plan de trabajo'}).count()===1&&await c.locator('.tc-message-body').filter({hasText:'Plan de trabajo'}).count()===1,'two recipients receive real group events');
  await send(b,'Bruno confirma el cronograma');await send(c,'Carla revisará los contenidos');
  await wait(async()=>await a.locator('.tc-message-body').count()===3,'three accounts share one persistent message history');
  check(await a.locator('.tc-sender').filter({hasText:'Carla Torres'}).count()===1,'sender name and avatar shown in group');
  await a.locator('[data-manage]').click();await pick(a,fixture.diana);await a.getByRole('button',{name:'Agregar seleccionados',exact:true}).click();
  await wait(async()=>await a.locator('[data-member="'+fixture.diana+'"]').count()===1,'late member added through management UI');await close(a);
  check((await api(d,'/conversations/'+group+'/messages')).body.data.messages.length===0,'late member cannot read pre-join history');
  await d.locator('[data-space-tab=group]').click();await d.locator('[data-contact="'+group+'"]').click();
  await send(a,'Bienvenida Diana al nuevo periodo');
  await wait(async()=>await d.locator('.tc-message-body').count()===1,'late member receives only authorized new message');
  await a.locator('[data-manage]').click();await a.locator('[data-member="'+fixture.carol+'"]').getByRole('button',{name:'Hacer administrador',exact:true}).click();
  await wait(async()=>await a.locator('[data-member="'+fixture.carol+'"]').textContent().then(v=>v.includes('Administrador')),'owner changes administrator through UI');
  await a.locator('[data-member="'+fixture.bob+'"]').getByRole('button',{name:'Retirar',exact:true}).click();
  await wait(async()=>await a.locator('[data-member="'+fixture.bob+'"]').count()===0,'owner expels participant through UI');await close(a);
  check((await api(b,'/conversations/'+group+'/messages')).status===404,'expelled member loses protected API immediately');
  await wait(async()=>await b.locator('[data-thread]').isHidden(),'existing subscription revalidates and clears expelled thread');
  await a.locator('[data-create-team]').click();await a.locator('[data-space-dialog] [name=name]').fill('Equipo de aprendizaje');await pick(a,fixture.bob);await pick(a,fixture.carol);await submit(a);
  const team=(await api(a,'/teams')).body.data.teams.find(v=>v.name==='Equipo de aprendizaje');
  check(!!team.general_id,'team UI creation supplies exactly one default General');
  await a.locator('.tc-team').filter({hasText:'Equipo de aprendizaje'}).getByRole('button',{name:'Administrar',exact:true}).click();
  await a.getByRole('button',{name:'Crear canal',exact:true}).click();await a.locator('[data-space-dialog] [name=name]').fill('Proyecto bilingüe');
  await submit(a);const channels=(await api(a,'/teams/'+team.id+'/channels')).body.data.channels;const standard=channels.find(v=>v.name==='Proyecto bilingüe').id;
  await send(a,'Coordinemos el proyecto institucional');
  await b.locator('[data-space-tab=team]').click();await b.locator('[data-team="'+team.id+'"]').click();await b.locator('[data-channel="'+standard+'"]').click();
  await wait(async()=>await b.locator('.tc-message-body').filter({hasText:'Coordinemos el proyecto'}).count()===1,'standard channel inherits team membership');
  await a.locator('[data-space-tab=team]').click();await a.locator('.tc-team').filter({hasText:'Equipo de aprendizaje'}).getByRole('button',{name:'Administrar',exact:true}).click();
  await a.getByRole('button',{name:'Crear canal',exact:true}).click();await a.locator('[data-space-dialog] [name=name]').fill('Comité privado');
  await a.locator('[name=visibility]').selectOption('private');await pick(a,fixture.carol);await submit(a);
  const privateId=(await api(a,'/teams/'+team.id+'/channels')).body.data.channels.find(v=>v.name==='Comité privado').id;
  await send(a,'Revisión privada del comité');
  check((await api(b,'/conversations/'+privateId)).status===404,'team membership cannot read private channel');
  check(!(await api(b,'/teams/'+team.id+'/channels')).body.data.channels.some(v=>v.id===privateId),'private channel hidden in team listing');
  await c.locator('[data-space-tab=channel]').click();await c.locator('[data-contact="'+privateId+'"]').click();
  await wait(async()=>await c.locator('.tc-message-body').filter({hasText:'Revisión privada'}).count()===1,'explicit private participant receives message');
  await c.context().setOffline(true);await send(a,'Mensaje durante reconexión');await c.context().setOffline(false);
  await wait(async()=>await c.locator('.tc-message-body').filter({hasText:'Mensaje durante reconexión'}).count()===1,'private channel reconnect recovers once');
  await a.locator('[data-space-tab=group]').click();await a.locator('[data-contact="'+group+'"]').click();await a.locator('[data-body]').fill('Borrador conservado');
  await a.locator('[data-space-tab=channel]').click();await a.locator('[data-contact="'+privateId+'"]').click();await a.locator('[data-space-tab=group]').click();await a.locator('[data-contact="'+group+'"]').click();
  check(await a.locator('[data-body]').inputValue()==='Borrador conservado','switching spaces preserves drafts');
  await wait(async()=>await a.locator('.tc-message-body').count()===4,'group history restored after navigation before visual capture');
  await a.screenshot({path:'.testing/teamchat-phase5-desktop.png',animations:'disabled'});
  await a.setViewportSize({width:390,height:844});await a.screenshot({path:'.testing/teamchat-phase5-mobile.png',animations:'disabled'});
  check(await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile viewport has no horizontal overflow');
  await a.locator('[data-manage]').click();check(await a.locator('[data-space-dialog]').isVisible(),'mobile management dialog accessible');
  await wait(async()=>await a.locator('[data-space-dialog] [data-member]').count()===3,'mobile management has loaded real members');
  await a.screenshot({path:'.testing/teamchat-phase5-mobile-manage.png',animations:'disabled'});await close(a);
  check(frames.length>0&&frames.every(v=>!v.includes('Revisión privada')),'real Reverb carries only minimal wake signals');
  check(errors.length===0,'no uncaught JavaScript errors');
  console.log('TEAMCHAT SPACES BROWSER PASS '+checks+' checks');
 }catch(e){console.error(e);console.error(errors);for(const context of browser.contexts())for(const p of context.pages())console.error(await p.locator('[data-space-dialog]').textContent().catch(()=>''));process.exitCode=1;}
 finally{await browser.close();}
})();
