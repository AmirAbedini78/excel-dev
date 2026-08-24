---
id: accounting_financial
status: active_mvp
reads:
  - docs/ai/01-NORTH-STAR.md
  - docs/ai/04-ROADMAP.md
touches_code:
  - app/Modules/AccountingIndustrialModule.php
  - app/Core/AccountingRepository.php
  - app/Core/AccountingSchema.php
  - app/Core/AccountingSchemaExtension.php
  - app/Core/AiToolRegistry.php
smoke_checks:
  - accounting pages load for selected company
  - trial balance reconciles debit/credit
  - draft documents do not leak into confirmed ledger semantics
---

# Accounting & Financial MVP Domain

## Purpose

این Domain بستر اصلی MVP هوشمند است؛ نه پروژه تکمیل کل نرم‌افزار حسابداری.

## Current primitives used by AI

- companies/workspace scope
- chart of accounts
- parties
- items
- sales
- purchases
- vouchers
- trial balance
- party ledger
- treasury/production foundations در سورس موجود

## Rule

اگر AI Workflow به primitive جدید نیاز داشت:

مثلاً:
- sales price resolver
- account search
- payment allocation read
- aging metric
- date/status query

فقط همان primitive با validation/test اضافه شود.

نباید از نیاز یک Tool نتیجه گرفت که کل Inventory/Payroll/Tax module همین فاز کامل شود.

## Accounting semantics

- `draft` نباید به‌طور ضمنی معادل فروش قطعی باشد.
- `confirmed = approved + final`
- پاسخ باید Scope سند را صریح کند.
- item analytics باید line-level semantics را از document-level total جدا نگه دارد.
- Trial Balance balance به معنی سلامت مالی کامل نیست.
- Sales minus purchases = profit نیست.

## v8.8 workflow use

v8.8 روی primitiveهای مالی موجود ساخته شده و **Accounting schema جدیدی اضافه نمی‌کند**.

Dependencyهای مجاز:

```text
document_analytics group_by=party → Tool result party_id → party_ledger
document_analytics group_by=item  → Tool result item_id  → scoped document_analytics
```

این IDها از خروجی واقعی server می‌آیند و هرگز از LLM Plan پذیرفته نمی‌شوند.

## v8.8 live validation

Grounded dependent accounting reads are Live-validated:

```text
Job #37
current-vs-previous confirmed sales
→ compare
→ current-period top party
→ no rows
→ dependent ledger safely skipped
→ accounting_workflow_partial

Job #38
previous-period confirmed sales grouped by party
→ top real party from Tool result
→ party_ledger(real party_id)
→ accounting_workflow_read
```

The LLM selects only server-grounded goal IDs; accounting periods, Tool args, DB IDs, financial values, dependency expansion, and execution remain server-owned/deterministic.

## v8.9 live action boundary

The first controlled accounting action is now Live-validated.

```text
READ real customer/ledger
→ CONDITION balance > 0
→ resolve real debit/credit accounts
→ PROPOSE balanced receipt voucher
→ HUMAN APPROVAL
→ create draft voucher
→ VERIFY approved/final facts unchanged
```

Live grounded example:

```text
party: کارخانه بهین بسته‌بندی
confirmed/final balance before action: 727,100,000 IRR
requested receipt: 100,000,000 IRR
debit account: 10101 بانک ملت - جاری
credit account: 11001 حساب‌های دریافتنی تجاری
proposal: #2
executed voucher: AI-VCH-20260823-193339-D278
voucher status: draft
debit total: 100,000,000 IRR
credit total: 100,000,000 IRR
```

Because the created voucher remains `draft`, it does not alter confirmed/final party ledger or trial-balance facts. Jobs #43/#44 verified this invariant after execution.

## v9.0.1 live financial intelligence

The accounting module now supports a read-only management intelligence layer over confirmed accounting facts.

```text
10 grounded datasets
→ complete-month sales/purchase trend
→ concentration checks
→ draft exposure
→ trial-balance integrity
→ largest confirmed balances
→ severity-safe management priorities
```

Live Job #46:

```text
confirmed sales trend: +26.4%
confirmed purchase trend: -31.9%  [warning]
top customer share: 26.1%
top vendor share: 59.4%
non-final sales: 784,300,000 IRR / 14.2%
trial difference: 0
```

Primary monthly trends use the last two complete Jalali months, not an incomplete current month.

## v9.1.0 live predictive accounting intelligence

The accounting AI now supports a read-only predictive layer over confirmed accounting history.

```text
target month: 1405/06
sales forecast: 2,387,880,000 IRR
sales approximate range: 1,910,304,000–2,865,456,000 IRR
purchase forecast: 1,164,533,333 IRR
purchase approximate range: 908,844,444–1,420,222,222 IRR
confidence: low / 3 complete months
purchase shift: -31.9% [warning]
customer concentration: 26.1%
vendor concentration: 59.4%
non-final sales exposure: 784,300,000 IRR / 14.2%
```

Incomplete current month is excluded from training; forecast bands are planning/error ranges, not formal statistical confidence intervals.
