# 03-ARCHITECTURE — معماری Canonical

## 1. Principle

ERPSMART نباید «Accounting UI + ChatGPT box» باشد.

معماری هدف:

```text
                         Accountant
                             │
                             ▼
                    cPanel Web Application
 ┌─────────────────────────────────────────────────────────┐
 │ Auth / RBAC / Workspace / Company                      │
 │ Accounting & Financial Core                            │
 │ MySQL System of Record                                 │
 │ AI Job Queue                                            │
 │ Tool Gateway                                            │
 │ Proposal / Approval / Audit                            │
 │ Realtime Job Observability                             │
 └───────────────────────┬─────────────────────────────────┘
                         ▲
                         │ outbound HTTPS
              ┌──────────┴───────────┐
              │                      │
              ▼                      ▼
       Local Worker A          Local Worker B
       Ollama / Python         Ollama / Python
              │                      │
              └──────────┬───────────┘
                         ▼
                  Future VPS/GPU
```

## 2. Trust boundaries

### Database
تنها Application/Tool Gateway به DB عملیاتی دسترسی دارد.

### LLM
LLM:
- SQL آزاد نمی‌زند.
- DB credentials ندارد.
- ERP IDs را اختراع نمی‌کند.
- مبالغ جاری را حدس نمی‌زند.
- Write را مستقیم Post نمی‌کند.

### RAG
RAG document context است؛ نه ledger.

### Worker
Worker عملیات را از طریق API کنترل‌شده cPanel انجام می‌دهد.

## 3. Hybrid intelligence path

### Fast deterministic path

برای سؤال‌های شناخته‌شده و محاسبات قطعی:

```text
Prompt
→ deterministic/semantic route
→ safe Tool
→ deterministic formatting
→ answer
```

LLM لازم نیست.

### General planned read

```text
Prompt
→ constrained Planner
→ validated multi-step plan
→ Tools
→ derived deterministic calculations
→ optional LLM explanation
```

### Deep analysis

```text
Prompt
→ collect grounded evidence
→ deterministic core analysis
→ constrained qualitative LLM enhancement
→ safety validation
→ fallback if unsafe/timeout
```

### Mutation

```text
Prompt
→ Planner
→ resolve entities
→ validate
→ Proposal
→ Approval/Risk Policy
→ deterministic execution
→ verify
→ audit
```

## 4. Current Worker guard stack

در Snapshot v9.3 ترتیب نصب Runtime:

```text
Safe Deep
→ Guarded Invoice Agent
→ Grounded/Parameterized Read
→ Adaptive Read Plan Cache
→ Constrained Workflow Planner
→ Accounting Action Orchestrator
→ Financial Intelligence
→ Forecast/Risk/Anomaly
→ Proactive Accounting Agent
→ Commercial Hardening (last wrapper)
```

Commercial Hardening هیچ منطق مالی جدیدی ندارد. وظیفه آن enforce کردن قرارداد release روی نتیجه همه مسیرهاست: read-only/proposal-only، secret redaction، end-to-end latency، actual Tool/model observability و fail-closed metadata contract.

### Live terminal metadata boundary

```text
redacted terminal result_json
→ authenticated liveJobStateForUser
→ same normalized fields for SSE and Polling
→ browser final renderer
```

در v9.3.0.1 فیلد redacted `commercial_hardening` همراه `mode/model/metrics` از endpoint زنده عبور می‌کند. SSE و Polling هر دو از یک renderer استفاده می‌کنند و باید بدون refresh با رندر PHP پس از refresh از نظر route، model، latency budget و risk هم‌معنا باشند.

v9.3.0.2 همین boundary را برای blocked/fallback attempt observability کامل می‌کند:

```text
persisted tools_used / tools_attempted (names only)
persisted attempted_metrics (numeric allowlist only)
→ server allow-pattern + unique + max 32
→ authenticated owner-scoped live payload
→ text-only SSE/Polling renderer + escaped PHP reload renderer
```

Metric allowlist فقط `first_chunk_seconds`، `elapsed_seconds`، token counts/durations را می‌پذیرد. Tool arguments، Tool results، call IDs و trace details حساس از این boundary عبور نمی‌کنند.

Proposal descriptorها به generic LLM loop داده نمی‌شوند. فقط Guarded Invoice Agent و Accounting Action Orchestrator پس از grounding قطعی می‌توانند Proposal Tool مشخص را مستقیم فراخوانی کنند.

## 5. Tool contract

Toolهای Read می‌توانند مستقیم اجرا شوند.

Toolهای Mutation در حالت فعلی Proposal هستند.

Server باید:
- workspace/company ownership را validate کند.
- identifier و status را allowlist کند.
- dates را normalize/validate کند.
- idempotency را enforce کند.
- audit بنویسد.

### Terminal recovery contract

```text
Worker complete/fail
→ server transaction commits once
→ lost response may retry with same node + lease secret
→ same terminal state acknowledged as replay for 24h
→ opposite terminal state rejected
```

Proposal insert نیز با unique idempotency key و atomic upsert انجام می‌شود. Retry هرگز مجوز Approval را bypass نمی‌کند و اجرای Proposal همچنان با row lock و transaction است.

## 6. RAG boundary

RAG مناسب:

- قوانین/بخشنامه
- قرارداد
- رویه داخلی
- راهنما
- policy
- اسناد غیرساختاریافته

RAG نامناسب برای حقیقت عددی جاری:

- مانده
- فروش
- خرید
- مقدار فاکتور
- تراز
- وضعیت سند
- موجودی قطعی

## 7. ML/Forecast boundary

LLM Forecast Engine نیست.

```text
Historical financial series
→ feature engineering
→ statistical/ML forecast
→ confidence/error
→ risk/anomaly rules
→ LLM explanation
```

## 8. Scale strategy

فعلاً Job-level parallelism.

در آینده بدون شکستن Tool Contract:

- VPS/GPU worker
- specialized model service
- queue service
- Qdrant/vector service در صورت نیاز واقعی
- Ray/vLLM در scale بالا

## 9. Framework adoption policy

### LangGraph
فقط وقتی durable multi-step/resume/HITL پیچیدگی custom loop را واقعاً بالا برد.

### Hermes
فقط به‌صورت Adapter روی Tool Gateway محدود؛ نه shell/SQL unrestricted.

### FastRAG/Qdrant
وقتی حجم Corpus و evaluation نشان دهد retrieval فعلی کافی نیست.

### Fine-tuning
بعد از داشتن حجم مناسب Interaction تاییدشده:
Prompt → Plan → Tools → Approval/Correction → Outcome.

## 10. v10 Module architecture

از v10، Platform علاوه بر Domain/AI contract، یک Module Kernel دارد:

```text
Workspace
  → Module Registry
      → enabled / disabled
      → dependencies
      → pages/routes/menu
      → permissions
      → schema/assets/API
      → AI tools/RAG/events/background jobs
```

Rule: Module غیرفعال نباید در navigation یا route قابل استفاده باشد و در Cycleهای بعد AI Tool/Background Job آن نیز فقط در حالت فعال expose می‌شود.

`workspace_modules` منبع وضعیت فعال/غیرفعال هر Workspace است. `ModuleRegistry` تنها Catalog/Dependency contract را نگه می‌دارد؛ Module Center مدیریت این وضعیت را برای Platform Admin انجام می‌دهد.

Target AI runtime بعدی:

```text
Model Provider Gateway
  → Ollama local primary
  → OpenAI-compatible fallback/alternate
  → task/provider policy
```

Business-critical calculations و domain execution مستقل از Provider باقی می‌مانند.
