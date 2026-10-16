import '../css/teamchat.css';
import { installSpaces } from './spaces';
import { installAttachments } from './attachments';

// Domain content never travels over the socket. It is fetched through the
// authenticated API after every wake, including reconnects and fallback polls.
const root = document.querySelector('#teamchat');
if (root) startChat(root);

function startChat(root) {
    const config = JSON.parse(document.querySelector('#teamchat-config').textContent);
    const t = config.labels, $ = (s) => root.querySelector(s);
    const state = { conversations: new Map(), messages: new Map(), drafts: new Map(), pending: new Map(),
        view: 'direct', selected: null, listCursor: null, searchCursor: null, search: '', searchVersion: 0,
        older: null, newer: null, hasOlder: false, cursor: null, booting: false, syncing: false,
        wake: false, stopped: false, socket: null, connected: false, retry: 0, seen: new Set(),
        notices: new Set(), presence: new Map(), receiptQueue: new Map(), receiptTimer: null };
    const body = $('[data-body]'), history = $('[data-history]'), messages = $('[data-messages]');
    let retryTimer, pollTimer, presenceTimer, searchTimer, toastTimer, socketWatch, lastSocketAt = 0;
    const dates = new Intl.DateTimeFormat(config.locale, { day:'numeric', month:'short', year:'numeric' });
    const times = new Intl.DateTimeFormat(config.locale, { hour:'2-digit', minute:'2-digit' });
    const shortDate = (value) => new Date(value).toDateString() === new Date().toDateString() ? times.format(new Date(value)) : dates.format(new Date(value));
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
    const initials = (name) => name.trim().split(/\s+/u).slice(0,2).map(v => Array.from(v)[0] || '').join('').toUpperCase();
    const nearBottom = () => history.scrollHeight - history.scrollTop - history.clientHeight < 100;
    const toBottom = () => { history.scrollTop = history.scrollHeight; $('[data-jump]').hidden = true; };
    const toast = (text) => { $('[data-toast]').textContent = text; $('[data-toast]').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('[data-toast]').hidden = true, 3500); };
    function error(e) { if (state.stopped) return; if ($('[data-space-dialog]').open) { $('[data-dialog-loading]')?.remove(); let notice=$('[data-dialog-error]'); if(!notice){notice=el('p','tc-dialog-feedback');notice.dataset.dialogError='';notice.setAttribute('role','alert');$('[data-space-panel]').append(notice);} notice.textContent=e?.status===403?t.not_allowed:t.error; } $('[data-error] span').textContent = e?.status === 422 ? t.limit : t.error; $('[data-error]').hidden = false; }
    function stop() {
        if (state.stopped) return;
        state.stopped = true; attachments.stop(); state.socket?.close(); clearTimeout(retryTimer); clearTimeout(pollTimer);
        clearInterval(presenceTimer); clearInterval(socketWatch); clearTimeout(state.receiptTimer);
        state.conversations.clear(); state.messages.clear(); state.pending.clear(); state.drafts.clear();
        messages.replaceChildren(); $('[data-list]').replaceChildren(); $('[data-team-tree]').replaceChildren(); $('[data-space-dialog]').close(); body.value = ''; body.disabled = true;
        $('[data-thread]').hidden = true; $('[data-welcome]').hidden = false;
        $('[data-error] span').textContent = t.denied; $('[data-error]').hidden = false;
        $('[data-retry]').hidden = true; $('[data-dismiss]').hidden = true; connection('offline');
    }
    async function api(path, method = 'GET', payload = null) {
        if (state.stopped) throw new Error('stopped');
        const response = await fetch(config.api + path, { method, credentials:'same-origin', cache:'no-store',
            headers:{ Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content },
            ...(payload === null ? {} : { body:JSON.stringify(payload) }) });
        const json = await response.json().catch(() => ({}));
        if (state.stopped) throw new Error('stopped');
        if (!response.ok) {
            const e = Object.assign(new Error(json.error?.code || 'request_failed'), { status:response.status });
            if ([401,419].includes(e.status) || ['device_revoked','session_revoked','two_factor_required'].includes(e.message)) stop();
            throw e;
        }
        return json.data ?? json;
    }
    function connection(key) { $('[data-connection]').textContent = t[key]; root.dataset.online = String(key === 'connected'); }
    function avatar(name, userId) {
        const a = el('span','tc-avatar',initials(name)); a.setAttribute('aria-hidden','true');
        const p = state.presence.get(userId); const dot = el('i','tc-presence-dot' + (p?.online ? ' ' + (p.manual_status === 'busy' ? 'busy' : 'online') : ''));
        a.append(dot); return a;
    }
    function row(c, directory = false) {
        const b = el('button','tc-contact' + (!directory && c.id === state.selected ? ' selected' : ''));
        b.type = 'button'; b.dataset.contact = String(c.id); b.setAttribute('aria-pressed',String(!directory && c.id === state.selected));
        const peer = directory ? c : c.peer;
        b.append(avatar(peer.name,peer.id));
        const text = el('span','tc-contact-text'), top = el('span','tc-contact-top');
        top.append(el('strong','',(!directory && c.type !== 'direct' ? (c.type === 'channel' ? '# ' : '◉ ') : '') + peer.name));
        if (!directory) top.append(el('time','',shortDate(c.activity_at)));
        text.append(top);
        const preview = el('span','tc-preview'); preview.append(el('span','',directory ? t.new : c.last_message?.body || (c.last_message?.attachments?.length ? t.attachment : t.empty_chat)));
        if (c.unread_count) preview.append(el('span','tc-count',String(c.unread_count)));
        text.append(preview); b.append(text);
        b.addEventListener('click', () => directory ? createDirect(peer.id) : openConversation(c.id));
        return b;
    }
    function renderList() {
        if (state.search) return;
        const list = $('[data-list]');
        // Reuse unaffected rows: an incoming message never replaces the whole inbox.
        const ordered = [...state.conversations.values()].filter(c => c.type === state.view).sort((a,b) => b.activity_at.localeCompare(a.activity_at) || b.id.localeCompare(a.id));
        const existing = new Map([...list.querySelectorAll('[data-contact]')].map(n => [n.dataset.contact,n]));
        list.querySelector('.tc-empty')?.remove();
        for (const c of ordered) {
            const signature = JSON.stringify([c,c.id === state.selected,state.presence.get(c.peer.id)]);
            let n = existing.get(c.id);
            if (!n || n.dataset.signature !== signature) { const next = row(c); next.dataset.signature = signature; n?.replaceWith(next); n = next; }
            list.append(n); existing.delete(c.id);
        }
        existing.forEach(n => n.remove());
        if (!ordered.length) list.append(el('p','tc-empty',t.none));
        $('[data-list-more]').hidden = !state.listCursor;
    }
    async function listMore(reset = false) {
        const result = await api('/conversations?limit=30' + (!reset && state.listCursor ? '&cursor=' + encodeURIComponent(state.listCursor) : ''));
        if (reset) state.conversations.clear();
        for (const c of result.conversations) state.conversations.set(c.id,c);
        state.listCursor = result.next_cursor; renderList();
    }
    async function searchMore(reset = true) {
        const q = $('[data-search]').value.trim(), version = ++state.searchVersion;
        state.search = q; if (!q) { renderList(); return; }
        if (reset) { $('[data-list]').replaceChildren(el('p','tc-empty',t.loading)); state.searchCursor = null; }
        const result = await api('/teamchat/directory?q=' + encodeURIComponent(q) + '&limit=30' + (state.searchCursor ? '&cursor=' + encodeURIComponent(state.searchCursor) : ''));
        if (version !== state.searchVersion || q !== state.search) return;
        if (reset) $('[data-list]').replaceChildren();
        result.users.forEach(u => $('[data-list]').append(row(u,true)));
        if (!$('[data-list]').children.length) $('[data-list]').append(el('p','tc-empty',t.no_results));
        state.searchCursor = result.next_cursor; $('[data-list-more]').hidden = !state.searchCursor;
    }
    async function createDirect(id) {
        try {
            const c = await api('/conversations/direct','POST',{participant_id:id});
            state.conversations.set(c.id,c); spaces.switchView('direct'); state.search = ''; state.searchVersion++; $('[data-search]').value = '';
            renderList(); await openConversation(c.id);
        } catch (e) { error(e); }
    }
    function header() {
        const c = state.conversations.get(state.selected); if (!c) return;
        $('[data-peer-name]').textContent = c.peer.name; $('[data-peer-avatar]').textContent = initials(c.peer.name);
        $('[data-manage]').hidden = c.type === 'direct';
        const p = state.presence.get(c.peer.id);
        $('[data-peer-presence]').textContent = !p ? t.unknown : p.online ? (p.manual_status === 'busy' ? t.busy : p.manual_status === 'away' ? t.away : t.online) : p.last_seen_at ? t.last_seen + ' · ' + shortDate(p.last_seen_at) : t.offline;
        if (c.type !== 'direct') $('[data-peer-presence]').textContent = c.member_count + ' · ' + t.members + ' · ' + t[c.visibility];
        body.disabled = !c.can_send; root.querySelectorAll('[data-attach],[data-attach-image],[data-record],[data-form] button[type=submit]').forEach(n=>n.disabled=!c.can_send);
        $('[data-composer-hint]').textContent = c.can_send ? t.hint : t.unavailable;
    }
    async function refreshConversation(id) {
        try { const c = await api('/conversations/' + id); const old = state.conversations.get(id); state.conversations.set(id,c);
            if (id === state.selected && old?.history_epoch && old.history_epoch !== c.history_epoch) {
                attachments.reset(); state.messages.clear(); state.pending.delete(id); state.newer = state.older = null; messages.replaceChildren(); await newerMessages(id);
            }
            renderList(); spaces.repaint(); if (id === state.selected) header(); }
        catch (e) {
            if ([403,404].includes(e.status)) {
                state.conversations.delete(id); state.pending.delete(id); state.drafts.delete(id); renderList(); spaces.repaint();
                if (id === state.selected) { attachments.reset(); state.selected = null; state.messages.clear(); messages.replaceChildren(); $('[data-thread]').hidden = true; $('[data-welcome]').hidden = false; root.classList.remove('tc-selected'); }
            } else throw e;
        }
    }
    async function openConversation(id) {
        attachments.reset();
        if (state.selected) state.drafts.set(state.selected,body.value);
        state.selected = id; state.messages.clear(); state.older = state.newer = null;
        observer.disconnect(); messages.replaceChildren(); $('[data-empty-chat]').textContent = t.loading;
        $('[data-empty-chat]').hidden = false; $('[data-thread]').hidden = false; $('[data-welcome]').hidden = true;
        root.classList.add('tc-selected'); body.value = state.drafts.get(id) || ''; header(); renderList();
        try {
            const page = await api('/conversations/' + id + '/messages?limit=30');
            if (state.selected !== id) return;
            state.older = page.older_cursor; state.newer = page.newer_cursor; state.hasOlder = page.has_more;
            acceptMessages(id,page.messages); renderMessages(); toBottom(); observeRead(); body.focus({preventScroll:true});
            refreshPresence().catch(() => {});
        } catch (e) { error(e); }
    }
    function acceptMessages(id, items) {
        const delivered = [];
        for (const m of items) {
            if (id === state.selected) state.messages.set(m.id,m);
            const pending = state.pending.get(id); if (pending) pending.delete(m.command_id);
            if (m.sender_id !== config.userId && m.status === 'sent') delivered.push(m.id);
        }
        if (delivered.length) queueReceipt(id,delivered,'delivered');
    }
    function messageNode(m, pending = false) {
        const mine = m.sender_id === config.userId, n = el('article','tc-message' + (mine ? ' mine' : ''));
        n.dataset.message = m.id || m.command_id; n.dataset.status = m.status;
        const bubble = el('div','tc-bubble');
        if (state.conversations.get(state.selected)?.type !== 'direct') {
            const sender = el('div','tc-sender'); sender.append(avatar(m.sender_name || config.labels.you,m.sender_id),el('strong','',m.sender_name || t.you)); bubble.append(sender);
        }
        bubble.append(el('p','tc-message-body',m.body));
        attachments.renderMessage(bubble,m);
        const meta = el('div','tc-message-meta'); meta.append(el('time','',times.format(new Date(m.created_at))));
        if (mine) meta.append(el('span',m.status,t[m.status]));
        if (mine && m.id && state.conversations.get(state.selected)?.type !== 'direct' && m.delivered_count) meta.append(el('span','',t.read_by+' '+m.read_count+' · '+t.delivered_to+' '+m.delivered_count));
        if (pending && m.status === 'failed') { const retry = el('button','',t.retry); retry.type = 'button'; retry.onclick = () => transmit(m); meta.append(retry); }
        bubble.append(meta); n.append(bubble); return n;
    }
    function renderMessages() {
        const all = [...state.messages.values()].sort((a,b) => a.sequence - b.sequence);
        const pending = [...(state.pending.get(state.selected)?.values() || [])];
        const current = new Map([...messages.querySelectorAll('[data-message]')].map(n => [n.dataset.message,n]));
        messages.querySelectorAll('.tc-day').forEach(n => n.remove());
        let day = '';
        for (const m of [...all,...pending]) {
            const date = dates.format(new Date(m.created_at));
            if (date !== day) { messages.append(el('div','tc-day',date)); day = date; }
            const id = m.id || m.command_id, signature = JSON.stringify([m.body,m.status,m.created_at,m.delivered_count,m.read_count,m.attachments,m.transfer]);
            let n = current.get(id);
            if (!n || n.dataset.signature !== signature) {
                const next = messageNode(m,!m.id); next.dataset.signature = signature; if(n) {observer.unobserve(n);n.replaceWith(next);} n = next;
            }
            messages.append(n); current.delete(id);
            if (m.id && m.sender_id !== config.userId && m.status !== 'read') observer.observe(n);
        }
        current.forEach(n => {observer.unobserve(n);n.remove();});
        $('[data-empty-chat]').textContent = t.empty_chat; $('[data-empty-chat]').hidden = all.length + pending.length > 0;
        $('[data-older]').hidden = !state.hasOlder;
    }
    async function newerMessages(id) {
        if (state.selected !== id) return;
        const bottom = nearBottom();
        let more = true;
        while (more && state.selected === id) {
            const page = await api('/conversations/' + id + '/messages?limit=50' + (state.newer ? '&cursor=' + encodeURIComponent(state.newer) : ''));
            if (state.selected !== id) return;
            acceptMessages(id,page.messages); state.newer = page.newer_cursor; more = page.has_more;
            renderMessages();
            if (page.messages.length) { if (bottom) toBottom(); else $('[data-jump]').hidden = false; }
        }
        observeRead();
    }
    async function olderMessages() {
        const id = state.selected, cursor = state.older; if (!id || !cursor) return;
        $('[data-older]').disabled = true;
        try {
            const page = await api('/conversations/' + id + '/messages?limit=30&cursor=' + encodeURIComponent(cursor));
            if (state.selected !== id) return;
            const height = history.scrollHeight, top = history.scrollTop;
            acceptMessages(id,page.messages); state.older = page.older_cursor; state.hasOlder = page.has_more;
            renderMessages(); history.scrollTop = top + history.scrollHeight - height; observeRead();
        } catch(e) { error(e); } finally { $('[data-older]').disabled = false; }
    }
    async function transmit(m) {
        if (m.status === 'pending') return;
        m.status = 'pending'; if (state.selected === m.conversation_id) renderMessages();
        try {
            const uploadIds = await attachments.ensure(m);
            const saved = await api('/conversations/' + m.conversation_id + '/messages','POST',{command_id:m.command_id,body:m.body,attachments:uploadIds});
            attachments.release(m);
            $('[data-error]').hidden = true;
            acceptMessages(m.conversation_id,[saved]); if (state.selected === m.conversation_id) {renderMessages();toBottom();}
            await refreshConversation(m.conversation_id);
        } catch(e) { m.status = 'failed'; if (state.selected === m.conversation_id) renderMessages(); error(e); }
    }
    const attachments = installAttachments({root,config,api,el,error,selected:()=>state.selected,changed:(paint=true)=>{if(paint)renderMessages();},onAuthFailure:stop});
    function submit(event) {
        event.preventDefault(); const id = state.selected, text = body.value.trim();
        if (!id || (!text && !attachments.hasFiles()) || body.disabled || attachments.isRecording()) return;
        const m = {command_id:crypto.randomUUID(),conversation_id:id,sender_id:config.userId,body:text,files:attachments.take(),status:'ready',created_at:new Date().toISOString()};
        if (!state.pending.has(id)) state.pending.set(id,new Map()); state.pending.get(id).set(m.command_id,m);
        body.value = ''; body.style.height = ''; state.drafts.set(id,''); transmit(m); body.focus(); toBottom();
    }
    function queueReceipt(id, ids, kind) {
        if (state.stopped) return;
        const key = id + ':' + kind;
        if (!state.receiptQueue.has(key)) state.receiptQueue.set(key,{id,kind,ids:new Set()});
        ids.forEach(v => state.receiptQueue.get(key).ids.add(v));
        if (!state.receiptTimer) state.receiptTimer = setTimeout(flushReceipts,400);
    }
    async function flushReceipts() {
        if (state.stopped) return;
        state.receiptTimer = null;
        const batches = [...state.receiptQueue.values()]; state.receiptQueue.clear();
        for (const batch of batches) {
            const ids = [...batch.ids];
            try {
                for (let i=0;i<ids.length;i+=50) await api('/conversations/' + batch.id + '/receipts','POST',{message_ids:ids.slice(i,i+50),kind:batch.kind});
                if (state.selected === batch.id) {
                    ids.forEach(id => {const m=state.messages.get(id); if(m && m.status!=='read')m.status=batch.kind;});renderMessages();
                }
                await refreshConversation(batch.id);
            } catch(e) { if (!state.stopped && ![401,403,404,419].includes(e.status)) { batch.ids.forEach(id => queueReceipt(batch.id,[id],batch.kind)); clearTimeout(state.receiptTimer); state.receiptTimer = setTimeout(flushReceipts,10000); } }
        }
    }
    function visible(n) {
        const a = n.getBoundingClientRect(), b = history.getBoundingClientRect();
        return a.bottom > b.top + 8 && a.top < b.bottom - 8 && a.left < b.right && a.right > b.left;
    }
    function observeRead() {
        if (document.visibilityState !== 'visible' || !document.hasFocus() || !root.classList.contains('tc-selected')) return;
        const ids = [...messages.querySelectorAll('[data-message]')].filter(visible).map(n => n.dataset.message)
            .filter(id => { const m = state.messages.get(id); return m && m.sender_id !== config.userId && m.status !== 'read'; });
        if (ids.length) queueReceipt(state.selected,ids,'read');
    }
    const observer = new IntersectionObserver(() => observeRead(),{root:history,threshold:[0,.5,1]});
    async function refreshPresence() {
        if (document.visibilityState !== 'visible') return;
        try { await api('/presence/heartbeat','POST',{}); } catch(e) { if(e.status===403)stop(); throw e; }
        const ids = [...new Set([...state.conversations.values()].filter(c => c.can_send && c.peer.id).map(c=>c.peer.id))].slice(0,50);
        if(ids.length) {
            const result = await api('/presence?' + ids.map(id=>'user_ids[]='+id).join('&'));
            result.forEach(p => state.presence.set(p.user_id,p)); renderList(); header();
        }
    }
    async function notices(initial = false) {
        const entries = await api('/realtime/notifications');
        for (const n of entries) {
            if (state.notices.has(n.id)) continue; state.notices.add(n.id);
            if(!initial && n.type === 'teamchat.message.created' && (state.selected !== n.resource_id || document.visibilityState !== 'visible')) toast(t.notification);
        }
        if(state.notices.size>2000)state.notices=new Set([...state.notices].slice(-1000));
    }
    async function bootstrap() {
        if(state.booting||state.stopped)return;
        state.booting=true;
        try {
            // Capture the cut BEFORE reading domain state. Events after that cut
            // are replayed even if the websocket wakes us during bootstrap.
            let page=await api('/sync?bootstrap=1&limit=100'), cut=page.cursor;
            while(page.nextPageCursor)page=await api('/sync?bootstrap=1&limit=100&cursor='+encodeURIComponent(page.nextPageCursor));
            await listMore(true); await spaces.refresh(); await notices(true);
            if(state.selected) { await refreshConversation(state.selected); if(state.selected)await newerMessages(state.selected); }
            state.cursor=cut;
        } catch(e) { if(e.status===403)stop(); else error(e); }
        finally {state.booting=false;}
        if(state.cursor)await sync();
    }
    async function sync() {
        if(state.stopped)return;
        if(state.booting||state.syncing){state.wake=true;return;}
        if(!state.cursor){await bootstrap();return;}
        state.syncing=true;
        try {
            do {
                state.wake=false;
                const page=await api('/sync?limit=100&cursor='+encodeURIComponent(state.cursor));
                const affected=new Set(), newMessages=new Set(), receipts=new Map();
                for(const e of page.events) {
                    if(state.seen.has(e.id)||!e.type.startsWith('teamchat.'))continue;
                    const id=e.data.conversation_id;affected.add(id);
                    if(e.type==='teamchat.message.created')newMessages.add(id);
                    if(e.type.startsWith('teamchat.receipt.') && id===state.selected) {
                        if(!receipts.has(id))receipts.set(id,new Set());e.data.message_ids.forEach(v=>receipts.get(id).add(v));
                    }
                }
                for(const id of affected)await refreshConversation(id);
                if (page.events.some(e => e.type.startsWith('teamchat.team.') || e.type.startsWith('teamchat.channel.'))) await spaces.refresh();
                if (page.events.some(e => e.type.endsWith('members.changed'))) toast(t.members_changed);
                for(const id of newMessages)if(id===state.selected)await newerMessages(id);
                for(const [id,ids]of receipts) {
                    const list=[...ids].filter(v=>state.messages.has(v));
                    for(let i=0;i<list.length;i+=50){
                        const p=await api('/conversations/'+id+'/messages?limit=50&'+list.slice(i,i+50).map(v=>'ids[]='+v).join('&'));
                        if(id===state.selected){acceptMessages(id,p.messages);renderMessages();}
                    }
                }
                // Only commit the cursor/dedup set after all authorized domain reads succeeded.
                page.events.forEach(e=>state.seen.add(e.id));state.cursor=page.cursor;
                if(state.seen.size>4000)state.seen=new Set([...state.seen].slice(-2000));
                if(newMessages.size)await notices();
                state.wake=state.wake||page.hasMore;
            }while(state.wake&&!state.stopped);
        } catch(e) {
            if(e.status===410){state.cursor=null;state.newer=null;state.older=null;state.seen.clear();}
            else if(e.status===403)stop();
            else if(!state.connected)connection(navigator.onLine?'polling':'offline');
        } finally {state.syncing=false;}
        if(!state.cursor&&!state.stopped)await bootstrap();
    }
    async function connect() {
        if(state.stopped)return;
        if(!config.socket.key){connection('polling');await bootstrap();return;}
        connection('connecting');
        try {
            const {channel}=await api('/realtime/channel');
            const s=config.socket, protocol=s.scheme==='https'?'wss':'ws';
            const ws=new WebSocket(protocol+'://'+s.host+':'+s.port+'/app/'+encodeURIComponent(s.key)+'?protocol=7&client=teamchat&version=1.0&flash=false');
            state.socket=ws;lastSocketAt=Date.now();
            ws.onmessage=async ({data})=>{
                if(state.socket!==ws||state.stopped)return;lastSocketAt=Date.now();
                try {
                    const frame=JSON.parse(data), value=typeof frame.data==='string'?JSON.parse(frame.data):frame.data;
                    if(frame.event==='pusher:connection_established') {
                        const auth=await api('/realtime/auth','POST',{socket_id:value.socket_id,channel_name:channel});
                        if(ws.readyState===WebSocket.OPEN)ws.send(JSON.stringify({event:'pusher:subscribe',data:{channel,auth:auth.auth}}));
                    } else if(frame.event==='pusher_internal:subscription_succeeded') {
                        state.connected=true;state.retry=0;connection('connected');if(state.cursor)await sync();else await bootstrap();
                    } else if(frame.event==='collaboration.sync.required') await sync();
                    else if(frame.event==='pusher:ping') ws.send(JSON.stringify({event:'pusher:pong',data:{}}));
                    else if(frame.event==='pusher:error') ws.close();
                }catch(e){if(e.status===403)stop();else ws.close();}
            };
            ws.onerror=()=>ws.close();
            ws.onclose=()=>{
                if(state.socket!==ws||state.stopped)return;
                state.connected=false;connection(navigator.onLine?'reconnecting':'offline');
                retryTimer=setTimeout(connect,Math.min(30000,1000*2**Math.min(state.retry++,5))+Math.random()*500);
                sync();
            };
        }catch(e){if(e.status===403)stop();else{connection('polling');retryTimer=setTimeout(connect,10000);}}
    }
    async function poll() {
        if(state.stopped)return;
        await sync();
        // Recheck access even without a domain event (e.g. institutional revocation).
        if(state.selected)await refreshConversation(state.selected).catch(()=>{});
        if(state.view === 'team')await spaces.refresh().catch(()=>{});
        pollTimer=setTimeout(poll,document.visibilityState==='visible'?10000:30000);
    }
    const spaces = installSpaces({root, state, t, api, el, renderList, openConversation, refreshConversation, listMore, error, toast});
    $('[data-form]').addEventListener('submit',submit);
    body.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing)submit(e);});
    body.addEventListener('input',()=>{body.style.height='auto';body.style.height=Math.min(132,body.scrollHeight)+'px';});
    $('[data-search]').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>searchMore().catch(error),250);});
    $('[data-new]').onclick=()=>{$('[data-search]').focus();};
    $('[data-list-more]').onclick=()=>{(state.search?searchMore(false):listMore()).catch(error);};
    $('[data-older]').onclick=olderMessages;
    $('[data-back]').onclick=()=>{attachments.cancelRecord();root.classList.remove('tc-selected');};
    $('[data-jump]').onclick=()=>{toBottom();observeRead();};
    $('[data-dismiss]').onclick=()=>{$('[data-error]').hidden=true;};
    $('[data-retry]').onclick=()=>{$('[data-error]').hidden=true;sync();if(state.selected)openConversation(state.selected);};
    history.addEventListener('scroll',()=>{if(nearBottom())$('[data-jump]').hidden=true;observeRead();},{passive:true});
    window.addEventListener('focus',()=>{observeRead();sync();});
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'){sync();observeRead();}});
    window.addEventListener('online',()=>{state.socket?.close();if(!state.socket)connect();sync();});
    window.addEventListener('offline',()=>connection('offline'));
    window.addEventListener('pagehide',()=>{state.stopped=true;state.socket?.close();clearTimeout(retryTimer);clearTimeout(pollTimer);clearInterval(presenceTimer);clearInterval(socketWatch);});
    window.addEventListener('pageshow',e=>{if(e.persisted)window.location.reload();});
    socketWatch=setInterval(()=>{if(state.socket?.readyState===WebSocket.OPEN){if(Date.now()-lastSocketAt>90000)state.socket.close();else state.socket.send(JSON.stringify({event:'pusher:ping',data:{}}));}},25000);
    presenceTimer=setInterval(()=>refreshPresence().catch(()=>{}),30000);
    connect(); pollTimer=setTimeout(poll,1500); refreshPresence().catch(()=>{});
}
