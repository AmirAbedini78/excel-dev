# 07-DECISION-LOG — تصمیم‌های معماری و محصول

## ADR-001 — AI-first Vertical Slice
Status: ACCEPTED

لازم نیست ابتدا نرم‌افزار حسابداری کامل شود. Existing Accounting Core برای ساخت و آزمون Workflowهای هوشمند استفاده می‌شود. هر primitive گمشده فقط در حد نیاز Workflow تکمیل می‌شود.

## ADR-002 — Accounting/Financial only for current MVP
Status: ACCEPTED

در Scope فعلی توسعه AI روی Notes/CRM/Phonebook/other business modules انجام نمی‌شود. Multi-company foundation می‌ماند ولی Practice OS expansion فعلاً DEFERRED است.

## ADR-003 — DB is system of record
Status: ACCEPTED

LLM و RAG منبع حقیقت عددی جاری نیستند.

## ADR-004 — No direct SQL for LLM
Status: ACCEPTED

تمام تعامل با ERP از Tool Gateway و Domain validation عبور می‌کند.

## ADR-005 — Proposal before financial mutation
Status: ACCEPTED

Mutationهای مالی حساس Proposal هستند. Autonomy بعداً با Risk Policy مرحله‌بندی می‌شود.

## ADR-006 — Local-first compute
Status: ACCEPTED

cPanel = control plane؛ Workerهای Docker/Ollama = compute. Worker outbound HTTPS استفاده می‌کند.

## ADR-007 — Hybrid deterministic + LLM
Status: ACCEPTED

LLM نباید برای arithmetic/report facts استفاده شود. Fast deterministic path برای facts، LLM برای planning/interpretation.

## ADR-008 — Forecasting is separate numeric engine
Status: ACCEPTED

Forecast عددی از مدل آماری/ML می‌آید؛ LLM فقط explain می‌کند.

## ADR-009 — RAG is contextual knowledge
Status: ACCEPTED

RAG برای documents/laws/policies است، نه current balances.

## ADR-010 — Frameworks are replaceable
Status: ACCEPTED

Custom loop فعلی contract نهایی نیست. LangGraph/Hermes/Qdrant فقط با نیاز اثبات‌شده اضافه می‌شوند و Tool contracts نباید به runtime خاص قفل شوند.

## ADR-011 — Safe Deep
Status: ACCEPTED

آزمایش‌های اولیه نشان دادند وارد کردن مستقیم عدد به LLM می‌تواند تعبیر/رقم نادرست بسازد. Deep باید deterministic core + constrained qualitative enhancement + fallback داشته باشد.

## ADR-012 — Ground IDs, never generate them
Status: ACCEPTED

party_id/item_id/account_id از search/Tool واقعی می‌آیند؛ LLM حق ساخت ID ندارد.

## ADR-013 — Adaptive Plan Cache is optimization only
Status: ACCEPTED / FROZEN

Cache پاسخ مالی ممنوع است. فقط Plan معتبر Read-only می‌تواند cache شود. این Feature نباید Roadmap را از Planner/Agent/Intelligence منحرف کند.

## ADR-014 — Comprehensive candidate validation
Status: ACCEPTED

پس از تجربه چند Micro-patch شکست‌خورده، سیاست رسمی Candidate-first + pre-mutation integration + rollback اتخاذ شد.

## ADR-015 — Full accounting app deferred until AI MVP
Status: ACCEPTED

هدف فعلی اثبات و تجاری‌سازی مغز AI روی ماژول مالی موجود است. Full accounting product expansion بعد از MVP تصمیم‌گیری و اجرا می‌شود.

## ADR-016 — Commercial release guard and idempotent terminal delivery
Status: ACCEPTED

v9.3 یک Wrapper نهایی cross-cutting روی Guard Stack دارد تا بدون تغییر محاسبات مالی، contractهای latency/observability/redaction/read-only/proposal-only را یکجا enforce کند. Proposal creation باید atomic idempotent باشد و retry پاسخ گمشده `complete/fail` باید همان terminal state را بدون side effect دوم acknowledge کند. این recovery هرگز Proposal Approval یا domain validation را bypass نمی‌کند.

## ADR-017 — Live and reload observability must be contract-equivalent
Status: ACCEPTED

Job #49 نشان داد persistence صحیح metadata کافی نیست؛ اگر endpoint یا browser renderer آن را در SSE/Polling حذف کند، Commercial observability در تجربه اصلی کاربر شکست خورده است. از v9.3.0.1، terminal live payload باید redacted `commercial_hardening` را حمل کند و renderer مشترک SSE/Polling با رندر PHP از نظر route/model/latency/risk هم‌معنا باشد. JavaScript syntax نیز release gate اجباری است.

## ADR-018 — Attempt observability is allowlisted and bounded
Status: ACCEPTED

Job #51 مدل تلاش‌شده و fail-closed بودن Action را ثابت کرد، اما stage عمومی `action_read` نام Toolهای واقعی را نشان نداد و metrics مدل تلاش‌شده نیز نمایش داده نشد. برای audit تجاری، endpoint و renderer می‌توانند فقط نام‌های normalize‌شده `tools_used/tools_attempted` و شش مقدار عددی allowlisted از `attempted_metrics` را نمایش دهند. Tool arguments، results، call IDs، free-form model metadata و trace details حساس هرگز وارد payload مرورگر نمی‌شوند.
