import '../css/spaces.css';

// Management extends the existing inbox; all messaging stays in teamchat.js.
export function installSpaces({root, state, t, api, el, renderList, openConversation, refreshConversation, listMore, error, toast}) {
    const $ = s => root.querySelector(s), dialog = $('[data-space-dialog]'), panel = $('[data-space-panel]');
    const teams = new Map(), expanded = new Set();
    let generation = 0, refreshing = false;
    const button = (label, action, cls = '') => { const b = el('button', cls, label); b.type = 'button'; b.onclick = () => Promise.resolve().then(action).catch(error); return b; };
    function switchView(view) {
        state.view = view; state.search = ''; state.searchVersion++; $('[data-search]').value = '';
        root.querySelectorAll('[data-space-tab]').forEach(n => n.setAttribute('aria-pressed', String(n.dataset.spaceTab === view)));
        $('[data-list]').hidden = view === 'team'; $('[data-team-tree]').hidden = view !== 'team';
        renderList(); if (view === 'team') renderTeams();
    }
    function begin(title) {
        generation++; panel.replaceChildren();
        const heading = el('header','tc-dialog-header'), h = el('h2','',title); h.id = 'tc-dialog-title';
        heading.append(h,button(t.close,()=>dialog.close())); panel.append(heading);
        const progress=el('p','tc-dialog-feedback',t.loading);progress.dataset.dialogLoading='';progress.setAttribute('role','status');panel.append(progress);
        if (!dialog.open) dialog.showModal();
        return generation;
    }
    function field(form, label, name, value = '', multiline = false) {
        const l = el('label','tc-field',label), input = el(multiline ? 'textarea' : 'input');
        input.name = name; input.value = value; input.maxLength = multiline ? 1000 : 120;
        if (!multiline) input.required = true; l.append(input); form.append(l); return input;
    }
    function feedback(form) { const n = el('p','tc-dialog-feedback'); n.setAttribute('role','status'); form.append(n); return n; }
    async function perform(form, status, action) {
        const controls = [...form.querySelectorAll('button,input,textarea,select')];
        const disabled = controls.map(n=>n.disabled); controls.forEach(n=>n.disabled=true); status.textContent=t.loading;
        try { await action(); status.textContent=t.saved; }
        catch(e) { status.textContent = e.status === 403 ? t.not_allowed : e.status === 422 ? t.invalid_space : t.error; throw e; }
        finally { controls.forEach((n,i)=>n.disabled=disabled[i]); }
    }
    function picker(form, gen, excluded = [], teamMembers = null) {
        const selected = new Set(), box = el('fieldset','tc-picker'), legend = el('legend','',t.select_members);
        const search = el('input'); search.type='search'; search.placeholder=t.search; search.setAttribute('aria-label',t.search);
        const chosen = el('div','tc-chosen'), results = el('div','tc-picker-results'), more = button(t.more,()=>load(false));
        box.append(legend,search,chosen,results,more); form.append(box);
        let cursor = null, timer, version=0;
        function pick(user) {
            if(selected.has(user.id)) return; selected.add(user.id);
            const chip=button(user.name+' ×',()=>{selected.delete(user.id);chip.remove();load(true);},'tc-chip'); chosen.append(chip); load(true);
        }
        async function load(reset=true) {
            const v=++version, query=search.value.trim();
            if(reset)cursor=null;
            const data=teamMembers ? {users:teamMembers.filter(u=>u.name.toLowerCase().includes(query.toLowerCase())),next_cursor:null}
                : await api('/teamchat/directory?limit=30&q='+encodeURIComponent(query)+(cursor?'&cursor='+encodeURIComponent(cursor):''));
            if(gen!==generation||v!==version||!dialog.open)return;
            if(reset)results.replaceChildren();
            for(const user of data.users) if(!excluded.includes(user.id)&&!selected.has(user.id)&&user.active!==false) {
                const b=button(user.name,()=>pick(user),'tc-picker-user'); b.dataset.pickUser=String(user.id); results.append(b);
            }
            cursor=data.next_cursor;more.hidden=!cursor;
        }
        search.oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>load().catch(error),250);};
        load().catch(error);
        return selected;
    }
    async function refresh() {
        if (refreshing || state.stopped) return;
        refreshing = true;
        try {
            let cursor = null; const next = new Map();
            do {
                const page = await api('/teams?limit=50'+(cursor?'&cursor='+encodeURIComponent(cursor):''));
                page.teams.forEach(team=>next.set(team.id,team)); cursor=page.next_cursor;
            } while(cursor);
            teams.clear();next.forEach((v,k)=>teams.set(k,v));
            for (const id of expanded) {
                if(!teams.has(id)){expanded.delete(id);continue;}
                await loadChannels(id);
            }
            renderTeams();
        } finally {refreshing=false;}
    }
    async function loadChannels(id) {
        let cursor=null, channels=[];
        do {
            const page=await api('/teams/'+id+'/channels?limit=50'+(cursor?'&cursor='+encodeURIComponent(cursor):''));
            channels.push(...page.channels);cursor=page.next_cursor;
        }while(cursor);
        const team=teams.get(id);if(!team)return;team.channels=channels;
        channels.forEach(c=>state.conversations.set(c.id,c));
    }
    function repaint() {
        $('[data-team-tree]').querySelectorAll('[data-channel]').forEach(node=>{
            const c=state.conversations.get(node.dataset.channel);
            if(c)node.textContent=(c.visibility==='private'?'▣ ':'# ')+c.peer.name+(c.unread_count?' · '+c.unread_count:'');
            else node.remove();
        });
    }
    function renderTeams() {
        const tree=$('[data-team-tree]'); tree.replaceChildren();
        for(const team of teams.values()) {
            const section=el('section','tc-team'), bar=el('div','tc-team-bar');
            const toggle=button((expanded.has(team.id)?'▾ ':'▸ ')+team.name,async()=>{
                if(expanded.has(team.id))expanded.delete(team.id);else{expanded.add(team.id);await loadChannels(team.id);}renderTeams();
            });
            toggle.setAttribute('aria-expanded',String(expanded.has(team.id)));toggle.dataset.team=team.id;
            bar.append(toggle,button(t.manage,()=>manage('team',team.id,team),'tc-team-settings'));section.append(bar);
            if(expanded.has(team.id))for(const c of team.channels||[]){
                const channel=button((c.visibility==='private'?'▣ ':'# ')+c.peer.name+(c.unread_count?' · '+c.unread_count:''),()=>{
                    state.conversations.set(c.id,c);return openConversation(c.id);
                },'tc-channel');channel.dataset.channel=c.id;section.append(channel);
            }
            tree.append(section);
        }
        if(!teams.size)tree.append(el('p','tc-empty',t.none));
    }
    async function create(kind, teamId=null) {
        const gen=begin(t['create_'+kind]), form=el('form','tc-space-form');panel.querySelector('[data-dialog-loading]')?.remove();panel.append(form);
        const name=field(form,t.name,'name'), description=field(form,t.description,'description','',true);
        let visibility=null, teamMembers=null;
        if(kind==='channel'){
            const l=el('label','tc-field',t.visibility);visibility=el('select');visibility.name='visibility';
            for(const v of ['standard','private']){const o=el('option','',t[v]);o.value=v;visibility.append(o);}l.append(visibility);form.append(l);
            teamMembers=(await api('/spaces/team/'+teamId+'/members')).members;
            if(gen!==generation)return;
        }
        const selected=picker(form,gen,[configUser()],teamMembers);
        if (visibility) {
            const derived=el('p','tc-policy',t.standard_membership);form.append(derived);
            const toggle=()=>{form.querySelector('.tc-picker').hidden=visibility.value==='standard';derived.hidden=visibility.value!=='standard';};
            visibility.onchange=toggle;toggle();
        }
        form.append(el('p','tc-policy',t.history_policy));
        const submit=el('button','tc-primary',t.create);submit.type='submit';form.append(submit);const status=feedback(form);
        let command=crypto.randomUUID(), signature=null;
        form.onsubmit=e=>{
            e.preventDefault();
            const payload={kind,name:name.value,description:description.value,members:kind==='channel'&&visibility.value==='standard'?[]:[...selected],...(kind==='channel'?{team_id:teamId,visibility:visibility.value}:{})};
            const next=JSON.stringify(payload);if(signature!==null&&signature!==next)command=crypto.randomUUID();signature=next;
            perform(form,status,async()=>{
                const result=await api('/spaces','POST',{...payload,command_id:command});
                await refresh();await listMore(true);dialog.close();
                if(kind==='team'){switchView('team');expanded.add(result.id);await loadChannels(result.id);renderTeams();}
                else{switchView(kind);state.conversations.set(result.id,result);await openConversation(result.id);}
                toast(t.saved);
            }).catch(()=>{});
        };
        name.focus();
    }
    const configUser = () => JSON.parse(document.querySelector('#teamchat-config').textContent).userId;
    async function manage(scope,id,resource) {
        const gen=begin(t.manage+' · '+(resource.name||resource.peer.name));
        const [roster,changes]=await Promise.all([api('/spaces/'+scope+'/'+id+'/members'),api('/spaces/'+scope+'/'+id+'/changes?limit=30')]);
        if(gen!==generation||!dialog.open)return;
        const presenceIds=roster.members.filter(m=>m.active).map(m=>m.id);
        const presence=presenceIds.length?await api('/presence?'+presenceIds.map(id=>'user_ids[]='+id).join('&')):[];
        if(gen!==generation||!dialog.open)return;
        panel.querySelector('[data-dialog-loading]')?.remove();
        const online=new Map(presence.map(p=>[p.user_id,p.online]));
        const me=roster.members.find(m=>m.id===configUser()), manager=['owner','admin'].includes(me?.role);
        const form=el('form','tc-space-form');panel.append(form);
        const name=field(form,t.name,'name',resource.name||resource.peer.name), description=field(form,t.description,'description',resource.description||'',true);
        const archived=el('input');archived.type='checkbox';archived.checked=resource.archived;
        const archiveLabel=el('label','tc-checkbox',t.archived);archiveLabel.prepend(archived);form.append(archiveLabel);
        name.disabled=description.disabled=archived.disabled=!manager;
        const save=el('button','tc-primary',t.save);save.type='submit';save.disabled=!manager;form.append(save);
        const status=feedback(form);
        let saveCommand=crypto.randomUUID(), lastSave=null;
        form.onsubmit=e=>{e.preventDefault();const data={name:name.value,description:description.value,archived:archived.checked};
            const signature=JSON.stringify(data);if(lastSave!==null&&lastSave!==signature)saveCommand=crypto.randomUUID();lastSave=signature;
            perform(form,status,async()=>{await api('/spaces/'+scope+'/'+id,'PUT',{...data,command_id:saveCommand});await after();}).catch(()=>{});
        };
        async function after() {await refresh();await listMore(true);if(state.selected)await refreshConversation(state.selected);}
        async function mutation(userId,operation,role='member',commandId=crypto.randomUUID()){
            if(['remove','transfer'].includes(operation)&&!window.confirm(t.confirm_change))return;
            await perform(panel,status,async()=>{
                await api('/spaces/'+scope+'/'+id+'/members','POST',{user_id:userId,operation,role,command_id:commandId});
                await after();
                if(userId===configUser()&&operation==='remove')dialog.close();
                else await manage(scope,id,scope==='team'?await api('/teams/'+id):await api('/conversations/'+id));
            });
        }
        const list=el('div','tc-members');list.append(el('h3','',t.members));
        for(const user of roster.members){
            const item=el('div','tc-member'), label=el('span','',user.name+' · '+t[user.role]+' · '+(online.get(user.id)?t.online:t.offline));
            item.append(label);item.dataset.member=String(user.id);
            if(!roster.derived&&user.role!=='owner'&&(manager||user.id===configUser())){
                const removeId=crypto.randomUUID();item.append(button(user.id===configUser()?t.leave:t.remove,()=>mutation(user.id,'remove','member',removeId)));
                if(me.role==='owner'){
                    const roleId=crypto.randomUUID(),transferId=crypto.randomUUID();
                    item.append(button(user.role==='admin'?t.make_member:t.make_admin,()=>mutation(user.id,'role',user.role==='admin'?'member':'admin',roleId)));
                    item.append(button(t.transfer,()=>mutation(user.id,'transfer','member',transferId)));
                }
            }
            list.append(item);
        }
        panel.append(list);
        if(manager&&!roster.derived){
            const addForm=el('div','tc-add-members');panel.append(addForm);
            const teamMembers=scope==='conversation'&&resource.team_id?(await api('/spaces/team/'+resource.team_id+'/members')).members:null;
            if(gen!==generation)return;
            const selected=picker(addForm,gen,roster.members.map(m=>m.id),teamMembers), commands=new Map();
            addForm.append(button(t.add_members,async()=>{
                await perform(panel,status,async()=>{
                    for(const userId of selected){if(!commands.has(userId))commands.set(userId,crypto.randomUUID());await api('/spaces/'+scope+'/'+id+'/members','POST',{user_id:userId,operation:'add',command_id:commands.get(userId)});}
                    await after();await manage(scope,id,scope==='team'?await api('/teams/'+id):await api('/conversations/'+id));
                });
            },'tc-primary'));
        }
        if(scope==='team'&&manager)panel.append(button(t.create_channel,()=>create('channel',id),'tc-primary'));
        const audit=el('details','tc-changes');audit.append(el('summary','',t.member_changes));
        for(const change of changes.changes) {
            const user=roster.members.find(m=>m.id===change.user_id);audit.append(el('p','',(change.user_name||user?.name||'#'+change.user_id)+' · '+(change.active?t.joined:t.left)+' · '+t[change.role]));
        }
        panel.append(audit,el('p','tc-policy',t.history_policy));
    }
    root.querySelectorAll('[data-space-tab]').forEach(n=>n.onclick=()=>switchView(n.dataset.spaceTab));
    $('[data-create-group]').onclick=()=>create('group').catch(error);
    $('[data-create-team]').onclick=()=>create('team').catch(error);
    $('[data-manage]').onclick=()=>{const c=state.conversations.get(state.selected);if(c)manage('conversation',c.id,c).catch(error);};
    dialog.addEventListener('close',()=>{generation++;});
    return {refresh,switchView,repaint};
}
