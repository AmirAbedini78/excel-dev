# 12-FINANCE-CAPABILITY-MATRIX — v10 Finance Action Depth

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

Baseline: `1e42fc49a124b85a94d41c5d5a661c40533330fd`

## Purpose

این سند مشخص می‌کند هر primitive موجود در ماژول Finance فعلی تا چه سطحی توسط UI، Read AI، Agent Action و Automation پوشش داده شده است. معیار «کامل در محدوده خودش» فقط وجود فرم نیست؛ باید مسیر عملیاتی قابل استفاده وجود داشته باشد.

## Live quality baseline

Job #56 در 2026-08-27 با همان Prompt شکست‌خورده Job #55 تکرار شد و PASS کامل داد:

```text
Prompt: مانده کارخانه بهین بسته‌بندی را بررسی کن و فقط وضعیت فعلی را بگو.
Route: grounded_read
LLM: none
Tools: search_parties → party_ledger
Result: 727,100,000 IRR
End-to-end: 1.0s
Semantic correctness: PASS
```

از این نقطه «Job succeeded» بدون صحت نتیجه Business معیار پذیرش نیست.

## Capability matrix

| Domain / primitive | Manual UI | Deterministic/Tool read | Agent action | Approval boundary | Current state |
|---|---:|---:|---:|---:|---|
| Party search / current balance | ✅ | ✅ | n/a | n/a | LIVE-VALIDATED |
| Sales documents | ✅ | ✅ | ✅ `create_sales_invoice_draft` | ✅ | LIVE-VALIDATED |
| Accounting voucher | ✅ | ✅ | ✅ `create_voucher_draft` | ✅ | LIVE-VALIDATED |
| Conditional customer receipt | via voucher | ✅ | ✅ grounded receipt workflow | ✅ | LIVE-VALIDATED |
| Purchase documents | ✅ | ✅ | ✅ `create_purchase_invoice_draft` | ✅ | candidate |
| Cheque list / due-state analytics | ✅ list | ✅ `check_analytics` | n/a | n/a | candidate |
| Cheque creation | ✅ | supporting reads | ✅ `create_check` | ✅ | candidate |
| Bank/cash-account lookup | ✅ | ✅ `search_cash_accounts` | n/a | n/a | candidate supporting tool |
| Bank/cash master-data creation | ✅ | partial | ❌ | — | P1 after pilot need |
| Cash transaction / payment / receipt primitive | schema exists | partial | ❌ | — | NEXT: UI/domain primitive first |
| Purchase return / order / contract | ✅ manual document types | reporting via purchase analytics | ❌ | — | P1 evidence-driven |
| Treasury forecasting | partial via Finance intelligence | ✅ | recommendation only | n/a | existing AI core |

## v10 Cycle 3 additions

### Read tools

- `search_cash_accounts`
- `check_analytics`

`check_analytics` supports bounded enums only:

```text
direction: all | receivable | payable
status: all | open | received | paid | bounced | canceled
due_scope: all | overdue | upcoming_7
limit: 1..100
```

No arbitrary SQL/date expression originates from the LLM.

### Proposal tools

- `create_purchase_invoice_draft` — medium risk
- `create_check` — high risk

Both are `mode=proposal`, require Human Approval, validate workspace/company ownership server-side, and never expose direct DB access to the Worker.

### Purchase action flow

```text
Prompt
→ constrained parse
→ search_parties
→ unique supplier resolve
→ search_items per line
→ server IDs only
→ goods/service consistency gate
→ create_purchase_invoice_draft Proposal
→ Human Approval
→ acc_purchase_docs workflow_status=draft
```

### Cheque action flow

```text
Prompt
→ direction/check no/amount/due-date grounding
→ optional search_parties
→ optional search_cash_accounts
→ create_check Proposal
→ Human Approval
→ acc_checks status=open
```

Cheque creation does not post a GL voucher by itself. Any accounting posting remains a separate controlled workflow.

## Explicitly deferred

- autonomous approval/posting;
- inventing supplier/item/bank IDs;
- mixed goods+service purchase invoice in one AI request;
- arbitrary check status transitions;
- cash payment/receipt Agent action before the corresponding operational UI/domain primitive is made product-complete;
- broad purchase-contract/order automation before Design Partner evidence.

## Acceptance prompts

1. Purchase proposal:

```text
برای تامین کننده «تامین آریا» فاکتور خرید بساز
2 عدد کابل قدرت
با قیمت واحد 500000 ریال
```

Expected: `search_parties → search_items → create_purchase_invoice_draft`, Proposal only.

2. Cheque analytics:

```text
چک های دریافتنی باز سررسید این هفته را گزارش بده
```

Expected: `check_analytics`, no LLM required, no Proposal.

3. Cheque proposal:

```text
چک دریافتنی شماره چک CH-100 به مبلغ 100000000 ریال سررسید 1405/06/20 برای مشتری «کارخانه بهین بسته‌بندی» بانک «بانک ملت - جاری» آماده کن
```

Expected: `search_parties → search_cash_accounts → create_check`, Proposal only.

## Next finance primitive

بعد از Live validation این Cycle، اولویت بعدی Finance این است:

```text
Cash Transaction primitive
→ UI receive/payment
→ deterministic reads
→ create_cash_transaction Proposal
→ approval
→ optional voucher bridge
```

این ترتیب از ایجاد Agent action روی primitive ناقص جلوگیری می‌کند.
