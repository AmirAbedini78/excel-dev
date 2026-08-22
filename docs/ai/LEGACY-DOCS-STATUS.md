# LEGACY-DOCS-STATUS — وضعیت مستندات قبلی

Repository در `docs/` چند سند اولیه دارد:

```text
docs/AI_ACCOUNTING_ARCHITECTURE_FA.md
docs/ACCOUNTING_PRODUCT_ROADMAP_FA.md
docs/CURRENT_SOURCE_REVIEW_FA.md
docs/AI_PHASE1_DEPLOY_FA.md
```

این‌ها برای تاریخچه و فهم Phase 1 ارزشمندند، اما **Roadmap canonical فعلی نیستند**.

علت:
- بعضی بخش‌ها Full Accounting Core و Practice OS را زودتر از تصمیم فعلی قرار می‌دهند.
- وضعیت Toolها/Worker/Deep/Agent نسبت به آن زمان بسیار جلو رفته است.
- Scope فعلی بعداً صریح‌تر محدود شد به AI MVP روی Accounting/Financial module موجود.

قانون:

```text
docs/ai/* = canonical current docs
docs/*.md = historical/legacy unless explicitly promoted
```

هیچ Legacy doc حذف نشود؛ فقط در صورت نیاز با لینک به SmartDocs جدید علامت‌گذاری شود.
