import '../css/attachments.css';

export function installAttachments({root,config,api,el,error,selected,changed,onAuthFailure}) {
    const $=s=>root.querySelector(s), t=config.labels, limits=config.attachments;
    let files=[], recorder=null, stream=null, chunks=[], recording=false, discard=false, timer=null, started=0, epoch=0, requesting=false;
    const requests=new Set();
    function notice(key){ $('[data-file-error]').textContent=t[key]||key; $('[data-file-error]').hidden=false; }
    function closeViewer(){ const d=$('[data-image-viewer]');d.close();d.querySelector('img').removeAttribute('src'); }
    function render(){
        const panel=$('[data-file-drafts]');panel.replaceChildren();
        for(const item of files){
            const card=el('div','tc-file-draft'); card.dataset.fileDraft='';
            if(item.kind==='image'){const img=el('img');img.src=item.preview;img.alt=item.file.name;card.append(img);}
            if(item.kind==='voice'){const audio=el('audio');audio.controls=true;audio.src=item.preview;card.append(audio);}
            const info=el('span','',item.file.name+' · '+formatSize(item.file.size));
            const cancel=el('button','',t.cancel);cancel.type='button';cancel.setAttribute('aria-label',t.cancel+' '+item.file.name);
            cancel.onclick=()=>{files=files.filter(v=>v!==item);releaseItem(item);render();};
            card.append(info,cancel);panel.append(card);
        }
        panel.hidden=!files.length;
        $('[data-record]').textContent=recording?t.stop_recording:t.record_voice;
        $('[data-record-cancel]').hidden=!recording&&!requesting;
        $('[data-record]').disabled=requesting;
        changed();
    }
    function formatSize(size){return size>=1048576?(size/1048576).toFixed(1)+' MB':Math.max(1,Math.ceil(size/1024))+' KB';}
    function releaseItem(item){if(item.preview)URL.revokeObjectURL(item.preview);}
    function add(list,kind){
        if(!selected()||$('[data-body]').disabled)return;
        $('[data-file-error]').hidden=true;
        for(const file of list){
            if(files.length>=limits.max_count){notice('file_count');break;}
            if(file.size===0||file.size>limits.max_bytes){notice('file_size');continue;}
            const ext=file.name.split('.').pop().toLowerCase();
            if(!limits.types[ext]){notice('file_type');continue;}
            files.push({file,kind:kind||(file.type.startsWith('image/')?'image':'document'),command:crypto.randomUUID(),preview:URL.createObjectURL(file),remote:null});
        }
        render();
    }
    async function record(){
        if(recording){recorder.stop();return;}
        if(!selected()||$('[data-body]').disabled)return;
        if(requesting)return;
        if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder||!window.isSecureContext){notice('mic_unsupported');return;}
        if(files.length>=limits.max_count){notice('file_count');return;}
        const version=++epoch; requesting=true;discard=false;render();
        try{
            const acquired=await navigator.mediaDevices.getUserMedia({audio:true,video:false});
            if(version!==epoch){acquired.getTracks().forEach(t=>t.stop());return;}
            stream=acquired;
            const mime=['audio/webm;codecs=opus','audio/mp4','audio/ogg;codecs=opus','audio/webm'].find(v=>MediaRecorder.isTypeSupported(v));
            if(!mime)throw new Error('unsupported');
            recorder=new MediaRecorder(stream,{mimeType:mime});chunks=[];recording=true;requesting=false;started=Date.now();
            const capture=recorder, tracks=stream, parts=chunks;
            recorder.ondataavailable=e=>{if(e.data.size)parts.push(e.data);};
            recorder.onerror=()=>{if(version!==epoch)return;discard=true;notice('record_error');if(capture.state!=='inactive')capture.stop();};
            recorder.onstop=()=>{
                tracks.getTracks().forEach(t=>t.stop());
                if(version!==epoch)return;
                clearInterval(timer);stream=null;recording=false;
                $('[data-record-time]').textContent='';
                if(!discard&&version===epoch){const blob=new Blob(parts,{type:capture.mimeType});const ext=mime.includes('mp4')?'m4a':mime.includes('ogg')?'ogg':'webm';add([new File([blob],t.voice_name+'.'+ext,{type:blob.type})],'voice');}
                chunks=[];render();
            };
            recorder.start(250);
            timer=setInterval(()=>{const seconds=Math.floor((Date.now()-started)/1000);$('[data-record-time]').textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');if(seconds>=limits.voice_seconds&&recorder.state==='recording')recorder.stop();},250);
            render();
        }catch(e){if(version!==epoch)return;stream?.getTracks().forEach(t=>t.stop());requesting=false;recording=false;notice(e.name==='NotAllowedError'?'mic_denied':'record_error');render();}
    }
    function cancelRecord(){discard=true;epoch++;requesting=false;if(recorder&&recorder.state!=='inactive')recorder.stop();stream?.getTracks().forEach(t=>t.stop());stream=null;recording=false;clearInterval(timer);$('[data-record-time]').textContent='';render();}
    function reset(){cancelRecord();for(const item of files){releaseItem(item);if(item.remote)api('/uploads/'+item.remote.id,'DELETE').catch(()=>{});}files=[];render();closeViewer();}
    async function ensure(m){
        for(const item of m.files||[]){
            if(!item.remote){
                const digest=await crypto.subtle.digest('SHA-256',await item.file.arrayBuffer());
                const sha256=[...new Uint8Array(digest)].map(b=>b.toString(16).padStart(2,'0')).join('');
                const ext=item.file.name.split('.').pop().toLowerCase();
                item.remote=await api('/conversations/'+m.conversation_id+'/uploads','POST',{command_id:item.command,name:item.file.name,kind:item.kind,
                    mime:item.file.type.split(';')[0]||limits.types[ext][0],size:item.file.size,sha256});
            }
            if(item.remote.state!=='ready'){
                item.remote=await new Promise((resolve,reject)=>{
                    const xhr=new XMLHttpRequest();requests.add(xhr);
                    xhr.open('POST',config.api+'/uploads/'+item.remote.id+'/content');xhr.withCredentials=true;
                    xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-CSRF-TOKEN',document.querySelector('meta[name=csrf-token]').content);
                    xhr.upload.onprogress=e=>{m.transfer=e.lengthComputable?Math.round(e.loaded/e.total*100):0;changed();};
                    xhr.onload=()=>{requests.delete(xhr);let result;try{result=JSON.parse(xhr.responseText);}catch{}
                        if(xhr.status>=200&&xhr.status<300)resolve(result.data);
                        else {if([401,419].includes(xhr.status)||['device_revoked','session_revoked'].includes(result?.error?.code))onAuthFailure();reject(Object.assign(new Error(result?.error?.code||'upload_failed'),{status:xhr.status}));}};
                    xhr.onerror=xhr.onabort=()=>{requests.delete(xhr);reject(new Error('upload_failed'));};
                    const data=new FormData();data.append('file',item.file);xhr.send(data);
                });
            }
        }
        return (m.files||[]).map(item=>item.remote.id);
    }
    function renderMessage(bubble,m){
        const items=m.attachments|| (m.files||[]).map(v=>({...v.remote,name:v.file.name,kind:v.kind,size:v.file.size,content_url:v.remote?.state==='ready'?v.remote.content_url:v.preview}));
        for(const item of items){
            const card=el('div','tc-attachment');card.dataset.attachment=item.kind;
            if(item.kind==='image'){
                const button=el('button','tc-image-button');button.type='button';button.setAttribute('aria-label',t.expand_image);
                const img=el('img','tc-message-image');img.src=item.content_url;img.alt=item.name;img.loading='lazy';img.addEventListener('load',()=>changed(false));button.append(img);
                button.onclick=()=>{const d=$('[data-image-viewer]');d.querySelector('img').src=item.content_url;d.querySelector('img').alt=item.name;d.showModal();};card.append(button);
            }else if(item.kind==='voice'){
                const audio=el('audio','tc-voice');audio.controls=true;audio.preload='metadata';audio.src=item.content_url;audio.setAttribute('aria-label',t.voice_name);card.append(audio);
                const speed=el('select','tc-speed');speed.setAttribute('aria-label',t.playback_speed);
                for(const v of [1,1.5,2]){const o=el('option','',v+'×');o.value=v;speed.append(o);}
                speed.onchange=()=>audio.playbackRate=Number(speed.value);card.append(speed);
                if(item.duration_ms)card.append(el('span','tc-file-detail',Math.ceil(item.duration_ms/1000)+' s'));
            } else {card.append(el('span','tc-file-icon','▤'),el('strong','',item.name),el('span','tc-file-detail',(item.mime||'')+' · '+formatSize(item.size)));}
            if(item.id){const download=el('a','tc-file-download',t.download);download.href=item.content_url+'?download=1';download.setAttribute('download','');card.append(download);}
            bubble.append(card);
        }
        if(m.files&&m.status==='pending')bubble.append(el('span','tc-transfer',t.uploading+' '+(m.transfer||0)+'%'));
    }
    $('[data-attach]').onclick=()=>$('[data-file-input]').click();
    $('[data-attach-image]').onclick=()=>$('[data-image-input]').click();
    $('[data-file-input]').onchange=e=>{add([...e.target.files]);e.target.value='';};
    $('[data-image-input]').onchange=e=>{add([...e.target.files],'image');e.target.value='';};
    $('[data-record]').onclick=record;$('[data-record-cancel]').onclick=cancelRecord;
    $('[data-viewer-close]').onclick=closeViewer;
    $('[data-image-viewer]').addEventListener('cancel',closeViewer);
    window.addEventListener('pagehide',()=>{cancelRecord();requests.forEach(x=>x.abort());});
    render();
    return {ensure,renderMessage,reset,cancelRecord,hasFiles:()=>files.length>0,isRecording:()=>recording||requesting,
        take:()=>{const result=files;files=[];render();return result;},
        release:m=>(m.files||[]).forEach(releaseItem),
        stop:()=>{requests.forEach(x=>x.abort());reset();}};
}
