/* MCA Collaboration Widget v1 — public configuration only; credentials stay in the central iframe. */
(function(){
'use strict';
if(window.MCACollaboration)return;
const script=document.currentScript,central=new URL(script.src).origin;
let current=null;
function mount(options={}){
 if(current)return current;
 const app=options.app||script.dataset.app;if(!app)throw Error('MCA application ID required');
 const lang=options.locale||document.documentElement.lang||'es',en=lang.startsWith('en'),channel=crypto.randomUUID();
 const container=document.createElement('div');container.setAttribute('data-mca-collaboration','');
 const shadow=container.attachShadow({mode:'open'}),style=document.createElement('style');
 style.textContent=':host{all:initial;position:fixed;bottom:18px;right:18px;z-index:2147483000;font-family:system-ui,sans-serif}button{cursor:pointer;background:#1e5aa8;color:white;border:0;border-radius:50%;height:58px;width:58px;font-size:23px;box-shadow:0 4px 18px #10203944}button:focus-visible{outline:3px solid #c9a84c;outline-offset:3px}.badge{position:absolute;right:-3px;top:-5px;background:#b52b37;color:white;border-radius:20px;font:700 12px system-ui;padding:4px;min-width:16px;text-align:center}iframe{position:absolute;bottom:70px;right:0;width:min(440px,calc(100vw - 36px));height:min(720px,calc(100dvh - 110px));border:1px solid #dce5f0;border-radius:16px;background:white;box-shadow:0 8px 40px #10203944}[hidden]{display:none!important}:host(.left){left:18px;right:auto}:host(.left) iframe{left:0;right:auto}@media(max-width:600px){iframe{position:fixed;inset:8px;width:calc(100vw - 16px);height:calc(100dvh - 16px)}}';
 const bubble=document.createElement('button');bubble.type='button';bubble.textContent='◌';bubble.setAttribute('aria-label',en?'Open Collaboration':'Abrir Colaboración');bubble.setAttribute('aria-expanded','false');
 const badge=document.createElement('span');badge.className='badge';badge.hidden=true;bubble.append(badge);
 const frame=document.createElement('iframe');frame.title='MCA Colaboración';frame.hidden=true;
 frame.allow='microphone '+central;frame.referrerPolicy='no-referrer';
 frame.setAttribute('sandbox','allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads');
 frame.src=central+'/collaboration/widget/frame?'+new URLSearchParams({app,origin:location.origin,locale:en?'en':'es',channel});
 const notice=document.createElement('button');notice.hidden=true;notice.style.cssText='position:absolute;bottom:72px;right:0;width:230px;height:auto;padding:12px;border-radius:10px;font:14px system-ui';notice.textContent=en?'New activity in Collaboration':'Nueva actividad en Colaboración';
 shadow.append(style,bubble,notice,frame);document.body.append(container);
 let opened=false,ready=false,activity=0,disposed=false,noticeTimer;
 const controller=new AbortController(),signal=controller.signal;
 function post(type,extra={}){if(!disposed)frame.contentWindow?.postMessage({type,channel,...extra},central);}
 function status(interacted=false){post('mca.widget.activity',{opened,visible:document.visibilityState==='visible',focused:document.hasFocus(),interacted});}
 function open(){notice.hidden=true;opened=true;frame.hidden=false;bubble.setAttribute('aria-expanded','true');status(true);frame.focus();}
 function close(){opened=false;frame.hidden=true;bubble.setAttribute('aria-expanded','false');status();bubble.focus();}
 function onMessage(e){
  if(e.origin!==central||e.source!==frame.contentWindow||e.data?.channel!==channel)return;
  if(e.data.type==='mca.widget.appearance'){const appearance=e.data.appearance||{};if(appearance.position==='left')container.classList.add('left');if(appearance.theme==='dark')bubble.style.background='#13253d';const environment=options.environment||script.dataset.environment;if(environment&&environment!==e.data.environment){current.destroy();return;}}
  if(e.data.type==='mca.widget.ready'){ready=true;status();}
  if(e.data.type==='mca.widget.close')close();
  if(e.data.type==='mca.widget.alert'&&!opened){notice.hidden=false;clearTimeout(noticeTimer);noticeTimer=setTimeout(()=>notice.hidden=true,5000);}
  if(e.data.type==='mca.widget.count'){const n=Math.max(0,Math.min(9999,Number(e.data.unread)||0));badge.hidden=!n;badge.textContent=n>99?'99+':String(n);bubble.setAttribute('aria-label',(en?'Collaboration, unread: ':'Colaboración, pendientes: ')+n);}
  if(e.data.type==='mca.widget.connection'){bubble.title=String(e.data.status||'').slice(0,80);}
 }
 notice.addEventListener('click',open,{signal});
 bubble.addEventListener('click',()=>opened?close():open(),{signal});
 window.addEventListener('message',onMessage,{signal});
 document.addEventListener('visibilitychange',()=>status(),{signal});
 for(const type of ['focus','blur'])window.addEventListener(type,()=>status(type==='focus'),{signal});
 for(const type of ['pointerdown','keydown'])document.addEventListener(type,()=>{if(Date.now()-activity>10000){activity=Date.now();status(true);}},{signal,passive:true});
 const timer=setInterval(()=>{if(ready)status();},30000);
 current={open,close,destroy(){post('mca.widget.destroy');disposed=true;clearInterval(timer);clearTimeout(noticeTimer);controller.abort();frame.remove();container.remove();current=null;}};
 if(options.position==='left'||script.dataset.position==='left')container.classList.add('left');
 if(options.open===true)open();
 return current;
}
window.MCACollaboration=Object.freeze({mount,open:()=>current?.open(),close:()=>current?.close(),destroy:()=>current?.destroy()});
if(script.dataset.app){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>mount(),{once:true});else mount();}
})();
