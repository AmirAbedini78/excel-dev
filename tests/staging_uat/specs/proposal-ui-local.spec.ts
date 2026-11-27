/**
 * Local Browser UAT — proposal UI cards (no staging credentials required).
 * Enable with PLAYWRIGHT_LOCAL_FIXTURE=1. Skips otherwise (does not invent logins).
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import * as os from 'os';

const FIXTURE = process.env.PLAYWRIGHT_LOCAL_FIXTURE === '1';

test.describe('Proposal UI local UAT', () => {
  test('proposal cards render approve/reject without physical-delete affordance', async ({ page }) => {
    test.skip(!FIXTURE, 'Set PLAYWRIGHT_LOCAL_FIXTURE=1 to run local fixture UAT');

    const inline = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body>
<div id="host"></div>
<script>
function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));}
function renderProposalActions(host,job){
  const proposals=Array.isArray(job.proposals)?job.proposals.filter(p=>p&&String(p.status||'')==='proposed'):[];
  const pid=Number(job.proposal_id)||Number(proposals[0]?.id)||0;
  if(!pid&&!proposals.length)return;
  const box=document.createElement('div');box.className='copilot-proposal-actions';
  const list=proposals.length?proposals:[{id:pid,summary:job.proposal_summary||'پیشنهاد عملیاتی',tool_name:job.tool_name||''}];
  box.innerHTML=list.map(p=>{
    const id=Number(p.id)||pid;
    const tool=esc(p.tool_name||'');
    const summary=esc(p.summary||'پیشنهاد عملیاتی');
    return '<div class="copilot-proposal-card" data-proposal-id="'+id+'"><p class="copilot-proposal-tool">'+(tool?('ابزار: '+tool):'')+'</p><p class="copilot-proposal-summary">'+summary+'</p><p class="ai-ws-proposal-note">این فقط پیشنهاد است و هنوز اجرا نشده است. حذف فیزیکی اسناد مالی از این مسیر ممکن نیست.</p><div class="copilot-proposal-buttons"><button type="button" data-proposal-approve="'+id+'">تأیید و اجرا</button><button type="button" data-proposal-reject="'+id+'">رد</button></div></div>';
  }).join('');
  host.appendChild(box);
}
renderProposalActions(document.getElementById('host'),{
  proposal_id: 42,
  proposals:[{id:42,status:'proposed',tool_name:'propose_master_data_operation',summary:'ایجاد مشتری — تست'}]
});
</script></body></html>`;

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'erpsmart-proposal-uat-'));
    const file = path.join(dir, 'proposal.html');
    fs.writeFileSync(file, inline, 'utf8');
    await page.goto('file://' + file.replace(/\\/g, '/'));
    await expect(page.locator('.copilot-proposal-card')).toHaveCount(1);
    await expect(page.locator('.copilot-proposal-tool')).toContainText('propose_master_data_operation');
    await expect(page.locator('.ai-ws-proposal-note')).toContainText('حذف فیزیکی');
    await expect(page.locator('[data-proposal-approve]')).toHaveCount(1);
    await expect(page.locator('[data-proposal-reject]')).toHaveCount(1);
    await expect(page.locator('text=حذف قطعی')).toHaveCount(0);
  });
});
