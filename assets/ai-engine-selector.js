/* ERPSMART: keep provider + model controls in sync without exposing any API credential. */
(()=>{'use strict';
function init(engine){if(engine.dataset.aiEngineReady)return;engine.dataset.aiEngineReady='1';
 const root=engine.closest('form, .copilot-sidecar, section')||document;
 const model=root.querySelector('[data-ai-model]');if(!model)return;
 function sync(){let first='';for(const opt of model.options){const valid=opt.dataset.engine===engine.value;opt.disabled=!valid;opt.hidden=!valid;if(valid&&!first)first=opt.value;}
 if(!Array.from(model.options).some(o=>!o.disabled&&o.value===model.value))model.value=first;
 }
 engine.addEventListener('change',sync);sync();}
 document.querySelectorAll('[data-ai-engine]').forEach(init);
})();
