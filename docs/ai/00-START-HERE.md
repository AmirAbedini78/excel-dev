# 00-START-HERE — ERPSMART AI Development Entrypoint

> این فایل نقطه شروع اجباری هر Session توسعه است.

## ترتیب مطالعه

قبل از طراحی یا تغییر کد:

1. `00-GOVERNANCE.md`
2. `01-NORTH-STAR.md`
3. `02-CURRENT-STATE.md`
4. `03-ARCHITECTURE.md`
5. `04-ROADMAP.md`
6. Domain مرتبط از `02-domains/INDEX.yml`
7. در صورت عملیات/تحلیل AI: `05-WORKFLOW-CONTRACTS.md`
8. قبل از تحویل: `06-TEST-RELEASE-PROTOCOL.md`
9. برای Commercial MVP: `09-COMMERCIAL-MVP-RELEASE.md`

برای انتقال سریع Context به AI جدید، بعد از موارد بالا این فایل را بخوان:

```text
AI_DEVELOPMENT_HANDOFF_FA.md
```

## دستور استاندارد برای AI/Developer

قبل از کدنویسی باید صریحاً مشخص شود:

```text
Current baseline:
Current phase:
Requirement:
In scope:
Out of scope:
Affected contracts:
Risk:
Success criteria:
Files expected to change:
Tests required:
```

اگر Requirement کاربر با `01-NORTH-STAR.md` یا Scope فعلی تعارض داشت، بدون ساختن هدف جدید یا حدس زدن Roadmap، تعارض را اعلام کن و فقط پس از تصمیم صریح کاربر سند North Star/Roadmap را تغییر بده.

## قانون Source of Truth

ترتیب اولویت:

```text
Repository code + tests
        ↓
Live/runtime evidence
        ↓
docs/ai canonical docs
        ↓
legacy docs
        ↓
conversation history
```

History برای فهم علت تصمیم‌هاست، نه جایگزین وضعیت فعلی سورس.

## Current snapshot

- Repository: `AmirAbedini78/excel-dev`
- SmartDocs foundation commit: `cd13fae227f18229ee734958ea465b41885e78e2`
- Live product baseline entering closeout: `448fca0b00a5ef2470e5498a9e25981ce30a7865` (`feat(accounting): add voucher detail view`)
- Current milestone: `v9.3 — Commercial MVP`
- Validation state: **LIVE-VALIDATED / FEATURE FROZEN**. Job #50 closed read observability, Job #53 closed blocked-action Tool/model parity, Job #54 created grounded Proposal #3, human approval created balanced draft `AI-VCH-20260826-202025-9F19`, and the Accounting UI verified both stored articles with debit = credit = `100,000,000 IRR`.
- Retry/replay state: deterministic Proposal idempotency and terminal replay tests PASS. Standalone live fault-injection harnesses are deferred unless a real runtime failure appears.
- Closeout-only changes: draft-only voucher deletion guard, release-gate exclusion for the intentionally local/gitignored `engine/config.json`, and SmartDocs synchronization. No new AI/financial capability is added.
- Release contract: `09-COMMERCIAL-MVP-RELEASE.md`
- Next target after closeout deploy: **RC/demo/market/customer/pricing/positioning/GTM**; no new core feature development inside v9.3.
- Scope: **Accounting/Financial AI MVP only**
