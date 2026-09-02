(()=>{
'use strict';
const shell=document.querySelector('[data-copilot-config]');if(!shell)return;
let cfg={};try{cfg=JSON.parse(shell.getAttribute('data-copilot-config')||'{}')}catch(e){return}
const $=(s,r=shell)=>r.querySelector(s),$$=(s,r=shell)=>Array.from(r.querySelectorAll(s));
const thread=$('[data-copilot-thread]'),input=$('[data-copilot-input]'),chips=$('[data-copilot-chips]'),menu=$('[data-copilot-mention-menu]'),preview=$('[data-copilot-preview]'),pageBox=$('[data-copilot-page-context]');
const key=`erpsmart:copilot:${cfg.workspace_id}:${cfg.user_id}:${cfg.company_id}`;let state={open:false,conversation_id:0,refs:[]};try{state={...state,...JSON.parse(localStorage.getItem(key)||'{}')}}catch(e){}
state.refs=Array.isArray(state.refs)?state.refs.filter(x=>x&&x.type&&Number(x.id)>0):[];
const save=()=>localStorage.setItem(key,JSON.stringify({open:state.open,conversation_id:state.conversation_id,refs:state.refs}));
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
function open(v=true){state.open=v;shell.classList.toggle('is-open',v);document.querySelector('.copilot-backdrop')?.classList.toggle('is-open',v);document.body.classList.toggle('copilot-open',v);shell.setAttribute('aria-hidden',v?'false':'true');save();if(v)setTimeout(()=>input.focus(),60)}
document.querySelectorAll('[data-copilot-open]').forEach(b=>b.addEventListener('click',()=>open(true)));document.querySelectorAll('[data-copilot-close]').forEach(b=>b.addEventListener('click',()=>open(false)));
function msg(kind,text,id=''){const d=document.createElement('div');d.className=`copilot-message ${kind}`;if(id)d.dataset.jobMessage=id;d.textContent=text;thread.appendChild(d);thread.scrollTop=thread.scrollHeight;return d}
function renderPage(){const es=Array.isArray(cfg.current_page_entities)?cfg.current_page_entities:[];pageBox.innerHTML=es.map(e=>`<span class="copilot-context-chip">صفحه جاری: ${esc(e.icon||'')} ${esc(e.code||e.label||'')} ${esc(e.code&&e.label?'• '+e.label:'')}</span>`).join('')}
function renderChips(){chips.innerHTML='';state.refs.forEach((r,i)=>{const el=document.createElement('span');el.className='copilot-chip';el.innerHTML=`<span class="copilot-chip-main" title="پیش‌نمایش">${esc(r.icon||'')} ${esc(r.label||r.code||`${r.type}#${r.id}`)}</span><button type="button" aria-label="حذف">×</button>`;el.querySelector('.copilot-chip-main').onclick=()=>showPreview(r);el.querySelector('button').onclick=()=>{state.refs.splice(i,1);save();renderChips();hidePreview()};chips.appendChild(el)})}
function hidePreview(){preview.hidden=true;preview.innerHTML=''}
async function showPreview(r){try{const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','preview');u.searchParams.set('company_id',cfg.company_id);u.searchParams.set('type',r.type);u.searchParams.set('id',r.id);const j=await fetch(u,{credentials:'same-origin'}).then(x=>x.json());if(!j.ok)throw new Error(j.error||'preview_failed');const e=j.entity;preview.innerHTML=`<div class="copilot-preview-head"><div><b>${esc(e.icon||'')} ${esc(e.label||'')}</b><div class="muted">${esc(e.code||'')} ${esc(e.subtitle||'')}</div></div><button class="btn tiny" type="button" data-preview-close>×</button></div><div class="copilot-preview-facts">${(e.facts||[]).map(f=>`<div><small>${esc(f.label)}</small><b>${esc(f.value)}</b></div>`).join('')}</div><div class="copilot-preview-actions"><a class="btn tiny" href="${esc(e.deep_link||'#')}">باز کردن رکورد</a></div>`;preview.hidden=false;preview.querySelector('[data-preview-close]').onclick=hidePreview}catch(e){preview.hidden=false;preview.textContent='پیش‌نمایش در دسترس نیست.'}}
let searchTimer=0,searchSeq=0;
function mentionToken(){const pos=input.selectionStart??input.value.length;const left=input.value.slice(0,pos);const m=left.match(/(^|\s)@([^@\n]{0,80})$/u);if(!m)return null;return{query:m[2].trim(),start:pos-m[2].length-1,end:pos}}
function mentionStatus(text,kind='muted'){menu.innerHTML=`<div class="${esc(kind)}" style="padding:8px">${esc(text)}</div>`;menu.hidden=false}
async function searchMention(q){
    const seq=++searchSeq;const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','search');u.searchParams.set('company_id',cfg.company_id);u.searchParams.set('q',q);
    let response,text,j;
    try{
        response=await fetch(u,{credentials:'same-origin',headers:{'Accept':'application/json'}});
        text=await response.text();
    }catch(e){
        if(seq===searchSeq)mentionStatus('اتصال به جست‌وجوی موجودیت برقرار نشد.','danger');
        return;
    }
    if(seq!==searchSeq)return;
    try{j=JSON.parse(text)}
    catch(e){
        mentionStatus(`پاسخ نامعتبر از جست‌وجو (HTTP ${response.status||0}).`,'danger');
        return;
    }
    if(!response.ok||!j||j.ok!==true){
        const code=(j&&j.error)?String(j.error):`http_${response.status||0}`;
        const rid=(j&&j.request_id)?` • request_id: ${j.request_id}`:'';
        mentionStatus(`خطای جست‌وجو: ${code}${rid}`,'danger');
        return;
    }
    renderMenu(j.results||[],j);
}
function renderMenu(rows,meta={}){
    menu.innerHTML='';
    if(meta.degraded){const w=document.createElement('div');w.className='copilot-mention-group';w.textContent='برخی دسته‌ها موقتاً در دسترس نیستند';menu.appendChild(w)}
    if(!rows.length){const d=document.createElement('div');d.className='muted';d.style.padding='8px';d.textContent='موردی پیدا نشد.';menu.appendChild(d);menu.hidden=false;return}
    let last='';rows.forEach(r=>{if(r.group!==last){last=r.group;const g=document.createElement('div');g.className='copilot-mention-group';g.textContent=last;menu.appendChild(g)}const b=document.createElement('button');b.type='button';b.className='copilot-mention-item';b.innerHTML=`<i>${esc(r.icon||'')}</i><span><b>${esc(r.label||r.code||'')}</b><small>${esc([r.code,r.subtitle].filter(Boolean).join(' • '))}</small></span>`;b.onclick=()=>selectMention(r);menu.appendChild(b)});menu.hidden=false
}
function selectMention(r){const t=mentionToken();if(t){const before=input.value.slice(0,t.start),after=input.value.slice(t.end);input.value=`${before}@${r.label||r.code||''} ${after}`;input.selectionStart=input.selectionEnd=(before+`@${r.label||r.code||''} `).length}if(!state.refs.some(x=>x.type===r.type&&Number(x.id)===Number(r.id)))state.refs.push({type:r.type,id:Number(r.id),label:r.label,code:r.code,icon:r.icon});menu.hidden=true;save();renderChips();input.focus()}
function queueMentionSearch(){const t=mentionToken();clearTimeout(searchTimer);if(!t){menu.hidden=true;return}mentionStatus('در حال جست‌وجو…');searchTimer=setTimeout(()=>searchMention(t.query),160)}
input.addEventListener('input',queueMentionSearch);input.addEventListener('compositionend',queueMentionSearch);input.addEventListener('focus',()=>{if(mentionToken())queueMentionSearch()});input.addEventListener('keydown',e=>{if(e.key==='Escape')menu.hidden=true;if((e.ctrlKey||e.metaKey)&&e.key==='Enter')send()});document.addEventListener('click',e=>{if(!shell.contains(e.target))menu.hidden=true});
async function loadConversation(){if(!state.conversation_id)return;const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','conversation');u.searchParams.set('conversation_id',state.conversation_id);try{const j=await fetch(u,{credentials:'same-origin'}).then(x=>x.json());if(!j.ok)return;thread.innerHTML='';(j.jobs||[]).forEach(x=>{msg('user',x.prompt||'');if(x.result_text)msg('ai',x.result_text);else if(x.error_text)msg('error',x.error_text);else msg('pending','در حال پردازش…',String(x.id))});thread.scrollTop=thread.scrollHeight}catch(e){}}
async function poll(jobId,node){for(let i=0;i<180;i++){await new Promise(r=>setTimeout(r,i?1100:300));const u=new URL(cfg.endpoint,location.href);u.searchParams.set('action','job');u.searchParams.set('job_id',jobId);try{const j=await fetch(u,{credentials:'same-origin'}).then(x=>x.json());if(!j.ok)continue;const x=j.job;if(x.terminal){node.classList.remove('pending');if(x.status==='succeeded'){node.classList.add('ai');node.textContent=x.result_text||'پاسخ بدون متن پایان یافت.'}else{node.classList.add('error');node.textContent=x.error_text||'پردازش ناموفق بود.'}thread.scrollTop=thread.scrollHeight;return}else{node.textContent=(x.live&&x.live.message)||'در حال پردازش…'}}catch(e){}}node.classList.remove('pending');node.classList.add('error');node.textContent='پیگیری پاسخ بیش از حد طول کشید؛ نتیجه در مرکز فرمان قابل مشاهده است.'}
async function send(){const prompt=input.value.trim();if(!prompt)return;const btn=$('[data-copilot-send]');btn.disabled=true;msg('user',prompt);const pending=msg('pending','در صف پردازش…');const refs=state.refs.map(({type,id})=>({type,id}));const pageRefs=(cfg.current_page_refs||[]).map(({type,id})=>({type,id}));const fd=new FormData();fd.set('action','queue');fd.set('csrf',cfg.csrf);fd.set('company_id',cfg.company_id);fd.set('conversation_id',state.conversation_id||'');fd.set('prompt',prompt);fd.set('context_refs_json',JSON.stringify(refs));fd.set('page_context_refs_json',JSON.stringify(pageRefs));try{const j=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:fd}).then(x=>x.json());if(!j.ok)throw new Error(j.error||'queue_failed');state.conversation_id=Number(j.conversation_id)||0;state.refs=[];save();renderChips();input.value='';pending.dataset.jobMessage=String(j.job_id);poll(j.job_id,pending)}catch(e){pending.classList.remove('pending');pending.classList.add('error');pending.textContent='ارسال ناموفق بود: '+e.message}finally{btn.disabled=false}}
$('[data-copilot-send]').addEventListener('click',send);
window.ERPSMART_COPILOT={open,attach(ref){if(!ref||!ref.type||!ref.id)return;open(true);if(!state.refs.some(x=>x.type===ref.type&&Number(x.id)===Number(ref.id)))state.refs.push(ref);save();renderChips();}};
document.addEventListener('click',e=>{const b=e.target.closest('[data-copilot-attach]');if(!b)return;e.preventDefault();let r={};try{r=JSON.parse(b.getAttribute('data-copilot-attach')||'{}')}catch(x){};window.ERPSMART_COPILOT.attach(r)});
renderPage();renderChips();if(state.open)open(true);loadConversation();
})();
