# 06-TEST-RELEASE-PROTOCOL — پروتکل اجباری تغییر و تحویل

## هدف

جلوگیری از تکرار چرخه:

```text
patch کوچک
→ خطای جدید
→ patch کوچک
→ خطای بعدی
→ فرسودگی
```

## A. قبل از ساخت

1. `git rev-parse HEAD`
2. `git status --short`
3. خواندن فایل‌های واقعی Baseline
4. Impact Map
5. Failure inventory
6. Success criteria
7. Expected changed-file set

## B. Candidate-first

تغییر ابتدا خارج از Working Tree ساخته شود.

حداقل Validation قبل از Mutation:

- Python `compile`/`py_compile`
- PHP lint برای فایل‌های PHP
- JavaScript syntax check برای تمام assetهای JS
- static safety guards
- unit tests
- integration test روی actual Worker class
- LF/CRLF patch compatibility اگر patcher وجود دارد
- test برای Failure اصلی Task

اگر Candidate روی mount read-only است، compile نباید به `__pycache__` همان mount نیاز داشته باشد.

## C. Mutation

فقط بعد از Pass:

1. external rollback backup
2. stop/isolate affected worker
3. install exact candidates
4. verify exact file set
5. `git diff --check`
6. SHA verification

## D. Rebuild validation

- Docker rebuild
- compile built image
- verify guard bootstrap
- actual Worker integration
- start worker
- inspect startup logs

## E. Live validation

بر اساس نوع تغییر:

### Read
- actual prompt
- reconciliation against deterministic source
- scope/date/status check
- terminal metadata parity در SSE/Polling بدون refresh و رندر PHP پس از refresh

### Deep
- deterministic core retained
- safe LLM behavior
- no unsupported financial claims
- fallback test

### Agent write
- Proposal arguments
- no generated IDs
- approval required
- execute only after explicit test approval
- verify created Draft
- no duplicate on retry
- blocked/noop path must show attempted model، allowlisted attempted metrics and bounded actual Tool names in SSE/Polling and reload UI
- Tool arguments/results/call IDs must remain absent from the browser payload

### Planner
- dependency correctness
- step count
- no dropped constraints
- no implicit broadening
- no hidden write

## F. Git

فقط فایل‌های دقیق:

```powershell
git add -- path1 path2 ...
git diff --cached --check
git diff --cached --stat
git status --short
```

`git add .` ممنوع به عنوان default.

## G. cPanel deploy rule

اگر `app/*`, root API, schema/runtime یا UI web تغییر کرده:
- Commit/Push
- cPanel Update from Remote
- Deploy HEAD
- live web test

اگر فقط `engine/*` تغییر کرده:
- cPanel deploy معمولاً لازم نیست؛ مگر Contract سرور هم تغییر کرده باشد.

## H. Documentation closeout

قبل از بستن Task:

- update `02-CURRENT-STATE.md`
- update Domain doc
- append `08-HISTORY-SNAPSHOT.md`
- update `task_state.json`
- architecture/roadmap docs در صورت نیاز

## Definition of Done

Feature فقط وقتی Done است که سطح Validation آن مشخص باشد:

```text
IMPLEMENTED
LOCAL-VALIDATED
LIVE-VALIDATED
```

برای Milestone مالی مهم، `LIVE-VALIDATED` هدف است.

## I. Commercial MVP gate — v9.3+

قبل از staging:

```bash
python scripts/release_gate.py
python scripts/release_gate.py --require-node
```

در CI یا محیط دارای PHP:

```bash
python scripts/release_gate.py --require-php --require-node
```

از v9.3.0.2، PHP gate علاوه بر lint، تست رفتاری `tests/php_live_observability_test.php` را نیز اجرا می‌کند تا allowlist نام ابزار/metrics و parity رندر reload به‌صورت اجرایی بررسی شود.

برای تغییر هم‌زمان Worker + Control Plane، هر سه سطح اجباری‌اند:

1. local candidate regression/contract suite؛
2. GitHub CI شامل PHP lint؛
3. cPanel deploy + Docker rebuild + Live recovery/Proposal verification.

در installer ویندوز هیچ runtime میزبان استفاده نمی‌شود: Python/PHP/Node validation باید داخل Docker اجرا شود. نبود runtime محلی فقط برای توسعه موقت warning است و اجازه حذف Gate اجباری CI/release را نمی‌دهد. Checklist دقیق در `09-COMMERCIAL-MVP-RELEASE.md` است.
