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
- Deployed runtime baseline: `2f196868c9f27c719cf0165fd541656a2e5f11d4` (`v9.3.0.1`)
- Current implementation milestone: `v9.3.0.2 — Safe Attempt Observability Hotfix`
- Validation state: v9.3.0.1 CI/cPanel passed. Job #50 proved no-refresh route/model/latency/budget/risk parity. Job #51 proved blocked-action model correction and fail-closed behavior, but actual Tool names and attempted-model metrics were still absent from the UI; v9.3.0.2 is the scoped web-only closeout.
- Release contract: `09-COMMERCIAL-MVP-RELEASE.md`
- Next target: validate/deploy v9.3.0.2 on exact baseline `2f19686`, repeat blocked Job #51 and require bounded Tool names plus attempted-model metrics in both no-refresh and reload UI, then complete recovery/Proposal/Approval/redaction gates.
- Scope: **Accounting/Financial AI MVP only**
