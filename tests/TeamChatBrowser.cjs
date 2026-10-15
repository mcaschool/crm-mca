// Real Chromium, normal login + TOTP, local fixture server only.
// Set PLAYWRIGHT_MODULE to an installed playwright package and TEAMCHAT_PHP to PHP.
// Run after TeamChatBrowserServer.php seed, with HTTP/Reverb/drain processes active.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const base = 'http://127.0.0.1:33319';
const php = process.env.TEAMCHAT_PHP || 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
const phpArgs = ['-n','-d','extension_dir=' + path.dirname(php) + '/ext', ...['mbstring','openssl','pdo_mysql','fileinfo','intl','gd','pdo_sqlite','curl'].flatMap(v=>['-d','extension='+v])];
const runPHP = mode => execFileSync(php,[...phpArgs,'tests/TeamChatBrowserServer.php',mode],{encoding:'utf8'}).trim();
let checks = 0;
function check(ok, label) { assert.ok(ok,label); checks++; console.log('PASS '+label); }
async function wait(fn, label, timeout=45000) {
    const start=Date.now();
    while(Date.now()-start<timeout){if(await fn()){check(true,label);return;}await new Promise(r=>setTimeout(r,150));}
    throw new Error('Timed out: '+label);
}
(async()=>{
    const browser=await chromium.launch({headless:true,channel:'chrome'});
    const errors=[], frames=[];
    function monitor(p){
        p.on('pageerror',e=>errors.push(e.message));
        p.on('websocket',ws=>ws.on('framereceived',f=>{if(String(f.payload).includes('collaboration.sync.required'))frames.push(String(f.payload));}));
    }
    async function login(context,who) {
        const p=await context.newPage();monitor(p);
        await p.goto(base+'/colaboracion');
        await p.locator('input[name=email]').fill(who+'@teamchat.invalid');
        await p.locator('input[name=password]').fill('LocalChat-Test-2026!');
        await p.locator('button[type=submit]').click();
        await p.locator('input[name=code]').fill(runPHP('code'));
        await p.locator('button[type=submit]').click();
        await p.waitForURL('**/colaboracion');
        await p.waitForSelector('#teamchat[data-online=true]');
        return p;
    }
    const a=await browser.newContext({viewport:{width:1440,height:1000}});
    let b=await browser.newContext({viewport:{width:1440,height:1000}});
    let alice,bob;
    try {
        alice=await login(a,'alice');bob=await login(b,'bob');
        check(true,'two independent MFA sessions subscribe to real Reverb');
        await alice.bringToFront();
        await alice.locator('[data-search]').fill('Bruno');
        await alice.locator('[data-list] .tc-contact').first().click();
        await alice.locator('[data-body]').fill('Hola Bruno, coordinemos la reunión de mañana.');
        await alice.locator('[data-body]').press('Enter');
        await wait(async()=> (await bob.locator('.tc-count').textContent().catch(()=>''))==='1','unread counter updates without reloading recipient');
        await bob.bringToFront();
        await bob.locator('[data-list] .tc-contact').first().click();
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'Hola Bruno'}).count()===1,'recipient gets persisted message through authorized API');
        await wait(async()=>await alice.locator('.tc-message-meta .read').count()===1,'visible recipient message becomes read on sender');
        await bob.locator('[data-body]').fill('Hola Ana. De acuerdo, nos vemos a las 10:00.');
        await bob.locator('[data-body]').press('Enter');
        await wait(async()=>await alice.locator('.tc-message-body').filter({hasText:'Hola Ana'}).count()===1,'bidirectional live messaging');
        await alice.bringToFront();
        await alice.locator('[data-body]').fill('Borrador que debe conservarse');
        await bob.locator('[data-body]').fill('El documento está listo.');
        await bob.locator('[data-body]').press('Enter');
        await wait(async()=>await alice.locator('.tc-message-body').filter({hasText:'El documento'}).count()===1,'new event arrives while composing');
        check(await alice.locator('[data-body]').inputValue()==='Borrador que debe conservarse','incoming messages preserve draft');
        await alice.locator('[data-body]').fill('<img src=x onerror="window.chatXss=true">');
        await alice.locator('[data-body]').press('Enter');
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'<img src=x'}).count()===1,'HTML arrives as literal text');
        check(await bob.evaluate(()=>!window.chatXss && document.querySelectorAll('[data-messages] img').length===0),'message text cannot execute HTML');
        await b.setOffline(true);
        await alice.locator('[data-body]').fill('Mensaje durante la desconexión');
        await alice.locator('[data-body]').press('Enter');
        await wait(async()=>await alice.locator('.tc-message-meta').filter({hasText:'Enviado'}).count()>0,'offline message is stored, not falsely delivered');
        await b.setOffline(false);await bob.bringToFront();
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'Mensaje durante la desconexión'}).count()===1,'reconnect recovers missed message exactly once',30000);
        await wait(async()=>await bob.locator('#teamchat[data-online=true]').count()===1,'private websocket resubscribes');
        await alice.bringToFront();
        await alice.route('**/conversations/*/messages',async route=>{
            if(route.request().method()==='POST'){return route.abort('failed');}
            return route.continue();
        });
        await alice.locator('[data-body]').fill('Reintento seguro');
        await alice.locator('[data-body]').press('Enter');
        await wait(async()=>await alice.locator('.tc-message-meta .failed').count()===1,'failed send is visible');
        await alice.unroute('**/conversations/*/messages');
        await alice.locator('.tc-message-meta button').click();
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'Reintento seguro'}).count()===1,'retry sends one message with original command id');
        const saved=await b.storageState();await b.close();b=await browser.newContext({storageState:saved,viewport:{width:1440,height:1000}});
        bob=await b.newPage();monitor(bob);await bob.goto(base+'/colaboracion');
        await bob.locator('[data-list] .tc-contact').first().click();
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'Reintento seguro'}).count()===1,'history survives closing and reopening browser context');
        // Domain fixture writes use an already valid registered session, not an HTTP bypass.
        console.log(runPHP('history'));
        await wait(async()=>await bob.locator('.tc-message-body').filter({hasText:'Historial 39'}).count()===1,'incremental synchronization catches a long history',30000);
        await bob.reload();await bob.locator('[data-list] .tc-contact').first().click();
        await bob.locator('[data-older]').waitFor({state:'visible'});
        const before=await bob.locator('.tc-message').count();
        await bob.locator('[data-older]').click();
        await wait(async()=>await bob.locator('.tc-message').count()>before,'older history is loaded into existing thread');
        const ids=await bob.locator('[data-message]').evaluateAll(ns=>ns.map(n=>n.dataset.message));
        check(new Set(ids).size===ids.length,'history and sync have no duplicated message nodes');
        await alice.bringToFront();await alice.screenshot({path:'.testing/teamchat-desktop.png',fullPage:true});
        await bob.setViewportSize({width:390,height:844});
        await bob.screenshot({path:'.testing/teamchat-mobile.png',fullPage:true});
        check(await bob.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile view has no horizontal page overflow');
        check(await bob.locator('[data-body]').isVisible(),'mobile composer remains visible');
        await bob.locator('[data-back]').click();
        check(await bob.locator('[data-search]').isVisible(),'mobile back returns to conversation list');
        await bob.locator('[data-list] .tc-contact').first().click();
        check(await bob.locator('[data-body]').isVisible(),'mobile conversation opens again');
        const denied=await alice.evaluate(async()=> (await fetch('/dashboard',{redirect:'manual'})).status);
        check(denied===403,'neutral collaborator cannot enter commercial CRM');
        check(frames.length>0 && frames.every(f=>!f.includes('Hola')&&!f.includes('Historial')),'real Reverb wakes contain no message bodies');
        const revoke=await bob.evaluate(async()=>{
            const headers={'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content};
            const me=await(await fetch('/api/v1/collaboration/me',{headers})).json();
            return (await fetch('/api/v1/collaboration/devices/'+me.data.device_id,{method:'DELETE',headers})).status;
        });
        check(revoke===200,'recipient revokes own device through Core');
        await bob.reload();
        check(await bob.locator('#teamchat').count()===0,'revoked device cannot reopen chat content');
        check(errors.length===0,'no uncaught JavaScript errors: '+errors.join(';'));
        console.log('TEAMCHAT BROWSER PASS '+checks+' checks');
    }catch(e){
        console.error(e);
        console.error('JS errors:',errors);
        for(const [name,p]of [['alice',alice],['bob',bob]])if(p&&!p.isClosed()){
            await p.screenshot({path:'.testing/teamchat-failure-'+name+'.png',fullPage:true}).catch(()=>{});
            console.error(name,await p.locator('body').innerText().catch(()=>''));
        }
        process.exitCode=1;
    }finally{await browser.close();}
})();
