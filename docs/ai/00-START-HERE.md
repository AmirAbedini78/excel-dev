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
- Deployed runtime baseline: `27e34a9af3d1ca05a2b25f5aa2b60a94a86a369c` (`v9.3.0`)
- Current implementation milestone: `v9.3.0.1 — Live Observability Hotfix`
- Validation state: v9.3.0 CI/cPanel/Worker startup passed; Job #49 proved the Grounded read path but exposed missing hardening metadata in the no-refresh UI. Hotfix is `LOCAL-VALIDATED` and deploy validation is pending.
- Release contract: `09-COMMERCIAL-MVP-RELEASE.md`
- Next target: deploy v9.3.0.1, repeat the live read-metadata check without refresh, complete the remaining v9.3 checklist, then freeze the Commercial MVP.
- Scope: **Accounting/Financial AI MVP only**
