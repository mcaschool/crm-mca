const { chromium }=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'), assert=require('node:assert/strict');
const php=process.env.TEAMCHAT_PHP||'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl'].flatMap(x=>['-d','extension='+x])];
const base='http://127.0.0.1:33319';
let count=0;
const check=(ok,text)=>{assert.ok(ok,text);count++;console.log('PASS '+text);};
async function wait(fn,text){let end=Date.now()+45000;while(Date.now()<end){if(await fn()){check(true,text);return;}await new Promise(r=>setTimeout(r,150));}throw Error(text);}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}),context=await browser.newContext({viewport:{width:390,height:844}}),p=await context.newPage(),errors=[];
 p.on('pageerror',e=>errors.push(e.message));
 try {
  await p.goto(base+'/colaboracion');await p.locator('[name=email]').fill('alice@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');
  await p.locator('button[type=submit]').click();await p.locator('[name=code]').fill(execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim());
  await p.locator('button[type=submit]').click();await p.waitForURL('**/colaboracion');
  await p.locator('[data-search]').fill('Bruno');
  await p.locator('[data-list] .tc-contact').first().click();
  console.log(execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','history'],{encoding:'utf8'}).trim());
  await p.reload();
  await p.locator('[data-list] .tc-contact').first().click();
  await wait(async()=>await p.locator('.tc-message').count()>=30,'initial history stays intact across bootstrap and selection');
  check(await p.locator('.mca-sidebar').evaluate(n=>n.getBoundingClientRect().right<=1),'CRM navigation does not cover mobile conversation');
  const input=p.locator('[data-body]');await input.fill('Primera línea');await input.press('Shift+Enter');await input.type('Segunda línea');
  check((await input.inputValue()).includes('\n'),'Shift+Enter preserves a line break without sending');
  await p.route('**/conversations/*/messages',r=>r.request().method()==='POST'?r.abort('failed'):r.continue());
  const text='Validación móvil de reintento '+Date.now();await input.fill(text);await input.press('Enter');
  await wait(async()=>await p.locator('.tc-message-meta .failed').count()===1,'mobile send failure exposes safe retry');
  await p.unroute('**/conversations/*/messages');await p.locator('.tc-message-meta button').click();
  await wait(async()=>/Enviado|Entregado|Leído/.test(await p.locator('.tc-message').filter({hasText:text}).locator('.tc-message-meta').innerText()),'mobile retry persists the message');
  check(await p.locator('.tc-message').count()>=31,'retry preserves already loaded history');
  check(await p.locator('[data-error]').isHidden(),'successful retry dismisses stale error banner');
  check(await p.locator('.tc-message-body').filter({hasText:text}).count()===1,'retry has one visible message');
  await p.screenshot({path:'.testing/teamchat-mobile.png',fullPage:true,animations:'disabled'});
  check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile layout fits viewport');
  const box=await input.boundingBox();check(box.y+box.height<844,'composer fits above viewport bottom');
  await p.setViewportSize({width:1440,height:1000});await p.screenshot({path:'.testing/teamchat-desktop.png',fullPage:true,animations:'disabled'});
  await p.locator('[data-older]').click();
  check(await input.inputValue()==='','history load does not overwrite composer');
  check(errors.length===0,'layout and retry have no uncaught JavaScript errors');
  console.log('TEAMCHAT LAYOUT BROWSER PASS '+count+' checks');
 }catch(e){console.error(e);console.error(errors);await p.screenshot({path:'.testing/teamchat-layout-failure.png',fullPage:true,animations:'disabled'});process.exitCode=1;}
 finally{await browser.close();}
})();
