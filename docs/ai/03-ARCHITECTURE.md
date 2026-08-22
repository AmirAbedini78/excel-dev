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

در Snapshot v8.7 ترتیب مفهومی Guardها:

```text
Safe Deep
→ Guarded Invoice Agent
→ Grounded/Parameterized Read
→ Adaptive Read Plan Cache
```

این Guardها Proof-of-Architecture هستند. هدف v8.8+ این است که منطق چندمرحله‌ای Accounting به یک Planner/Orchestrator عمومی‌تر تبدیل شود، بدون حذف safety contracts.

## 5. Tool contract

Toolهای Read می‌توانند مستقیم اجرا شوند.

Toolهای Mutation در حالت فعلی Proposal هستند.

Server باید:
- workspace/company ownership را validate کند.
- identifier و status را allowlist کند.
- dates را normalize/validate کند.
- idempotency را enforce کند.
- audit بنویسد.

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
