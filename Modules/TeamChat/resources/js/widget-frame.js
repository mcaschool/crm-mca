import '../css/widget.css';
const cfg=JSON.parse(document.querySelector('#widget-config').textContent),$=s=>document.querySelector(s),en=cfg.locale==='en';
let preferred=false,heartBusy=false,alertBusy=false;
let token=null,instance=null,renewing=null,popup=null,proof=null,closed=false,active={opened:false,visible:false,focused:false,interacted:false},lastHeartbeat=0,lastCount=0,heartTimer=null,renewTimer=null,alertTimer=null,audio=null,chat=null,preferences={visual:true,sound:false,muted:false};
const inFlight=new Set();
const pending=new Map(),processed=new Set(),blobs=new Set(),controller=new AbortController(),signal=controller.signal;
function post(type,data={}){parent.postMessage({type,channel:cfg.channel,...data},cfg.origin);}
function status(text){$('[data-widget-status]').textContent=text;post('mca.widget.connection',{status:text});}
function headers(){return token?{Authorization:'MCAWidget '+token,'X-Widget-Instance':instance,'X-Widget-App':cfg.app,'X-Widget-Origin':cfg.origin}:{};}
async function beginOperation(){
 if(renewing)await renewing;
 let done;const completion=new Promise(resolve=>done=resolve);inFlight.add(completion);
 return ()=>{inFlight.delete(completion);done();};
}
async function request(path,method='GET',body=null,raw=false){
 const done=await beginOperation();try{return await performRequest(path,method,body,raw);}finally{done();}
}
async function performRequest(path,method='GET',body=null,raw=false){
 if(closed||!token)throw Error('disconnected');
 const r=await fetch('/api/v1/collaboration'+path,{method,credentials:'omit',cache:'no-store',headers:{...headers(),Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
 if(!r.ok){const error=await r.json().catch(()=>({}));if([401,419].includes(r.status)||['session_revoked','device_revoked','two_factor_required'].includes(error.error?.code)){disconnect(false);}throw Object.assign(Error(error.error?.code||'request_failed'),{status:r.status});}
 if(raw)return r;const json=await r.json();return json.data??json;
}
async function renew(){
 if(!token||closed||renewing)return;
 renewing=(async()=>{await Promise.all([...inFlight]);const r=await fetch('/api/v1/collaboration/widget/renew',{method:'POST',credentials:'omit',headers:{...headers(),Accept:'application/json'}});if(!r.ok)throw Error('expired');const v=(await r.json()).data;if(closed)return;token=v.token;renewTimer=setTimeout(renew,Math.max(30,v.expires_in-60)*1000);})();
 try{await renewing;}catch{disconnect(false);}finally{renewing=null;}
}
async function heartbeat(force=false){
 if(!token||closed||heartBusy||Date.now()-lastHeartbeat<(force?2000:25000))return;
 heartBusy=true;
 lastHeartbeat=Date.now();
 try{const h=await request('/widget/heartbeat','POST',{visible:active.visible,focused:active.focused,interacted:active.interacted});preferred=h.preferred===instance;active.interacted=false;await counts();await alerts();}catch{}finally{heartBusy=false;}
}
async function counts(){if(!token||Date.now()-lastCount<3000)return;lastCount=Date.now();try{const v=await request('/widget/state');post('mca.widget.count',v);}catch{}}
async function alerts(){
 if(!token||closed||!preferred||alertBusy)return;
 alertBusy=true;
 try{for(const [id,n] of pending){
  if(processed.has(id)||Date.parse(n.expires_at)<=Date.now()){pending.delete(id);continue;}
  try{
   const claim=await request('/widget/claim','POST',{notification:n.id});
   if(!claim.claimed){if(['muted','processed'].includes(claim.reason)){pending.delete(id);processed.add(id);}continue;}
   try{await request('/widget/ack','POST',{event:claim.event_id,lease:claim.lease});}catch{continue;}
   if(closed)return;
   processed.add(id);pending.delete(id);
   if(claim.visual){const root=$('#teamchat');if(root){const node=root.querySelector('[data-toast]');node.textContent=en?'New activity in Collaboration':'Nueva actividad en Colaboración';node.hidden=false;setTimeout(()=>node.hidden=true,4500);}post('mca.widget.alert');}
   if(claim.sound&&audio&&audio.state==='running'){const oscillator=audio.createOscillator(),gain=audio.createGain();oscillator.connect(gain);gain.connect(audio.destination);gain.gain.value=.06;oscillator.frequency.value=660;oscillator.start();oscillator.stop(audio.currentTime+.12);}
   window.dispatchEvent(new CustomEvent('mca-widget-alert',{detail:{event:claim.event_id,sound:!!(claim.sound&&audio?.state==='running')}}));
  }catch(e){if([403,404].includes(e.status)){pending.delete(id);processed.add(id);}}
 }}finally{alertBusy=false;}
}
async function accept(code){
 const r=await fetch('/api/v1/collaboration/widget/exchange',{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({app:cfg.app,origin:cfg.origin,code,verifier:proof.verifier})});
 if(!r.ok)throw Error('exchange');
 const v=(await r.json()).data;token=v.token;instance=v.instance_id;proof=null;
 window.mcaWidgetTransport={
  request,headers,beginOperation,
  reading:()=>active.opened&&active.visible&&document.hasFocus(),
  selected:()=>chat?.selected?.(),
  updated:()=>counts(),
  notifications:(entries,initial)=>{for(const n of entries){if(!processed.has(n.source_event_id))pending.set(n.source_event_id,n);}alerts();},
  connection:key=>post('mca.widget.connection',{status:key}),
  async media(url){const parsed=new URL(url,location.origin);if(parsed.origin!==location.origin||!parsed.pathname.startsWith('/api/v1/collaboration/uploads/'))throw Error('invalid media');const r=await request(parsed.pathname.replace('/api/v1/collaboration','')+parsed.search,'GET',null,true);const blob=URL.createObjectURL(await r.blob());blobs.add(blob);return blob;},
 };
 const html=await (await request('/widget/content?locale='+cfg.locale,'GET',null,true)).text();
 $('[data-widget-content]').innerHTML=html;$('[data-widget-login]').hidden=true;status('');
 const module=await import('./teamchat.js');chat=window.mcaTeamChat;
 preferences=await request('/widget/preferences');drawPreferences();
 await heartbeat(true);
 heartTimer=setInterval(()=>heartbeat(),30000);alertTimer=setInterval(alerts,15000);renewTimer=setTimeout(renew,Math.max(30,v.expires_in-60)*1000);
}
async function prepare(){
 const bytes=crypto.getRandomValues(new Uint8Array(32)),verifier=[...bytes].map(n=>n.toString(16).padStart(2,'0')).join('');
 const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(verifier));
 const challenge=btoa(String.fromCharCode(...new Uint8Array(digest))).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
 proof={verifier,challenge,state:crypto.randomUUID().replaceAll('-','')};return proof;
}
function authorize(){
 if(!proof)return;popup=window.open('/collaboration/widget/authorize?'+new URLSearchParams({app:cfg.app,origin:cfg.origin,challenge:proof.challenge,state:proof.state,locale:cfg.locale}),'mca-widget-'+cfg.channel,'popup,width=520,height=680');
 if(!popup)status(en?'Allow the institutional sign-in window.':'Permite la ventana de acceso institucional.');
}
async function disconnect(revoke=true){
 if(closed)return;
 const oldHeaders=headers(),hadToken=!!token;
 closed=true;token=null;
 if(revoke&&hadToken)fetch('/api/v1/collaboration/widget/revoke',{method:'POST',credentials:'omit',keepalive:true,headers:{...oldHeaders,Accept:'application/json'}}).catch(()=>{});
 clearInterval(heartTimer);clearInterval(alertTimer);clearTimeout(renewTimer);chat?.destroy?.();controller.abort();popup?.close();if(audio&&audio.state!=='closed')audio.close().catch(()=>{});
 for(const url of blobs)URL.revokeObjectURL(url);blobs.clear();delete window.mcaWidgetTransport;
 $('[data-widget-content]').replaceChildren();$('[data-widget-settings]').hidden=true;status(en?'Session closed. Reopen the widget to connect.':'Sesión cerrada. Vuelve a cargar el widget para conectar.');
 post('mca.widget.count',{unread:0});
}
function drawPreferences(){$('[data-visual]').checked=preferences.visual;$('[data-sound]').checked=preferences.sound;}
async function save(extra={},resource=''){
 const data={resource_id:resource,...extra};
 try{preferences=await request('/widget/preferences','PUT',data);drawPreferences();}catch{status(en?'Unable to save preferences':'No se pudieron guardar las preferencias');}
}
$('[data-widget-login]').onclick=authorize;
$('[data-widget-close]').onclick=()=>post('mca.widget.close');
$('[data-widget-options]').onclick=()=>$('[data-widget-settings]').hidden=!$('[data-widget-settings]').hidden;
$('[data-visual]').onchange=()=>save({visual:$('[data-visual]').checked});
$('[data-sound]').onchange=()=>{if($('[data-sound]').checked){audio??=new AudioContext();audio.resume().catch(()=>status(en?'Tap to enable sound':'Pulsa para activar el sonido'));}save({sound:$('[data-sound]').checked});};
$('[data-enable-sound]').onclick=()=>{audio??=new AudioContext();audio.resume().catch(()=>status(en?'Sound unavailable':'Sonido no disponible'));$('[data-sound]').checked=true;save({sound:true});};
$('[data-snooze]').onclick=()=>save({snooze_minutes:60});$('[data-resume]').onclick=()=>save({snooze_minutes:0,muted:false});
$('[data-mute-conversation]').onclick=()=>{const id=chat?.selected?.();if(id)save({muted:true},id);};
$('[data-unmute-conversation]').onclick=()=>{const id=chat?.selected?.();if(id)save({muted:false},id);};
$('[data-disconnect]').onclick=()=>disconnect();
window.addEventListener('message',async e=>{
 if(closed)return;
 if(e.source===popup&&e.origin===cfg.central&&e.data?.type==='mca.widget.code'&&proof&&e.data.state===proof.state&&e.data.app===cfg.app){try{await accept(e.data.code);}catch{status(en?'Connection denied':'Conexión rechazada');}return;}
 if(e.source!==parent||e.origin!==cfg.origin||e.data?.channel!==cfg.channel)return;
 if(e.data.type==='mca.widget.activity'){active={opened:e.data.opened===true,visible:e.data.visible===true,focused:e.data.focused===true,interacted:e.data.interacted===true};heartbeat(true);}
 if(e.data.type==='mca.widget.destroy')disconnect();
},{signal});
document.addEventListener('pointerdown',()=>{active.interacted=true;active.focused=true;heartbeat();},{signal,passive:true});
window.addEventListener('pagehide',()=>disconnect(false),{signal});
await prepare();post('mca.widget.appearance',{appearance:cfg.appearance,environment:cfg.environment});post('mca.widget.ready');
if(cfg.origin===cfg.central){
 try{
  // Same-origin CRM only: normal cookie + CSRF auth. Cross-origin frames never depend on this path.
  const r=await fetch('/collaboration/widget/authorize',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':$('meta[name=csrf-token]').content},body:JSON.stringify({app:cfg.app,origin:cfg.origin,challenge:proof.challenge,state:proof.state,automatic:true})});
  if(r.ok)await accept((await r.json()).data.code);
 }catch{}
}
