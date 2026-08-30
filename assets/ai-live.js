(() => {
  "use strict";

  const terminal = new Set(["succeeded", "failed"]);
  const stageLabels = {
    queued: "در صف",
    leased: "تخصیص Worker",
    running: "در حال پردازش",
    start: "شروع",
    route: "انتخاب مسیر",
    analysis_bundle_request: "دریافت داده مالی",
    analysis_bundle_ready: "آماده‌سازی داده",
    deterministic_report: "گزارش قطعی",
    deep_analysis: "تحلیل عمیق",
    deep_fallback: "بازگشت امن",
    prepare: "آماده‌سازی",
    llm_request: "ارسال به مدل",
    llm_stream: "پردازش مدل",
    llm_done: "پاسخ مدل",
    provider_fallback: "تغییر Provider و مسیر جایگزین مدل",
    tool_call: "اجرای ابزار",
    tool_result: "نتیجه ابزار",
    invoice_parse: "تحلیل درخواست فاکتور",
    guarded_route: "مسیر Proposal محافظت‌شده",
    proposal_request: "درخواست Proposal",
    proposal_created: "Proposal ساخته شد",
    proposal_blocked: "Proposal مسدود شد",
    read_intent_parse: "تحلیل قصد خواندن",
    entity_parse: "تحلیل موجودیت‌ها",
    grounded_read_route: "مسیر خواندن Grounded",
    grounded_read: "خواندن Grounded",
    multi_read_step: "گام خواندن Grounded",
    grounded_read_complete: "خواندن Grounded تکمیل شد",
    adaptive_cache_lookup: "بررسی Plan معتبر",
    adaptive_cache_hit: "Plan معتبر یافت شد",
    adaptive_cache_miss: "Plan معتبر یافت نشد",
    adaptive_cache_reject: "Plan ذخیره‌شده رد شد",
    adaptive_cache_unavailable: "Plan cache در دسترس نیست",
    adaptive_plan_llm: "طراحی Plan محدود",
    adaptive_plan_validated: "Plan معتبر شد",
    adaptive_route_learned: "Plan معتبر ذخیره شد",
    adaptive_learn_warning: "هشدار ذخیره Plan",
    adaptive_delegate: "واگذاری به مسیر موجود",
    workflow_candidate: "انتخاب Workflow حسابداری",
    workflow_plan_llm: "طراحی Workflow محدود",
    workflow_plan_validated: "Workflow معتبر شد",
    workflow_plan_rejected: "Plan مدل رد شد",
    workflow_plan_fallback: "Workflow قطعی جایگزین",
    workflow_fallback_rejected: "Workflow جایگزین رد شد",
    workflow_step: "اجرای گام Workflow",
    workflow_step_complete: "گام Workflow تکمیل شد",
    workflow_step_skipped: "گام Workflow رد شد",
    workflow_complete: "Workflow حسابداری تکمیل شد",
    workflow_delegate: "واگذاری Workflow به مسیر موجود",
    workflow_blocked: "Workflow مسدود شد",
    action_candidate: "درخواست اقدام حسابداری",
    action_plan_llm: "انتخاب هدف اقدام",
    action_plan_validated: "هدف اقدام معتبر شد",
    action_read: "خواندن داده اقدام",
    action_condition: "ارزیابی شرط اقدام",
    action_proposal: "ساخت Proposal اقدام",
    action_complete: "اقدام منتظر تأیید انسانی",
    action_blocked: "اقدام مسدود شد",
    action_rejected: "اقدام رد شد",
    financial_intelligence_candidate: "انتخاب هوشمندی مالی",
    financial_intelligence_tool: "خواندن داده هوشمندی مالی",
    financial_intelligence_llm: "اولویت‌بندی هوشمندی مالی",
    financial_intelligence_prioritized: "اولویت‌های مالی تثبیت شد",
    financial_intelligence_priority_fallback: "اولویت‌بندی قطعی جایگزین",
    financial_intelligence_complete: "هوشمندی مالی تکمیل شد",
    financial_intelligence_blocked: "هوشمندی مالی مسدود شد",
    forecast_risk_candidate: "انتخاب پیش‌بینی و ریسک",
    forecast_risk_tool: "خواندن داده پیش‌بینی",
    forecast_risk_llm: "اولویت‌بندی ریسک",
    forecast_risk_prioritized: "اولویت‌های ریسک تثبیت شد",
    forecast_risk_priority_fallback: "اولویت‌بندی قطعی ریسک",
    forecast_risk_complete: "پیش‌بینی و ریسک تکمیل شد",
    forecast_risk_blocked: "پیش‌بینی و ریسک مسدود شد",
    proactive_candidate: "انتخاب پایش پیش‌دستانه",
    proactive_tool: "خواندن داده پایش",
    proactive_recommendations_built: "ساخت پیشنهادهای Grounded",
    proactive_llm: "اولویت‌بندی پیشنهادها",
    proactive_prioritized: "اولویت پیشنهادها تثبیت شد",
    proactive_priority_fallback: "اولویت‌بندی قطعی پیشنهادها",
    proactive_no_action: "اقدام مهمی یافت نشد",
    proactive_complete: "پایش پیش‌دستانه تکمیل شد",
    proactive_blocked: "پایش پیش‌دستانه مسدود شد",
    commercial_hardening_complete: "تأیید قرارداد تجاری",
    commercial_hardening_failed: "نقض قرارداد تجاری",
    completed: "تکمیل",
    succeeded: "موفق",
    failed: "ناموفق",
  };
  const statusLabels = {
    queued: "در صف",
    leased: "تخصیص داده‌شده",
    running: "در حال پردازش",
    succeeded: "موفق",
    failed: "ناموفق",
  };
  const modeLabels = {
    deterministic_financial_report: "گزارش سریع قطعی",
    deep_financial_analysis: "تحلیل عمیق محلی",
    deep_financial_analysis_fallback: "گزارش قطعی؛ تحلیل عمیق تکمیل نشد",
    tool_agent: "ایجنت ابزارمحور",
    fast_read_analysis: "تحلیل سریع",
    grounded_multi_read: "خواندن چندبخشی Grounded",
    accounting_workflow_read: "Workflow حسابداری Grounded",
    accounting_workflow_partial: "Workflow حسابداری با نتیجه جزئی",
    accounting_workflow_blocked: "Workflow حسابداری مسدودشده",
    accounting_action_proposal: "Proposal اقدام حسابداری",
    accounting_action_noop: "اقدام حسابداری؛ شرط برقرار نبود",
    accounting_action_blocked: "اقدام حسابداری مسدودشده",
    accounting_action_rejected: "اقدام حسابداری ردشده",
    guarded_sales_invoice_proposal: "Proposal فاکتور فروش",
    guarded_sales_invoice_blocked: "فاکتور فروش مسدودشده",
    guarded_purchase_invoice_proposal: "Proposal فاکتور خرید",
    guarded_purchase_invoice_blocked: "فاکتور خرید مسدودشده",
    guarded_check_proposal: "Proposal چک",
    guarded_check_blocked: "عملیات چک مسدودشده",
    treasury_check_read: "گزارش Grounded چک‌ها",
    treasury_check_read_blocked: "گزارش چک‌ها مسدودشده",
    inventory_warehouses_read: "فهرست Grounded انبارها",
    inventory_position_read: "وضعیت Grounded موجودی",
    inventory_replenishment_read: "ریسک تأمین و نقطه سفارش",
    procurement_pipeline_read: "جریان خرید و ورودی مورد انتظار",
    guarded_inventory_receipt_proposal: "Proposal رسید انبار",
    trade_case_read: "وضعیت Grounded بازرگانی",
    trade_landed_cost_read: "Landed Cost Grounded",
    trade_risk_read: "ریسک بازرگانی Grounded",
    guarded_trade_case_proposal: "Proposal پرونده بازرگانی",
    guarded_trade_case_blocked: "پرونده بازرگانی مسدودشده",
    guarded_trade_shipment_proposal: "Proposal حمل",
    guarded_trade_shipment_blocked: "حمل مسدودشده",
    guarded_trade_cost_proposal: "Proposal هزینه بازرگانی",
    guarded_trade_cost_blocked: "هزینه بازرگانی مسدودشده",
    sales_fulfillment_read: "تأمین و تحویل فروش Grounded",
    sales_margin_read: "حاشیه سود فروش Grounded",
    trade_manager_brief_read: "Manager Brief تجاری Grounded",
    crm_customer_360_read: "Customer 360 Grounded",
    crm_pipeline_read: "Pipeline فروش CRM Grounded",
    crm_followup_read: "پیگیری‌های CRM Grounded",
    guarded_crm_activity_proposal: "Proposal پیگیری CRM",
    guarded_crm_activity_blocked: "پیگیری CRM مسدودشده",
    guarded_crm_opportunity_proposal: "Proposal فرصت فروش CRM",
    guarded_crm_opportunity_blocked: "فرصت فروش CRM مسدودشده",
    guarded_sales_reservation_proposal: "Proposal رزرو فروش",
    guarded_sales_reservation_blocked: "رزرو فروش مسدودشده",
    guarded_sales_delivery_proposal: "Proposal تحویل فروش",
    guarded_sales_delivery_blocked: "تحویل فروش مسدودشده",
    guarded_inventory_receipt_blocked: "رسید انبار مسدودشده",
    financial_intelligence: "هوشمندی مالی",
    financial_intelligence_blocked: "هوشمندی مالی مسدودشده",
    forecast_risk_anomaly: "پیش‌بینی، ریسک و ناهنجاری",
    forecast_risk_blocked: "پیش‌بینی مسدودشده",
    proactive_accounting: "پایش پیش‌دستانه حسابداری",
    proactive_accounting_no_action: "پایش پیش‌دستانه؛ اقدام مهمی نبود",
    proactive_accounting_blocked: "پایش پیش‌دستانه مسدودشده",
    adaptive_cache_read: "خواندن تطبیقی از Plan معتبر",
    adaptive_llm_read: "خواندن تطبیقی با Planner",
  };
  const riskLabels = { low: "کم", medium: "متوسط", high: "بالا" };
  const latencyLabels = { within_budget: "پاس", exceeded: "بیش‌ازحد" };
  const state = new Map();
  const transport = text => document.querySelectorAll("[data-ai-transport]").forEach(element => {
    element.textContent = text;
  });
  const finite = value => value !== null && value !== "" && Number.isFinite(Number(value));
  const fmt = (value, digits = 1) => Number(value).toFixed(digits);
  const safeToolNames = value => {
    if (!Array.isArray(value)) return [];
    const names = [];
    const seen = new Set();
    value.forEach(candidate => {
      if (names.length >= 32 || typeof candidate !== "string") return;
      const name = candidate.trim();
      if (!/^[a-z][a-z0-9_]{0,79}$/.test(name) || seen.has(name)) return;
      seen.add(name);
      names.push(name);
    });
    return names;
  };

  const metaText = job => {
    const parts = [];
    if (job?.mode) parts.push(`مسیر: ${modeLabels[job.mode] || job.mode}`);
    if (job?.model) parts.push(`مدل: ${job.model === "none" ? "بدون LLM" : job.model}`);
    return parts.join(" • ");
  };

  const metricsText = metrics => {
    if (!metrics || typeof metrics !== "object") return "";
    const parts = [];
    if (finite(metrics.first_chunk_seconds)) parts.push(`اولین خروجی: ${fmt(metrics.first_chunk_seconds)}s`);
    if (finite(metrics.elapsed_seconds)) parts.push(`زمان مدل: ${fmt(metrics.elapsed_seconds)}s`);
    const promptCount = Number(metrics.prompt_eval_count || 0);
    const promptDuration = Number(metrics.prompt_eval_duration || 0);
    if (promptCount > 0 && promptDuration > 0) parts.push(`Prompt: ${fmt(promptCount / (promptDuration / 1e9))} tok/s`);
    const evalCount = Number(metrics.eval_count || 0);
    const evalDuration = Number(metrics.eval_duration || 0);
    if (evalCount > 0 && evalDuration > 0) parts.push(`Generation: ${fmt(evalCount / (evalDuration / 1e9))} tok/s`);
    return parts.join(" • ");
  };

  const hardeningText = hardening => {
    if (!hardening || typeof hardening !== "object") return "";
    const parts = [];
    if (finite(hardening.end_to_end_seconds)) parts.push(`زمان کل: ${fmt(hardening.end_to_end_seconds)}s`);
    if (hardening.latency_status) {
      parts.push(`بودجه زمان: ${latencyLabels[hardening.latency_status] || hardening.latency_status}`);
    }
    if (hardening.risk_class) {
      parts.push(`ریسک مسیر: ${riskLabels[hardening.risk_class] || hardening.risk_class}`);
    }
    return parts.join(" • ");
  };

  const toolText = job => {
    const used = safeToolNames(job?.tools_used);
    const attempted = safeToolNames(job?.tools_attempted);
    if (!attempted.length) return used.length ? `ابزارهای موفق: ${used.join("، ")}` : "";
    const same = attempted.length === used.length && attempted.every(name => used.includes(name));
    if (same) return `ابزارها: ${attempted.join("، ")}`;
    const parts = [`ابزارهای تلاش‌شده: ${attempted.join("، ")}`];
    if (used.length) parts.push(`ابزارهای موفق: ${used.join("، ")}`);
    return parts.join(" • ");
  };

  const jobMetricsText = job => [
    metaText(job),
    metricsText(job?.metrics),
    toolText(job),
    hardeningText(job?.commercial_hardening),
  ].filter(Boolean).join(" • ");

  const renderTrace = (root, trace) => {
    const box = root.querySelector("[data-ai-live-trace-box]");
    const list = root.querySelector("[data-ai-live-trace]");
    if (!box || !list) return;
    list.replaceChildren();
    const rows = Array.isArray(trace) ? trace.slice(-30) : [];
    rows.forEach(event => {
      const item = document.createElement("li");
      const code = document.createElement("code");
      const stage = String(event?.stage || "");
      code.textContent = stageLabels[stage] || stage;
      item.append(code, document.createTextNode(` — ${String(event?.message || "")}`));
      list.appendChild(item);
    });
    box.hidden = rows.length === 0;
  };

  const renderFinalResult = job => {
    const holder = document.querySelector(`[data-ai-result="${job.id}"]`);
    if (!holder || !terminal.has(job.status)) return;
    holder.replaceChildren();
    if (job.status === "failed") {
      const alert = document.createElement("div");
      alert.className = "alert danger";
      alert.style.whiteSpace = "pre-wrap";
      alert.textContent = job.error_text || "پردازش ناموفق بود.";
      holder.appendChild(alert);
      return;
    }
    const answer = document.createElement("div");
    answer.className = "ai-answer";
    const title = document.createElement("strong");
    title.textContent = "پاسخ ایجنت";
    const body = document.createElement("div");
    body.style.whiteSpace = "pre-wrap";
    body.textContent = job.result_text || "";
    answer.append(title, body);
    const text = jobMetricsText(job);
    if (text) {
      const meta = document.createElement("div");
      meta.className = "muted";
      meta.style.marginTop = "10px";
      meta.textContent = text;
      answer.appendChild(meta);
    }
    holder.appendChild(answer);
  };

  const apply = job => {
    if (!job || !job.id) return;
    const root = document.querySelector(`[data-ai-live-job="${job.id}"]`);
    if (root) {
      const live = job.live || {};
      const details = live.details || {};
      const set = (selector, value) => {
        const element = root.querySelector(selector);
        if (element) element.textContent = value;
      };
      set("[data-ai-live-status]", statusLabels[job.status] || job.status);
      set("[data-ai-live-message]", String(live.message || ""));
      set("[data-ai-live-stage]", stageLabels[String(live.stage || job.status)] || String(live.stage || job.status));
      set("[data-ai-live-worker]", job.worker_name || "در انتظار تخصیص");
      set("[data-ai-live-elapsed]", finite(details.elapsed_seconds) ? `زمان: ${fmt(details.elapsed_seconds)}s` : "");
      set("[data-ai-live-metrics]", jobMetricsText(job));
      renderTrace(root, live.trace || []);
    }
    const header = document.querySelector(`[data-ai-job="${job.id}"] [data-ai-job-status]`);
    if (header) {
      header.textContent = `#${job.id} • ${statusLabels[job.status] || job.status}${job.worker_name ? ` • ${job.worker_name}` : ""}`;
    }
    renderFinalResult(job);
    if (terminal.has(job.status)) {
      const slot = state.get(Number(job.id));
      if (slot?.source) slot.source.close();
      if (slot?.timer) clearTimeout(slot.timer);
      if (slot?.watchdog) clearInterval(slot.watchdog);
      state.delete(Number(job.id));
    }
  };

  const fetchOnce = async id => {
    const response = await fetch(`ai_live.php?format=json&job_id=${encodeURIComponent(id)}&_=${Date.now()}`, {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const payload = await response.json();
    if (!payload?.ok || !payload?.job) throw new Error(payload?.error || "invalid_payload");
    apply(payload.job);
    return payload.job;
  };

  const startPolling = id => {
    id = Number(id);
    const old = state.get(id) || {};
    if (old.source) old.source.close();
    if (old.watchdog) clearInterval(old.watchdog);
    transport("اتصال سبک HTTP");
    const slot = { ...old, source: null, watchdog: null, mode: "poll" };
    state.set(id, slot);
    const loop = async () => {
      if (!state.has(id)) return;
      try {
        const job = await fetchOnce(id);
        if (terminal.has(job.status)) return;
      } catch (error) {
        console.warn("AI live polling:", error);
      }
      if (!state.has(id)) return;
      slot.timer = setTimeout(loop, 1200);
    };
    loop();
  };

  const startSse = id => {
    id = Number(id);
    if (!("EventSource" in window)) {
      startPolling(id);
      return;
    }
    const source = new EventSource(`ai_live.php?job_id=${encodeURIComponent(id)}`);
    const slot = { source, timer: null, watchdog: null, mode: "sse", lastEventAt: Date.now(), errors: 0 };
    state.set(id, slot);
    transport("SSE زنده");
    const handle = event => {
      slot.lastEventAt = Date.now();
      slot.errors = 0;
      try {
        const payload = JSON.parse(event.data);
        if (payload?.job) apply(payload.job);
      } catch (error) {
        console.warn("AI live SSE payload:", error);
      }
    };
    source.addEventListener("job", handle);
    source.addEventListener("done", handle);
    source.onerror = () => {
      slot.errors += 1;
      if (slot.errors >= 2) startPolling(id);
    };
    slot.watchdog = setInterval(() => {
      if (state.has(id) && Date.now() - slot.lastEventAt > 7000) startPolling(id);
    }, 2500);
  };

  document.querySelectorAll("[data-ai-live-job]").forEach(element => {
    const id = Number(element.getAttribute("data-ai-live-job"));
    if (id > 0) startSse(id);
  });
})();
