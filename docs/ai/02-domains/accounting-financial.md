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
