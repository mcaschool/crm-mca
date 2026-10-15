const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'),assert=require('node:assert/strict');
const php=process.env.TEAMCHAT_PHP||'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl'].flatMap(v=>['-d','extension='+v])];
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function wait(fn,label){const end=Date.now()+45000;while(Date.now()<end){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,150));}throw Error(label);}
async function api(p,url,method='GET',data=null){return p.evaluate(async({url,method,data})=>{const r=await fetch('/api/v1/collaboration'+url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(data?{body:JSON.stringify(data)}:{})});if(!r.ok)throw Error('API '+r.status);return(await r.json()).data;},{url,method,data});}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
 async function login(who){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
 await p.goto('http://127.0.0.1:33319/colaboracion');await p.locator('[name=email]').fill(who+'@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');
 await p.locator('button[type=submit]').click();await p.locator('[name=code]').fill(execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim());await p.locator('button[type=submit]').click();
 await p.waitForURL('**/colaboracion');await p.waitForSelector('#teamchat[data-online=true]');return p;}
 try{
 const a=await login('alice'),b=await login('bob'),team=(await api(a,'/teams')).teams.find(t=>t.name==='Equipo de aprendizaje');
 const channel=(await api(a,'/teams/'+team.id+'/channels')).channels.find(c=>c.name==='Proyecto bilingüe');
 for(const p of [a,b]){await p.locator('[data-space-tab=team]').click();await p.locator('[data-team="'+team.id+'"]').click();}
 await b.locator('[data-channel="'+team.general_id+'"]').click();await b.bringToFront();
 const before=(await api(b,'/conversations/'+channel.id)).unread_count;
 const sent=await api(a,'/conversations/'+channel.id+'/messages','POST',{command_id:'tree-'+Date.now(),body:'Actualización del árbol y recibos compartidos'});
 await wait(async()=>await b.locator('[data-channel="'+channel.id+'"]').textContent().then(v=>v.endsWith(' · '+(before+1))),'expanded team tree updates unread count from message event');
 await a.locator('[data-channel="'+channel.id+'"]').click();await b.locator('[data-channel="'+channel.id+'"]').click();await b.bringToFront();
 await wait(async()=>await a.locator('[data-message="'+sent.id+'"]').textContent().then(v=>v.includes('Leído por 1')),'sender sees actual channel read recipient count');
 await wait(async()=>await b.locator('[data-channel="'+channel.id+'"]').textContent().then(v=>!v.includes(' · ')),'reading clears unread counter in team tree');
 await b.locator('[data-manage]').click();await b.waitForSelector('[data-space-dialog] input[name=name]');
 check(await b.locator('[data-space-dialog] input[name=name]').isDisabled(),'ordinary channel member cannot edit metadata');
 await b.locator('.tc-dialog-header button').click();
 await a.bringToFront();await a.screenshot({path:'.testing/teamchat-phase5-teams.png',animations:'disabled'});
 await a.setViewportSize({width:390,height:844});await a.screenshot({path:'.testing/teamchat-phase5-channel-mobile.png',animations:'disabled'});
 check(await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'channel receipt counts fit mobile viewport');
 await a.locator('[data-back]').click();await a.locator('.tc-team').filter({hasText:'Equipo de aprendizaje'}).getByRole('button',{name:'Administrar',exact:true}).click();
 await a.getByRole('button',{name:'Crear canal',exact:true}).click();await a.waitForSelector('[name=visibility]');
 await a.locator('.tc-picker').waitFor({state:'attached'});
 check(await a.locator('.tc-picker').isHidden(),'standard channel explains inherited membership without false selection');
 await a.locator('[name=visibility]').selectOption('private');
 await wait(async()=>await a.locator('.tc-picker').isVisible(),'private channel exposes explicit participant selection');
 check(errors.length===0,'final assets have no uncaught JavaScript errors');
 console.log('TEAMCHAT TREE BROWSER PASS '+checks+' checks');
 }catch(e){console.error(e);console.error(errors);for(const context of browser.contexts())for(const page of context.pages())console.error(await page.evaluate(()=>({visibility:document.querySelector('[name=visibility]')?.value,pickers:[...document.querySelectorAll('.tc-picker')].map(n=>({hidden:n.hidden,rect:n.getBoundingClientRect().toJSON()})),dialog:document.querySelector('[data-space-dialog]')?.textContent})));process.exitCode=1;}finally{await browser.close();}
})();
