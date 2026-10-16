// Companion real-browser checks: independent devices, preference enforcement,
// access revocation, and denied browser requests after revocation.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const base='http://127.0.0.1:33319',php=process.env.TEAMCHAT_PHP||'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const args=['-n','-d','extension_dir='+path.dirname(php)+'/ext',...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl'].flatMap(x=>['-d','extension='+x])];
let checks=0;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function wait(fn,label){const start=Date.now();while(Date.now()-start<60000){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,300));}throw Error(label);}
async function api(page,url,method='GET',data=null) {
    return page.evaluate(async({url,method,data})=>{
        const response=await fetch('/api/v1/collaboration'+url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(data===null?{}:{body:JSON.stringify(data)})});
        return {status:response.status,body:await response.json()};
    },{url,method,data});
}
(async()=>{
    const browser=await chromium.launch({channel:'chrome',headless:true}),errors=[];
    async function login(who){
        const c=await browser.newContext({viewport:{width:1366,height:900}}),p=await c.newPage();
        p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/colaboracion');
        await p.locator('[name=email]').fill(who+'@teamchat.invalid');await p.locator('[name=password]').fill('LocalChat-Test-2026!');
        await p.locator('button[type=submit]').click();
        await p.locator('[name=code]').fill(execFileSync(php,[...args,'tests/TeamChatBrowserServer.php','code'],{encoding:'utf8'}).trim());
        await p.locator('button[type=submit]').click();await p.waitForURL('**/colaboracion');await p.waitForSelector('#teamchat[data-online=true]');
        return p;
    }
    try{
        const alice=await login('alice'),one=await login('bob'),two=await login('bob');
        const d1=(await api(one,'/me')).body.data.device_id,d2=(await api(two,'/me')).body.data.device_id;
        check(d1!==d2,'same user has distinct registered devices and valid MFA sessions');
        const conv=(await api(alice,'/conversations')).body.data.conversations[0].id;
        const sent=await api(alice,'/conversations/'+conv+'/messages','POST',{command_id:'devices-'+Date.now(),body:'Lectura entre dispositivos autorizados'});
        check(sent.status===200,'message accepted from authenticated sender');
        await one.bringToFront();await one.locator('[data-list] .tc-contact').first().click();
        await wait(async()=>await one.locator('[data-message="'+sent.body.data.id+'"]').count()===1,'first device receives the message');
        await wait(async()=> (await api(two,'/conversations/'+conv+'/messages?ids[]='+sent.body.data.id)).body.data.messages[0].status==='read','second device observes the read receipt without opening the conversation');
        await api(two,'/notification-preferences','PUT',{enabled:false});await alice.bringToFront();
        const muted=await api(alice,'/conversations/'+conv+'/messages','POST',{command_id:'muted-'+Date.now(),body:'Mensaje con avisos silenciados'});
        check(muted.status===200,'muted preferences do not prevent messages');
        await wait(async()=>await two.locator('.tc-preview').filter({hasText:'Mensaje con avisos silenciados'}).count()===1,'muted message still updates conversation preview');
        check((await api(two,'/realtime/notifications')).body.data.length===0,'shared preferences suppress notification intents in web inbox');
        await api(two,'/notification-preferences','PUT',{enabled:true});
        check((await api(two,'/devices/'+d1,'DELETE')).status===200,'one valid device revokes another through shared registry');
        const blocked=await api(one,'/conversations/'+conv+'/messages');
        check([401,403].includes(blocked.status)&&['unauthenticated','session_revoked','device_revoked'].includes(blocked.body.error?.code)&&!blocked.body.data,'revoked device cannot retrieve history');
        await wait(async()=>await one.locator('[data-thread]').isHidden(),'revoked session clears visible conversation on revalidation');
        check(errors.length===0,'no uncaught JavaScript errors');
        console.log('TEAMCHAT DEVICES BROWSER PASS '+checks+' checks');
    }catch(e){console.error(e);console.error(errors);process.exitCode=1;}
    finally{await browser.close();}
})();
