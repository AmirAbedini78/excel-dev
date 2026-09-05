(()=>{
'use strict';
const shell=document.querySelector('[data-copilot-config]');if(!shell)return;
let cfg={};try{cfg=JSON.parse(shell.getAttribute('data-copilot-config')||'{}')}catch(e){return}
const skills=Array.isArray(cfg.skills)?cfg.skills.filter(x=>x&&x.id&&x.title):[];
const composer=shell.querySelector('.copilot-composer');
const input=shell.querySelector('[data-copilot-input]');
const mention=shell.querySelector('[data-copilot-mention-menu]');
if(!composer||!input)return;

// Cycle 12 stylesheet is additive so Cycle 11 cache contracts remain untouched.
if(!document.querySelector('link[data-copilot-cycle12-css]')){
    const link=document.createElement('link');link.rel='stylesheet';link.href='assets/business-copilot-cycle12.css?v=10.9.0';link.dataset.copilotCycle12Css='1';document.head.appendChild(link);
}

const hint=shell.querySelector('.copilot-compose-actions .muted');
if(hint)hint.textContent='@ موجودیت • / مهارت • داده‌ها هر بار از ERP تازه خوانده می‌شوند';

const menu=document.createElement('div');
menu.className='copilot-skill-menu';
menu.dataset.copilotSkillMenu='1';
menu.hidden=true;
if(mention&&mention.parentNode===composer)composer.insertBefore(menu,mention.nextSibling);
else composer.insertBefore(menu,input);

const norm=s=>String(s??'').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,' ').toLowerCase().trim();
function slashToken(){
    const pos=input.selectionStart??input.value.length;
    const left=input.value.slice(0,pos);
    const m=left.match(/(^|\s)\/([a-zA-Z0-9\-\u0600-\u06FF]{0,48})$/u);
    if(!m)return null;
    return {query:m[2].trim(),start:pos-m[2].length-1,end:pos};
}
function hide(){menu.hidden=true}
function fitFloating(el){
    if(!el||el.hidden)return;
    const side=shell.getBoundingClientRect(),comp=composer.getBoundingClientRect();
    const available=Math.max(32,Math.floor(comp.top-side.top-12));
    el.style.maxHeight=Math.min(420,available)+'px';
}
function el(tag,cls,text){
    const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=String(text);return node;
}
function selectSkill(skill){
    const token=slashToken();if(!token)return;
    const before=input.value.slice(0,token.start),after=input.value.slice(token.end);
    const replacement=`/${skill.id} `;
    input.value=before+replacement+after;
    const cursor=(before+replacement).length;
    input.selectionStart=input.selectionEnd=cursor;
    hide();input.focus();
}
function render(){
    const token=slashToken();if(!token||!skills.length){hide();return}
    if(mention)mention.hidden=true;
    const q=norm(token.query);
    const filtered=skills.filter(s=>!q||norm(`${s.id} ${s.title} ${s.description} ${s.category_title}`).includes(q));
    menu.innerHTML='';
    const top=el('div','copilot-skill-head');
    top.append(el('b','',q?'مهارت‌های مرتبط':'مهارت‌های Business Copilot'),el('small','',`${filtered.length} مورد`));
    menu.appendChild(top);
    if(!filtered.length){
        menu.appendChild(el('div','copilot-skill-empty','مهارتی با این عبارت پیدا نشد.'));
        menu.hidden=false;fitFloating(menu);return;
    }
    const categories=new Map();
    filtered.forEach(s=>{
        const key=String(s.category||'other');
        if(!categories.has(key))categories.set(key,{title:s.category_title||'سایر',rows:[]});
        categories.get(key).rows.push(s);
    });
    for(const group of categories.values()){
        const details=el('details','copilot-skill-category');details.open=!!q||categories.size===1;
        const summary=el('summary','');
        summary.append(el('span','',group.title),el('b','',group.rows.length));
        details.appendChild(summary);
        const body=el('div','copilot-skill-body');
        group.rows.forEach(skill=>{
            const button=el('button','copilot-skill-item');button.type='button';
            const icon=el('i','',skill.icon||'⚡');
            const main=el('span','');main.append(el('b','',skill.title),el('small','',skill.description||''));
            const code=el('code','',`/${skill.id}`);
            button.append(icon,main,code);
            button.addEventListener('click',()=>selectSkill(skill));
            body.appendChild(button);
        });
        details.appendChild(body);menu.appendChild(details);
    }
    menu.hidden=false;fitFloating(menu);
}

input.addEventListener('input',()=>{if(slashToken())render();else hide()});
input.addEventListener('compositionend',()=>{if(slashToken())render()});
input.addEventListener('focus',()=>{if(slashToken())render()});
input.addEventListener('keydown',e=>{
    if(e.key==='Escape')hide();
    if(e.key==='/'&&!input.value.trim()){setTimeout(render,0)}
});
document.addEventListener('click',e=>{if(!menu.contains(e.target)&&e.target!==input)hide()});

// Keep both @ and / popups inside the visible Sidecar viewport.
const fitAll=()=>{fitFloating(mention);fitFloating(menu)};
if(mention){
    const observer=new MutationObserver(fitAll);
    observer.observe(mention,{attributes:true,childList:true,subtree:true,attributeFilter:['hidden']});
}
const composerObserver=new ResizeObserver(fitAll);composerObserver.observe(composer);
window.addEventListener('resize',fitAll,{passive:true});
shell.addEventListener('scroll',fitAll,{passive:true,capture:true});

// Small discoverability affordance without replacing natural-language-first UX.
if(skills.length){
    const quick=shell.querySelector('.copilot-quick-actions');
    if(quick&&!quick.querySelector('[data-open-skill-picker]')){
        const b=el('button','','/ مهارت‌ها');b.type='button';b.dataset.openSkillPicker='1';
        b.addEventListener('click',()=>{
            const pos=input.selectionStart??input.value.length;
            const prefix=input.value.slice(0,pos),suffix=input.value.slice(pos);
            const separator=prefix&& !/\s$/.test(prefix)?' ':'';
            input.value=prefix+separator+'/'+suffix;
            const cursor=(prefix+separator+'/').length;input.selectionStart=input.selectionEnd=cursor;
            input.focus();render();
        });
        quick.prepend(b);
    }
}
})();
