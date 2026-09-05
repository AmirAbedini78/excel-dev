(()=>{
'use strict';
const shell=document.querySelector('[data-copilot-config]');if(!shell)return;
let cfg={};try{cfg=JSON.parse(shell.getAttribute('data-copilot-config')||'{}')}catch(e){return}
const $=(s,r=shell)=>r.querySelector(s),$$=(s,r=shell)=>Array.from(r.querySelectorAll(s));
const thread=$('[data-copilot-thread]'),input=$('[data-copilot-input]'),chips=$('[data-copilot-chips]'),menu=$('[data-copilot-mention-menu]'),preview=$('[data-copilot-preview]'),pageBox=$('[data-copilot-page-context]');
const companySelect=$('[data-copilot-company]'),conversationSelect=$('[data-copilot-conversation]'),scopeName=$('[data-copilot-scope-name]');
const companies=Array.isArray(cfg.companies)?cfg.companies.map(c=>({id:Number(c.id)||0,name:String(c.name||'')})).filter(c=>c.id>0):[];
const companyById=id=>companies.find(c=>c.id===Number(id))||null;
const defaultCompanyId=companyById(cfg.company_id)?Number(cfg.company_id):(companies[0]?.id||0);
const globalKey=`erpsmart:copilot:v2:${cfg.workspace_id}:${cfg.user_id}`;
let state={open:false,scope_company_id:defaultCompanyId,conversation_ids:{},refs:[]};
try{state={...state,...JSON.parse(localStorage.getItem(globalKey)||'{}')}}catch(e){}
if(!companyById(state.scope_company_id))state.scope_company_id=defaultCompanyId;
state.conversation_ids=state.conversation_ids&&typeof state.conversation_ids==='object'?state.conversation_ids:{};
state.refs=Array.isArray(state.refs)?state.refs.filter(x=>x&&x.type&&Number(x.id)>0&&Number(x.company_id||state.scope_company_id)===Number(state.scope_company_id)):[];
if(!localStorage.getItem(globalKey)){
    const legacyKey=`erpsmart:copilot:${cfg.workspace_id}:${cfg.user_id}:${cfg.company_id}`;
    try{
        const legacy=JSON.parse(localStorage.getItem(legacyKey)||'{}');
        if(legacy&&typeof legacy==='object'){
            state.open=!!legacy.open;
            if(Number(legacy.conversation_id)>0)state.conversation_ids[String(defaultCompanyId)]=Number(legacy.conversation_id);
            if(Array.isArray(legacy.refs))state.refs=legacy.refs.filter(x=>x&&x.type&&Number(x.id)>0).map(x=>({...x,company_id:defaultCompanyId,company_name:companyById(defaultCompanyId)?.name||''}));
        }
    }catch(e){}
}
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const scopeId=()=>Number(state.scope_company_id)||0;
const scopeCompany=()=>companyById(scopeId());
const conversationId=()=>Number(state.conversation_ids[String(scopeId())]||0);
const setConversationId=id=>{state.conversation_ids[String(scopeId())]=Number(id)||0;save()};
const save=()=>localStorage.setItem(globalKey,JSON.stringify({open:!!state.open,scope_company_id:scopeId(),conversation_ids:state.conversation_ids,refs:state.refs}));
function open(v=true){state.open=!!v;shell.classList.toggle('is-open',state.open);document.querySelector('.copilot-backdrop')?.classList.toggle('is-open',state.open);document.body.classList.toggle('copilot-open',state.open);shell.setAttribute('aria-hidden',state.open?'false':'true');save();if(state.open)setTimeout(()=>input.focus(),60)}
document.querySelectorAll('[data-copilot-open]').forEach(b=>b.addEventListener('click',()=>open(true)));document.querySelectorAll('[data-copilot-close]').forEach(b=>b.addEventListener('click',()=>open(false)));
function msg(kind,text,id=''){const d=document.createElement('div');d.className=`copilot-message ${kind}`;if(id)d.dataset.jobMessage=id;d.textContent=text;thread.appendChild(d);thread.scrollTop=thread.scrollHeight;return d}
function renderWelcome(extra=''){
    thread.innerHTML='';const box=document.createElement('div');box.className='copilot-welcome';
    const b=document.createElement('b');b.textContent='دستیار آماده است.';const s=document.createElement('span');s.textContent=extra||'با @ در همه شرکت‌های این محیط کاری جست‌وجو کن؛ گفتگو و عملیات هر شرکت جدا نگه داشته می‌شود.';
    box.append(b,s);thread.appendChild(box);
}
function renderScope(){
    const c=scopeCompany();if(companySelect)companySelect.value=String(scopeId());if(scopeName)scopeName.textContent=`گفتگو: ${c?.name||'بدون شرکت'}`;renderPage();
}
function renderPage(){
    const pageCid=Number(cfg.company_id)||0,chatCid=scopeId();
    if(pageCid!==chatCid){pageBox.innerHTML=`<span class="copilot-context-note">زمینه صفحه جاری متعلق به ${esc(cfg.company_name||'شرکت دیگری')} است و وارد گفتگوی ${esc(scopeCompany()?.name||'')} نمی‌شود.</span>`;return}
    const es=Array.isArray(cfg.current_page_entities)?cfg.current_page_entities:[];
    pageBox.innerHTML=es.map(e=>`<span class="copilot-context-chip">صفحه جاری: ${esc(e.icon||'')} ${esc(e.code||e.label||'')} ${esc(e.code&&e.label?'• '+e.label:'')}</span>`).join('')
}
function renderChips(){
    chips.innerHTML='';state.refs.forEach((r,i)=>{const el=document.createElement('span');el.className='copilot-chip';el.innerHTML=`<span class="copilot-chip-main" title="پیش‌نمایش">${esc(r.icon||'')} ${esc(r.label||r.code||`${r.type}#${r.id}`)}<small>${esc(r.company_name||scopeCompany()?.name||'')}</small></span><button type="button" aria-label="حذف">×</button>`;el.querySelector('.copilot-chip-main').onclick=()=>showPreview(r);el.querySelector('button').onclick=()=>{state.refs.splice(i,1);save();renderChips();hidePreview()};chips.appendChild(el)})
}
function hidePreview(){preview.hidden=true;preview.innerHTML=''}
async function responseJson(response){const text=await response.text();try{return JSON.parse(text)}catch(e){throw new Error(`invalid_json_HTTP_${response.status||0}`)}}
async function showPreview(r){
    try{
        if(Number(r.company_id||scopeId())!==scopeId())await setScope(Number(r.company_id),{announce:false});
        const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','preview');u.searchParams.set('company_id',scopeId());u.searchParams.set('type',r.type);u.searchParams.set('id',r.id);
        const response=await fetch(u,{credentials:'same-origin',headers:{'Accept':'application/json'}});const j=await responseJson(response);if(!response.ok||!j.ok)throw new Error(j.error||'preview_failed');const e=j.entity;
        preview.innerHTML=`<div class="copilot-preview-head"><div><b>${esc(e.icon||'')} ${esc(e.label||'')}</b><div class="muted">${esc(e.company_name||'')} ${e.code?'• '+esc(e.code):''} ${e.subtitle?'• '+esc(e.subtitle):''}</div></div><button class="btn tiny" type="button" data-preview-close>×</button></div><div class="copilot-preview-facts">${(e.facts||[]).map(f=>`<div><small>${esc(f.label)}</small><b>${esc(f.value)}</b></div>`).join('')}</div><div class="copilot-preview-actions"><a class="btn tiny" href="${esc(e.deep_link||'#')}">باز کردن رکورد</a></div>`;
        preview.hidden=false;preview.querySelector('[data-preview-close]').onclick=hidePreview
    }catch(e){preview.hidden=false;preview.textContent='پیش‌نمایش در دسترس نیست.'}
}
let conversationSeq=0;
async function refreshConversations({restoreLatest=true,load=true}={}){
    const seq=++conversationSeq,cid=scopeId();if(!cid){renderWelcome('شرکت گفتگو مشخص نیست.');return}
    const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','conversations');u.searchParams.set('company_id',cid);u.searchParams.set('limit','15');
    try{
        const response=await fetch(u,{credentials:'same-origin',headers:{'Accept':'application/json'}});const j=await responseJson(response);if(seq!==conversationSeq||cid!==scopeId())return;if(!response.ok||!j.ok)throw new Error(j.error||'conversations_failed');
        const rows=Array.isArray(j.conversations)?j.conversations:[];conversationSelect.innerHTML='<option value="0">گفتگوی جدید</option>';
        rows.forEach(r=>{const o=document.createElement('option');o.value=String(Number(r.id)||0);const title=String(r.title||r.last_prompt||`گفتگو #${r.id}`);o.textContent=title.length>46?title.slice(0,46)+'…':title;conversationSelect.appendChild(o)});
        let wanted=conversationId();if(!rows.some(r=>Number(r.id)===wanted)){wanted=restoreLatest&&rows.length?Number(rows[0].id):0;setConversationId(wanted)}conversationSelect.value=String(wanted);
        if(load)await loadConversation(wanted,cid)
    }catch(e){if(seq===conversationSeq&&cid===scopeId()){conversationSelect.innerHTML='<option value="0">گفتگوی جدید</option>';renderWelcome('بازیابی گفتگوهای قبلی در دسترس نیست؛ می‌توانی گفتگوی جدید را شروع کنی.')}}
}
async function loadConversation(id=conversationId(),cid=scopeId()){
    id=Number(id)||0;if(!id){renderWelcome();return}
    const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','conversation');u.searchParams.set('company_id',cid);u.searchParams.set('conversation_id',id);
    try{
        const response=await fetch(u,{credentials:'same-origin',headers:{'Accept':'application/json'}});const j=await responseJson(response);if(cid!==scopeId()||id!==conversationId())return;if(!response.ok||!j.ok)throw new Error(j.error||'conversation_failed');
        thread.innerHTML='';const jobs=Array.isArray(j.jobs)?j.jobs:[];if(!jobs.length){renderWelcome('این گفتگو هنوز پیامی ندارد.');return}
        jobs.forEach(x=>{msg('user',x.prompt||'');if(x.result_text)msg('ai',x.result_text);else if(x.error_text)msg('error',x.error_text);else msg('pending','در حال پردازش…',String(x.id))});thread.scrollTop=thread.scrollHeight
    }catch(e){if(cid===scopeId()&&id===conversationId())renderWelcome('این گفتگو فعلاً قابل بازیابی نیست.')}
}
async function setScope(cid,{announce=true,restoreLatest=true}={}){
    cid=Number(cid)||0;const company=companyById(cid);if(!company||cid===scopeId()){renderScope();return}
    state.scope_company_id=cid;state.refs=[];save();renderScope();renderChips();hidePreview();renderWelcome(announce?`زمینه گفتگو به «${company.name}» تغییر کرد؛ در حال بازیابی آخرین گفتگو…`:'در حال بازیابی گفتگو…');
    await refreshConversations({restoreLatest,load:true})
}
let searchTimer=0,searchSeq=0,lastMentionQuery='';
function mentionToken(){const pos=input.selectionStart??input.value.length;const left=input.value.slice(0,pos);const m=left.match(/(^|\s)@([^@\n]{0,80})$/u);if(!m)return null;return{query:m[2].trim(),start:pos-m[2].length-1,end:pos}}
function mentionStatus(text,kind='muted'){menu.innerHTML=`<div class="copilot-mention-status ${esc(kind)}">${esc(text)}</div>`;menu.hidden=false}
async function searchMention(q){
    const seq=++searchSeq;lastMentionQuery=q;const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','search');u.searchParams.set('company_id',scopeId());u.searchParams.set('q',q);
    let response,j;try{response=await fetch(u,{credentials:'same-origin',headers:{'Accept':'application/json'}});j=await responseJson(response)}catch(e){if(seq===searchSeq)mentionStatus('اتصال به جست‌وجوی سراسری برقرار نشد.','danger');return}
    if(seq!==searchSeq)return;if(!response.ok||!j||j.ok!==true){const code=(j&&j.error)?String(j.error):`http_${response.status||0}`;const rid=(j&&j.request_id)?` • ${j.request_id}`:'';mentionStatus(`خطای جست‌وجو: ${code}${rid}`,'danger');return}
    renderMenu(j.results||[],j,q)
}
function renderMenu(rows,meta={},query=''){
    menu.innerHTML='';if(meta.degraded){const w=document.createElement('div');w.className='copilot-mention-warning';w.textContent='بعضی منابع موقتاً پاسخ ندادند؛ نتایج سالم نمایش داده شده‌اند.';menu.appendChild(w)}
    const catalog=Array.isArray(meta.catalog)?meta.catalog:[];const byCategory=new Map();rows.forEach(r=>{const key=String(r.category||'other');if(!byCategory.has(key))byCategory.set(key,[]);byCategory.get(key).push(r)});
    if(!catalog.length&&!rows.length){mentionStatus('موردی در محیط کاری پیدا نشد.');return}
    let opened=false;catalog.forEach(cat=>{
        const catRows=byCategory.get(String(cat.key))||[];const details=document.createElement('details');details.className='copilot-mention-category';
        if(query!==''&&catRows.length&&!opened){details.open=true;opened=true}
        const summary=document.createElement('summary');summary.innerHTML=`<span>${esc(cat.icon||'•')} ${esc(cat.title||'دسته')}</span><b>${catRows.length}</b>`;details.appendChild(summary);
        const body=document.createElement('div');body.className='copilot-mention-category-body';
        const types=Array.isArray(cat.types)?cat.types:[];types.forEach(type=>{
            const typeRows=catRows.filter(r=>String(r.type)===String(type.type));const section=document.createElement('div');section.className='copilot-mention-type';
            const head=document.createElement('div');head.className='copilot-mention-type-head';head.innerHTML=`<span>${esc(type.icon||'')} ${esc(type.group||type.title||type.type)}</span><small>${typeRows.length}</small>`;section.appendChild(head);
            if(typeRows.length){typeRows.forEach(r=>{const b=document.createElement('button');b.type='button';b.className='copilot-mention-item';b.innerHTML=`<i>${esc(r.icon||'')}</i><span><b>${esc(r.label||r.code||'')}</b><small>${esc([r.code,r.subtitle].filter(Boolean).join(' • '))}</small></span><em>${esc(r.company_name||'')}</em>`;b.onclick=()=>selectMention(r);section.appendChild(b)})}
            else{const empty=document.createElement('div');empty.className='copilot-mention-empty';empty.textContent=query?'نتیجه‌ای ندارد':'داده‌ای ثبت نشده';section.appendChild(empty)}
            body.appendChild(section)
        });details.appendChild(body);menu.appendChild(details)
    });
    if(!rows.length&&query){const d=document.createElement('div');d.className='copilot-mention-empty global';d.textContent='برای این عبارت در هیچ‌کدام از شرکت‌های محیط کاری موردی پیدا نشد.';menu.prepend(d)}menu.hidden=false
}
async function selectMention(r){
    const token=mentionToken();const targetCid=Number(r.company_id)||scopeId();if(targetCid!==scopeId())await setScope(targetCid,{announce:true,restoreLatest:true});
    if(token){const before=input.value.slice(0,token.start),after=input.value.slice(token.end);const label=r.label||r.code||'';input.value=`${before}@${label} ${after}`;input.selectionStart=input.selectionEnd=(before+`@${label} `).length}
    if(!state.refs.some(x=>x.type===r.type&&Number(x.id)===Number(r.id)))state.refs.push({type:r.type,id:Number(r.id),label:r.label,code:r.code,icon:r.icon,company_id:scopeId(),company_name:r.company_name||scopeCompany()?.name||''});
    searchSeq++;menu.hidden=true;save();renderChips();input.focus()
}
function queueMentionSearch(){const t=mentionToken();clearTimeout(searchTimer);if(!t){menu.hidden=true;return}mentionStatus(t.query?'در حال جست‌وجوی همه شرکت‌ها…':'دسته‌بندی موجودیت‌ها');searchTimer=setTimeout(()=>searchMention(t.query),130)}
input.addEventListener('input',queueMentionSearch);input.addEventListener('compositionend',queueMentionSearch);input.addEventListener('focus',()=>{if(mentionToken())queueMentionSearch()});input.addEventListener('keydown',e=>{if(e.key==='Escape')menu.hidden=true;if((e.ctrlKey||e.metaKey)&&e.key==='Enter')send()});document.addEventListener('click',e=>{if(!shell.contains(e.target))menu.hidden=true});
async function poll(jobId,node,cid){
    for(let i=0;i<180;i++){
        await new Promise(r=>setTimeout(r,i?1100:300));const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','job');u.searchParams.set('company_id',cid);u.searchParams.set('job_id',jobId);
        try{const response=await fetch(u,{credentials:'same-origin'});const j=await responseJson(response);if(!response.ok||!j.ok)continue;const x=j.job;if(x.terminal){if(cid===scopeId()&&document.body.contains(node)){node.classList.remove('pending');if(x.status==='succeeded'){node.classList.add('ai');node.textContent=x.result_text||'پاسخ بدون متن پایان یافت.'}else{node.classList.add('error');node.textContent=x.error_text||'پردازش ناموفق بود.'}thread.scrollTop=thread.scrollHeight}if(cid===scopeId())refreshConversations({restoreLatest:false,load:false});return}else if(cid===scopeId()&&document.body.contains(node)){node.textContent=(x.live&&x.live.message)||'در حال پردازش…'}}catch(e){}
    }
    if(cid===scopeId()&&document.body.contains(node)){node.classList.remove('pending');node.classList.add('error');node.textContent='پیگیری پاسخ بیش از حد طول کشید؛ نتیجه در مرکز فرمان قابل مشاهده است.'}
}
async function send(){
    const prompt=input.value.trim();if(!prompt)return;const cid=scopeId();if(!cid)return;const btn=$('[data-copilot-send]');btn.disabled=true;msg('user',prompt);const pending=msg('pending','در صف پردازش…');
    const refs=state.refs.filter(r=>Number(r.company_id||cid)===cid).map(({type,id})=>({type,id}));const pageRefs=Number(cfg.company_id)===cid?(cfg.current_page_refs||[]).map(({type,id})=>({type,id})):[];
    const fd=new FormData();fd.set('action','queue');fd.set('csrf',cfg.csrf);fd.set('company_id',cid);fd.set('conversation_id',conversationId()||'');fd.set('prompt',prompt);fd.set('context_refs_json',JSON.stringify(refs));fd.set('page_context_refs_json',JSON.stringify(pageRefs));
    try{
        const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:fd,headers:{'Accept':'application/json'}});const j=await responseJson(response);if(!response.ok||!j.ok)throw new Error(j.error||'queue_failed');
        if(cid!==scopeId())return;setConversationId(Number(j.conversation_id)||0);state.refs=[];save();renderChips();input.value='';input.focus();conversationSelect.value=String(conversationId());refreshConversations({restoreLatest:false,load:false});pending.dataset.jobMessage=String(j.job_id);poll(j.job_id,pending,cid)
    }catch(e){if(cid===scopeId()&&document.body.contains(pending)){pending.classList.remove('pending');pending.classList.add('error');pending.textContent='ارسال ناموفق بود: '+e.message}}finally{btn.disabled=false}
}
$('[data-copilot-send]').addEventListener('click',send);
companySelect?.addEventListener('change',()=>setScope(Number(companySelect.value),{announce:true,restoreLatest:true}));
conversationSelect?.addEventListener('change',()=>{setConversationId(Number(conversationSelect.value)||0);state.refs=[];save();renderChips();hidePreview();loadConversation()});
$('[data-copilot-new]')?.addEventListener('click',()=>{setConversationId(0);state.refs=[];save();renderChips();hidePreview();conversationSelect.value='0';renderWelcome('گفتگوی جدید آماده است.');input.focus()});
$$('[data-copilot-template]').forEach(b=>b.addEventListener('click',()=>{const t=String(b.getAttribute('data-copilot-template')||'').trim();if(!t)return;input.value=input.value.trim()?input.value.trim()+`\n${t}`:t;input.focus();input.selectionStart=input.selectionEnd=input.value.length}));
window.ERPSMART_COPILOT={open,async attach(ref){if(!ref||!ref.type||!ref.id)return;const targetCid=Number(ref.company_id||cfg.company_id||scopeId());if(targetCid!==scopeId())await setScope(targetCid,{announce:true,restoreLatest:true});if(!state.refs.some(x=>x.type===ref.type&&Number(x.id)===Number(ref.id)))state.refs.push({...ref,company_id:scopeId(),company_name:ref.company_name||scopeCompany()?.name||''});save();renderChips();open(true);input.focus()}};
document.addEventListener('click',e=>{const b=e.target.closest('[data-copilot-attach]');if(!b)return;e.preventDefault();let r={};try{r=JSON.parse(b.getAttribute('data-copilot-attach')||'{}')}catch(x){};window.ERPSMART_COPILOT.attach(r)});
renderScope();renderChips();if(state.open)open(true);refreshConversations({restoreLatest:true,load:true});
})();
