const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process'),assert=require('node:assert/strict');
const php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe',args=['-n','-d','extension_dir=C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl','zip'].flatMap(v=>['-d','extension='+v])];
const base='http://127.0.0.1:33319';let checks=0;
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS '+label);}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
  const context=await browser.newContext(),page=await context.newPage(),requests=[];
  page.on('request',r=>{if(r.url().includes('/api/v1/collaboration'))requests.push(r.allHeaders().catch(()=>({unavailable:true})));});
  await page.goto(base+'/profile');await page.locator('[name=email]').fill('diana@teamchat.invalid');await page.locator('[name=password]').fill('LocalChat-Test-2026!');
  await page.locator('button[type=submit]').click();await page.locator('[name=code]').fill(execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim());await page.locator('button[type=submit]').click();
  await page.goto(base+'/profile');await page.locator('[data-mca-collaboration]').waitFor();
  check(await page.locator('[data-mca-collaboration]').count()===1,'CRM profile includes widget for member without commercial role');
  await page.locator('[data-mca-collaboration] button').first().click();
  const frame=page.frames().find(f=>f.url().includes('/widget/frame'));
  await frame.waitForSelector('#teamchat[data-online=true]',{timeout:90000});check(true,'same-origin CRM connects with central MFA and CSRF automatically');
  const headers=await Promise.all(requests);check(headers.some(h=>h.authorization)&&!headers.some(h=>h.unavailable||(h.authorization&&h.cookie)),'wire headers confirm delegated requests omit cookies even in first-party CRM');
  const noCsrf=await context.request.post(base+'/collaboration/widget/authorize',{headers:{Accept:'application/json',Origin:'https://evil.invalid'},data:{app:'local-crm',origin:base,challenge:'a'.repeat(43),state:'b'.repeat(32)}});
  check([403,419].includes(noCsrf.status()),'ordinary cookie authorization rejects forged origin without CSRF');
  const cfg=await context.request.get(base+'/api/v1/collaboration/widget/config?app=local-crm&origin=https://evil.invalid');
  check(cfg.status()===403,'unregistered embedding origin rejected');
  const denied=await context.request.post(base+'/api/v1/collaboration/widget/exchange',{headers:{Origin:'https://evil.invalid',Accept:'application/json'},data:{app:'local-crm',origin:base,code:'a'.repeat(64),verifier:'a'.repeat(64)}});
  check(denied.status()===403,'exchange rejects noncentral origin');
  await page.goto(base+'/colaboracion');await page.waitForSelector('#teamchat[data-online=true]');
  check(await page.locator('[data-mca-collaboration]').count()===0,'central full screen has no duplicate widget');
  const anonymous=await browser.newContext(),anon=await anonymous.newPage();await anon.goto(base+'/login');
  check(await anon.locator('[data-mca-collaboration]').count()===0,'anonymous login has no collaboration widget');
  console.log('TEAMCHAT WIDGET SECURITY CHROME PASS '+checks);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
