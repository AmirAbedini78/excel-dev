# 05-WORKFLOW-CONTRACTS — قراردادهای هوش و Agent

## Workflow A — سؤال مالی ساده

مثال:

```text
فروش قطعی این ماه چقدر است؟
```

مسیر:

```text
Prompt
→ deterministic/semantic parse
→ document_analytics
→ fresh DB facts
→ deterministic answer
```

Success:
- عدد از Tool
- Scope وضعیت صریح
- بازه صریح
- zero hallucination

## Workflow B — سؤال چندمرحله‌ای

مثال:

```text
فروش این ماه را با ماه قبل مقایسه کن و مشتری برتر را هم بگو.
```

مسیر هدف v8.8:

```text
Prompt
→ constrained plan
→ validate plan
→ execute steps
→ resolve dependencies
→ deterministic compare/rank
→ final grounded response
```

## Workflow C — تحلیل عمیق

```text
Prompt
→ evidence bundle
→ deterministic financial core
→ data availability flags
→ safe LLM qualitative analysis
→ output validation
→ fallback if unsafe
```

قانون:
نبود داده Cash Flow به معنی «ریسک نقدینگی بیشتر» نیست؛ یعنی تحلیل نقدینگی محدود است.

## Workflow D — عملیات مالی

مثال:

```text
برای فروشگاه پارس دو عدد PLC با قیمت ... پیش‌نویس فاکتور بساز.
```

```text
Prompt
→ parse ID-free intent
→ search_parties
→ search_items
→ ground qty/price/tax
→ server validation
→ create_sales_invoice_draft proposal
→ human approval
→ deterministic draft creation
→ verification
```

## Workflow E — عملیات چندمرحله‌ای

هدف v8.9:

```text
Prompt
→ Planner
→ Read prerequisites
→ condition evaluation
→ Proposal steps
→ approval checkpoint
→ resume
→ execute
→ verify
→ report
```

## Workflow F — Prediction

```text
Question
→ historical series Tool
→ feature pipeline
→ forecasting model
→ backtest/error/confidence
→ risk rule
→ LLM explanation
```

Prediction بدون backtest/confidence نباید به عنوان پیش‌بینی production نمایش داده شود.

## Workflow G — Proactive suggestion

مرحله‌ای:

```text
Rule
→ behavior pattern
→ ranked suggestion
→ precomputed safe draft
→ feedback
→ outcome
```

## Workflow H — RAG

```text
Document
→ ingestion
→ chunk
→ metadata
→ embedding
→ retrieval
→ evidence
→ LLM
```

RAG اجازه ندارد عدد current ledger را جایگزین Tool کند.

## Risk policy target

```text
READ                         → execute
ANALYTICAL DERIVATION        → execute if deterministic
DRAFT LOW-RISK               → policy-dependent later
FINANCIAL MUTATION           → proposal/approval
POST/FINALIZE/HIGH-RISK      → strict approval
EXTERNAL SEND                → separate permission/approval policy
DELETE/REVERSAL              → strict lifecycle + approval
```

## Workflow I — Commercial failure recovery

```text
same Tool call ID + retry
→ atomic Proposal upsert
→ same Proposal ID

same lease + repeated terminal request within 24h
→ same terminal state: replay acknowledgement
→ opposite terminal state: conflict
→ no second side effect / no second worker-counter decrement
```

Recovery فقط delivery را idempotent می‌کند؛ Approval و domain validation را دور نمی‌زند.

## Workflow J — Live terminal observability parity

```text
Worker terminal result
→ persisted redacted metadata
→ authenticated ai_live state
→ SSE or Polling
→ same terminal answer + route/model/metrics/hardening fields
```

Success:
- بدون refresh، `mode`, `model`, model metrics, end-to-end time, latency budget status و risk class دیده شوند؛
- SSE و Polling payload/renderer مشترک داشته باشند؛
- refresh بعدی همان معنی را در server-rendered UI نشان دهد؛
- endpoint فقط برای user/workspace مالک Job قابل خواندن بماند؛
- هیچ token، authorization، Tool argument یا Tool result حساس به UI نرسد.
