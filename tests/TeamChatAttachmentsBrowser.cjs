// Real Chrome + real HTTP/MySQL/Reverb. Microphone capture is simulated by Chrome, not physical hardware.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const fixture=JSON.parse(fs.readFileSync('.testing/teamchat-browser.json','utf8'));
const php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl','zip'].flatMap(v=>['-d','extension='+v])];
const code=()=>execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim();
const base='http://127.0.0.1:33319';let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function wait(fn,label,timeout=45000){const until=Date.now()+timeout;while(Date.now()<until){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,150));}throw Error('Timeout: '+label);}
async function api(p,url,method='GET',body=null){return p.evaluate(async({url,method,body})=>{const r=await fetch('/api/v1/collaboration'+url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{url,method,body});}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true,args:['--use-fake-device-for-media-stream']});
 const errors=[],frames=[];
 async function login(who){
  const ctx=await browser.newContext({permissions:['microphone'],viewport:{width:1440,height:1000}}),p=await ctx.newPage();
  p.on('pageerror',e=>errors.push(e.message));p.on('websocket',s=>s.on('framereceived',f=>{if(String(f.payload).includes('collaboration.sync.required'))frames.push(String(f.payload));}));
  await p.goto(base+'/colaboracion');await p.locator('[name=email]').fill(who+'@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');await p.locator('button[type=submit]').click();
  await p.locator('[name=code]').fill(code());await p.locator('button[type=submit]').click();await p.waitForSelector('#teamchat[data-online=true]');return p;
 }
 async function submit(p){await p.locator('[data-form] button[type=submit]').click();}
 async function doc(p,name='Plan.txt'){
  await p.locator('[data-file-input]').setInputFiles({name,mimeType:'text/plain',buffer:Buffer.from('Documento privado de coordinación MCA.\nSólo participantes autorizados.')});
  await submit(p);
 }
 try{
  const a=await login('alice'),b=await login('bob');
  check(true,'two real institutional login and MFA sessions');
  await a.locator('[data-search]').fill('Bruno');await a.locator('[data-list] [data-contact]').first().click();
  const direct=(await api(a,'/conversations')).body.data.conversations.find(c=>c.type==='direct').id;
  await b.locator('[data-contact="'+direct+'"]').click();
  await a.locator('[data-record]').click();await wait(()=>a.locator('[data-record-cancel]').isVisible(),'microphone capture starts');
  await new Promise(r=>setTimeout(r,700));await a.locator('[data-record-cancel]').click();
  check(await a.locator('[data-file-draft]').count()===0,'recording cancelled before upload');
  await a.locator('[data-record]').click();await new Promise(r=>setTimeout(r,1600));await a.locator('[data-record]').click();
  await wait(()=>a.locator('[data-file-draft] audio').count().then(v=>v===1),'recorded voice preview');
  await submit(a);
  await wait(()=>b.locator('[data-attachment=voice] audio').count().then(v=>v===1),'other account receives voice without navigation');
  const audio=b.locator('[data-attachment=voice] audio').first();
  await audio.evaluate(n=>n.play());
  await wait(()=>audio.evaluate(n=>n.currentTime>0&&!n.error),'real browser decodes and plays normalized voice');
  await b.locator('.tc-speed').first().selectOption('1.5');check(await audio.evaluate(n=>n.playbackRate)===1.5,'playback speed control');
  await audio.evaluate(n=>n.pause());
  const png=await a.evaluate(()=>{const c=document.createElement('canvas');c.width=640;c.height=360;const x=c.getContext('2d');x.fillStyle='#155e75';x.fillRect(0,0,640,360);x.fillStyle='white';x.font='36px sans-serif';x.fillText('MCA · Imagen privada',40,180);return c.toDataURL('image/png').split(',')[1];});
  await a.locator('[data-image-input]').setInputFiles({name:'imagen.png',mimeType:'image/png',buffer:Buffer.from(png,'base64')});
  check(await a.locator('[data-file-draft] img').count()===1,'image preview before sending');
  await b.context().setOffline(true);await submit(a);
  await wait(()=>a.locator('[data-message][data-status=sent] [data-attachment=image]').count().then(v=>v>0),'image persists while recipient disconnected');
  await b.context().setOffline(false);
  await wait(()=>b.locator('[data-message] .tc-message-image').count().then(v=>v===1),'reconnection recovers authorized image history');
  await wait(()=>b.locator('.tc-message-image').evaluate(n=>n.complete&&n.naturalWidth>0&&n.getBoundingClientRect().width>0),'received image actually decodes and renders');
  await b.locator('.tc-image-button').click();check(await b.locator('[data-image-viewer]').isVisible(),'enlarged image viewer');await b.locator('[data-viewer-close]').click();
  await doc(a);
  await wait(()=>b.locator('[data-attachment=document]').count().then(v=>v===1),'document received through shared realtime engine');
  const downloadPromise=b.waitForEvent('download');await b.locator('[data-attachment=document] a').click();const download=await downloadPromise;
  check(download.suggestedFilename()==='Plan.txt','authorized document download preserves name');
  const downloaded=await download.path();check(fs.readFileSync(downloaded,'utf8').includes('Documento privado'),'downloaded bytes match source');
  const second=await login('bob');
  const recovered=(await api(second,'/conversations/'+direct+'/messages')).body.data.messages.flatMap(m=>m.attachments);
  check(['voice','image','document'].every(kind=>recovered.some(f=>f.kind===kind)),'second registered device recovers all attachment kinds');
  check((await second.request.get(base+recovered.find(f=>f.kind==='document').content_url)).status()===200,'second authorized device downloads the document');
  await second.context().close();
  let interrupted=true;
  await a.route('**/api/v1/collaboration/uploads/*/content',async route=>{if(interrupted&&route.request().method()==='POST'){interrupted=false;await route.abort();}else await route.continue();});
  await doc(a,'Reintento.txt');
  await wait(()=>a.locator('[data-message][data-status=failed]').count().then(v=>v===1),'failed transfer exposes retry');
  await a.locator('[data-message][data-status=failed] button').click();
  await wait(()=>b.locator('[data-attachment=document]').count().then(v=>v===2),'retry sends once');
  const all=(await api(a,'/conversations/'+direct+'/messages')).body.data.messages;
  check(all.filter(m=>m.attachments?.some(f=>f.name==='Reintento.txt')).length===1,'retry did not duplicate message');
  await a.unroute('**/api/v1/collaboration/uploads/*/content');
  const group=(await api(a,'/spaces','POST',{kind:'group',command_id:'browser-attachments-group',name:'Archivos del equipo',description:'',members:[fixture.bob,fixture.carol]}));
  check(group.status===200,'group prepared with existing API');
  const groupId=group.body.data.id;
  await a.locator('[data-space-tab=group]').click();await b.locator('[data-space-tab=group]').click();
  await a.locator('[data-contact="'+groupId+'"]').click();await b.locator('[data-contact="'+groupId+'"]').click();
  await doc(a,'Grupo.txt');await wait(()=>b.locator('[data-attachment=document]').count().then(v=>v===1),'group attachment received');
  const teamResult=await api(a,'/spaces','POST',{kind:'team',command_id:'browser-attachments-team',name:'Recursos',description:'',members:[fixture.bob]});
  const team=teamResult.body.data.id;
  const standard=(await api(a,'/teams/'+team+'/channels')).body.data.channels[0].id;
  const privateResult=await api(a,'/spaces','POST',{kind:'channel',command_id:'browser-attachments-private',name:'Privado',description:'',members:[fixture.bob],team_id:team,visibility:'private'});
  const privateId=privateResult.body.data.id;
  for(const [id,name] of [[standard,'Estándar.txt'],[privateId,'Privado.txt']]){
   await a.locator('[data-space-tab=channel]').click();await b.locator('[data-space-tab=channel]').click();
   await a.locator('[data-contact="'+id+'"]').click();await b.locator('[data-contact="'+id+'"]').click();
   await doc(a,name);await wait(()=>b.locator('[data-attachment=document]').count().then(v=>v===1),'channel attachment '+name);
  }
  await a.locator('[data-space-tab=direct]').click();await a.locator('[data-contact="'+direct+'"]').click();
  await wait(()=>a.locator('[data-attachment=voice]').count().then(v=>v===1),'attachment history restored after switching spaces');
  await wait(()=>a.locator('.tc-message-image').evaluate(n=>n.complete&&n.naturalWidth>0&&n.getBoundingClientRect().width>0),'restored image actually renders');
  await wait(()=>a.locator('[data-attachment=voice] audio').evaluate(n=>Number.isFinite(n.duration)&&n.duration>0),'restored audio loads duration');
  await a.screenshot({path:'.testing/teamchat-phase6-desktop.png',fullPage:true,animations:'disabled'});
  await a.setViewportSize({width:390,height:844});
  await wait(()=>a.locator('.mca-sidebar').evaluate(n=>n.getBoundingClientRect().right<=1),'mobile navigation does not cover the conversation');
  await a.screenshot({path:'.testing/teamchat-phase6-mobile.png',fullPage:true,animations:'disabled'});
  check(await a.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile view has no horizontal overflow');
  await a.evaluate(()=>{navigator.mediaDevices.getUserMedia=()=>Promise.reject(new DOMException('Test denial','NotAllowedError'));});
  await a.locator('[data-record]').click();
  await wait(()=>a.locator('[data-file-error]').textContent().then(v=>v.includes('micrófono')),'permission denial handled (simulated rejection)');
  const privateMessages=(await api(b,'/conversations/'+privateId+'/messages')).body.data.messages;
  const oldUrl=privateMessages[0].attachments[0].content_url;
  await api(a,'/spaces/conversation/'+privateId+'/members','POST',{command_id:'remove-browser-bob',user_id:fixture.bob,operation:'remove'});
  check((await b.request.get(base+oldUrl)).status()===404,'old download URL denied after expulsion');
  check(frames.length>0,'real Reverb wake frames observed');
  check(errors.length===0,'no uncaught JavaScript errors: '+errors.join(';'));
  console.log('TEAMCHAT PHASE 6 CHROME PASS '+checks);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
