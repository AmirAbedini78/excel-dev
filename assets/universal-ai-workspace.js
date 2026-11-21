/**
 * ERPSMART Universal AI Workspace — Wave4 UX helpers.
 * Extends Business Copilot sidecar; does not create a parallel chatbot.
 * Progress/evidence/proposal UI maps to docs/ai/agentic-next/04-AGENT-RUNTIME-CONTRACT.md
 */
(() => {
  'use strict';

  const TASK_ORDER = [
    'CREATED', 'VALIDATING', 'PLANNING', 'EXECUTING_READS', 'ANALYZING',
    'PROPOSAL_READY', 'AWAITING_APPROVAL', 'EXECUTING_APPROVED_ACTION',
    'VERIFYING', 'COMPLETED'
  ];
  const TERMINAL = new Set([
    'FAILED', 'CANCELLED', 'EXPIRED', 'BLOCKED', 'PARTIALLY_COMPLETED', 'COMPLETED'
  ]);
  const STATE_FA = {
    CREATED: 'ایجاد شده',
    VALIDATING: 'اعتبارسنجی زمینه و دسترسی',
    PLANNING: 'برنامه‌ریزی محدود',
    EXECUTING_READS: 'خواندن شواهد ERP',
    ANALYZING: 'تحلیل یافته‌ها',
    PROPOSAL_READY: 'پیشنهاد آماده — اجرا نشده',
    AWAITING_APPROVAL: 'در انتظار تأیید انسانی',
    EXECUTING_APPROVED_ACTION: 'اجرای اقدام تأییدشده',
    VERIFYING: 'راستی‌آزمایی نتیجه',
    COMPLETED: 'تکمیل شد',
    FAILED: 'ناموفق',
    CANCELLED: 'لغو شد',
    EXPIRED: 'منقضی شد',
    BLOCKED: 'مسدود',
    PARTIALLY_COMPLETED: 'تکمیل جزئی'
  };
  const EVIDENCE_FA = {
    LIVE_ERP_FACT: 'واقعیت زنده ERP',
    DOCUMENT_EVIDENCE: 'شواهد سند/RAG',
    CALCULATED_FINDING: 'یافته محاسباتی',
    MODEL_INTERPRETATION: 'تفسیر مدل (بدون اختیار)'
  };
  const COPY = {
    empty_thread: 'هنوز پیامی در این گفتگو نیست. با @ موجودیت را بچسبانید یا سؤال بپرسید.',
    empty_search: 'برای این عبارت در محیط کاری موردی پیدا نشد.',
    loading_search: 'در حال جست‌وجوی همه شرکت‌های محیط کاری…',
    loading_job: 'در صف پردازش…',
    loading_conversations: 'در حال بازیابی گفتگوها…',
    error_send: 'ارسال ناموفق بود. لطفاً دوباره تلاش کنید.',
    error_poll: 'پیگیری پاسخ بیش از حد طول کشید؛ نتیجه در مرکز فرمان قابل مشاهده است.',
    error_proposal: 'عملیات پیشنهاد ناموفق بود.',
    proposal_pending_note: 'این فقط پیشنهاد است و هنوز اجرا نشده است.',
    proposal_executed: 'پیشنهاد پس از تأیید انسانی اجرا شد.',
    proposal_rejected: 'پیشنهاد رد شد و اجرا نشد.',
    evidence_empty: 'شواهدی برای این پاسخ ثبت نشده است.',
    evidence_interpretation_only: 'تفسیر مدل به‌تنهایی سند اجرا نیست.',
    model_hint: 'موتور و مدل فقط مسیر استنتاج را انتخاب می‌کنند؛ اجرا و RBAC سمت سرور است.'
  };

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[m]);

  function faDigits(s) {
    return String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
  }

  /** Compact Gregorian Y-m-d[ H:i:s] → Jalali Y/m/d */
  function toJalali(dateStr) {
    const raw = String(dateStr || '').trim();
    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!m) return raw || '';
    let gy = +m[1], gm = +m[2], gd = +m[3];
    const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
    let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
    jy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    let jm, jd;
    if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
    else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
    const pad = (n) => String(n).padStart(2, '0');
    let out = `${jy}/${pad(jm)}/${pad(jd)}`;
    if (m[4]) out += ` ${m[4]}:${m[5]}${m[6] ? ':' + m[6] : ''}`;
    return faDigits(out);
  }

  /**
   * Format ledger IRR (whole or scaled×100) for display.
   * Ledger remains IRR; TOMAN is display-only (÷10). Never invent execution.
   */
  function formatMoney(amount, displayCurrency, opts = {}) {
    const cur = String(displayCurrency || 'IRR').toUpperCase() === 'TOMAN' ? 'TOMAN' : 'IRR';
    let n = Number(amount);
    if (!Number.isFinite(n)) return '—';
    if (opts.scaled) n = n / 100;
    if (cur === 'TOMAN') n = n / 10;
    const label = cur === 'TOMAN' ? 'تومان' : 'ریال';
    const formatted = n.toLocaleString('fa-IR', { maximumFractionDigits: 2 });
    return `${formatted} ${label}`;
  }

  function normalizeState(raw) {
    const s = String(raw || '').trim().toUpperCase();
    if (!s) return '';
    if (STATE_FA[s]) return s;
    return '';
  }

  /** Only mark a step reached if server reported state at/after it — never fabricate. */
  function progressSteps(agentTask) {
    const state = normalizeState(agentTask && agentTask.state);
    if (!state) return [];
    const idx = TASK_ORDER.indexOf(state);
    const terminalFail = TERMINAL.has(state) && state !== 'COMPLETED' && state !== 'PARTIALLY_COMPLETED';
    return TASK_ORDER.map((key, i) => {
      let status = 'upcoming';
      if (terminalFail) {
        status = i === 0 ? 'done' : (key === state || (idx < 0 && i === TASK_ORDER.length - 1) ? 'failed' : 'upcoming');
        if (STATE_FA[state] && key === 'COMPLETED') status = 'failed';
      } else if (idx >= 0) {
        if (i < idx) status = 'done';
        else if (i === idx) status = state === 'COMPLETED' ? 'done' : 'current';
      }
      return { key, label: STATE_FA[key], status };
    }).concat(terminalFail && !TASK_ORDER.includes(state) ? [{
      key: state, label: STATE_FA[state] || state, status: 'failed'
    }] : []);
  }

  function renderProgress(host, job) {
    if (!host) return null;
    let box = host.querySelector('[data-ai-ws-progress]');
    const agentTask = job && job.agent_task && typeof job.agent_task === 'object' ? job.agent_task : null;
    const state = normalizeState(agentTask && agentTask.state);
    const liveMsg = (job && job.live && job.live.message) || '';
    const stage = (job && job.live && job.live.stage) || '';

    if (!box) {
      box = document.createElement('div');
      box.className = 'ai-ws-progress';
      box.dataset.aiWsProgress = '1';
      host.appendChild(box);
    }

    if (!state) {
      // Fallback: show live message only — do not invent task-machine steps.
      box.innerHTML = `<div class="ai-ws-progress-live"><span class="ai-ws-badge muted">وضعیت زنده</span><p>${esc(liveMsg || COPY.loading_job)}</p>${stage ? `<small>مرحله موتور: ${esc(stage)}</small>` : ''}</div>`;
      return box;
    }

    const steps = progressSteps(agentTask);
    const updated = agentTask.updated_at ? toJalali(agentTask.updated_at) : '';
    box.innerHTML = `
      <div class="ai-ws-progress-head">
        <strong>${esc(STATE_FA[state] || state)}</strong>
        ${updated ? `<small>${esc(updated)}</small>` : ''}
      </div>
      <ol class="ai-ws-progress-steps">${steps.map((s) =>
        `<li data-status="${esc(s.status)}"><span>${esc(s.label)}</span></li>`
      ).join('')}</ol>
      ${liveMsg ? `<p class="ai-ws-progress-msg">${esc(liveMsg)}</p>` : ''}
      ${agentTask.message ? `<p class="ai-ws-progress-msg muted">${esc(agentTask.message)}</p>` : ''}
    `;
    return box;
  }

  function renderEvidence(host, job, moneyCtx) {
    if (!host) return null;
    const rows = Array.isArray(job && job.evidence) ? job.evidence : [];
    let box = host.querySelector('[data-ai-ws-evidence]');
    if (!box) {
      box = document.createElement('details');
      box.className = 'ai-ws-evidence';
      box.dataset.aiWsEvidence = '1';
      host.appendChild(box);
    }
    if (!rows.length) {
      box.innerHTML = `<summary>بازرس شواهد</summary><p class="ai-ws-empty">${esc(COPY.evidence_empty)}</p>`;
      return box;
    }
    const items = rows.slice(0, 40).map((ev) => {
      const kind = String(ev.kind || ev.type || 'MODEL_INTERPRETATION');
      const kindLabel = EVIDENCE_FA[kind] || kind;
      const title = ev.title || ev.label || ev.source || 'شواهد';
      let value = ev.value != null ? String(ev.value) : (ev.text || ev.snippet || '');
      if (ev.amount_irr != null || ev.irr_scaled != null) {
        value = formatMoney(
          ev.irr_scaled != null ? ev.irr_scaled : ev.amount_irr,
          (moneyCtx && moneyCtx.display_currency) || 'IRR',
          { scaled: ev.irr_scaled != null }
        );
      }
      const at = ev.at || ev.observed_at || '';
      const warn = kind === 'MODEL_INTERPRETATION'
        ? `<em class="ai-ws-evidence-warn">${esc(COPY.evidence_interpretation_only)}</em>` : '';
      return `<li data-kind="${esc(kind)}">
        <div><b>${esc(title)}</b><span class="ai-ws-badge">${esc(kindLabel)}</span></div>
        <p>${esc(value)}</p>
        ${at ? `<small>${esc(toJalali(at))}</small>` : ''}
        ${warn}
      </li>`;
    }).join('');
    box.innerHTML = `<summary>بازرس شواهد (${faDigits(rows.length)})</summary><ol>${items}</ol>`;
    return box;
  }

  function proposalStatusMeta(status) {
    const s = String(status || 'proposed').toLowerCase();
    if (s === 'executed') return { label: 'اجرا شده', tone: 'ok', executed: true };
    if (s === 'approved') return { label: 'تأیید شده — در حال اجرا/ثبت', tone: 'warn', executed: false };
    if (s === 'rejected') return { label: 'رد شده — اجرا نشده', tone: 'danger', executed: false };
    if (s === 'proposed') return { label: 'پیشنهاد — اجرا نشده', tone: 'warn', executed: false };
    return { label: s + ' — اجرا نشده مگر صریح', tone: 'muted', executed: false };
  }

  function renderProposalCard(host, job, opts = {}) {
    if (!host) return null;
    const proposals = Array.isArray(job.proposals) ? job.proposals : [];
    const actionable = proposals.filter((p) => p && String(p.status || '') === 'proposed');
    const pid = Number(job.proposal_id) || Number(actionable[0] && actionable[0].id) || 0;
    if (!pid && !proposals.length && !job.awaiting_human_approval) return null;

    let box = host.querySelector('[data-ai-ws-proposal]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'ai-ws-proposal-card';
      box.dataset.aiWsProposal = '1';
      host.appendChild(box);
    }

    const primary = actionable[0] || proposals[0] || {};
    const status = proposalStatusMeta(primary.status || (job.awaiting_human_approval ? 'proposed' : ''));
    const summary = primary.summary || primary.tool_name || 'پیشنهاد عملیاتی';
    const risk = primary.risk_level || primary.risk || '';
    const tool = primary.tool_name || '';
    const canAct = String(primary.status || 'proposed') === 'proposed' && pid > 0;

    box.innerHTML = `
      <div class="ai-ws-proposal-head">
        <strong>کارت تأیید پیشنهاد</strong>
        <span class="ai-ws-badge ${esc(status.tone)}">${esc(status.label)}</span>
      </div>
      <p class="ai-ws-proposal-summary">${esc(summary)}</p>
      <div class="ai-ws-proposal-meta">
        ${tool ? `<code>${esc(tool)}</code>` : ''}
        ${risk ? `<span>ریسک: ${esc(risk)}</span>` : ''}
      </div>
      <p class="ai-ws-proposal-note">${esc(status.executed ? COPY.proposal_executed : COPY.proposal_pending_note)}</p>
      ${canAct ? `<div class="ai-ws-proposal-buttons">
        <button type="button" class="btn tiny primary" data-proposal-approve="${pid}">تأیید و اجرا</button>
        <button type="button" class="btn tiny" data-proposal-reject="${pid}">رد</button>
      </div>` : ''}
    `;

    if (canAct && typeof opts.onApprove === 'function') {
      box.querySelector('[data-proposal-approve]')?.addEventListener('click', () => opts.onApprove(pid, box));
      box.querySelector('[data-proposal-reject]')?.addEventListener('click', () => opts.onReject(pid, box));
    }
    return box;
  }

  function markProposalResult(box, action) {
    if (!box) return;
    const note = document.createElement('p');
    note.className = 'ai-ws-proposal-done';
    if (action === 'approve_proposal') {
      note.textContent = COPY.proposal_executed;
      note.dataset.executed = '1';
    } else {
      note.textContent = COPY.proposal_rejected;
      note.dataset.executed = '0';
    }
    box.replaceWith(note);
  }

  function applyJobUi(host, job, moneyCtx, handlers) {
    if (!host || !job) return;
    renderProgress(host, job);
    if (job.terminal || (job.evidence && job.evidence.length) || (job.agent_task && job.agent_task.state)) {
      renderEvidence(host, job, moneyCtx);
    }
    if (job.awaiting_human_approval || job.proposal_id || (job.proposals && job.proposals.length)) {
      renderProposalCard(host, job, handlers || {});
    }
  }

  window.ERPSMART_AI_WORKSPACE = {
    COPY,
    STATE_FA,
    EVIDENCE_FA,
    TASK_ORDER,
    esc,
    faDigits,
    toJalali,
    formatMoney,
    normalizeState,
    progressSteps,
    renderProgress,
    renderEvidence,
    renderProposalCard,
    markProposalResult,
    applyJobUi,
    version: 'wave4-prep-1'
  };
})();
