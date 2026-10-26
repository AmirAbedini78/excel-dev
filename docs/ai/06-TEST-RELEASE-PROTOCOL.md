# 06-TEST-RELEASE-PROTOCOL — پروتکل اجباری تغییر و تحویل

## 2026-09-11 — v10.10.3 / Kernel v1.6: bounded stream recovery

Current package: `ERPSMART-v10.10.3-stream-recovery-local-validated.zip`, applied over the exact delivered v10.10.2 working state. Current implementation and incident evidence: **30-STREAM-RECOVERY-20260911.md**; install/rollback: **28-LOW-MEMORY-INSTALL-FA.md**; results: **29-LOW-MEMORY-TEST-REPORT-FA.md**.

The supplied Windows log proves v10.10.2 Docker build, 268 offline tests, file installation and Worker startup PASS. Its model gate is DEGRADED: qwen3.5:0.8b provider_error, qwen3:1.7b deadline, gemma3:4b memory_admission. This does not establish an OOM for the first model. A reproducible cross-thread HTTP chunk cancellation bug is fixed in v1.6; first-request allowance, shared endpoint cooldown and redacted per-case evidence are included. v1.6 remains LOCAL-VALIDATED until tested on that engine. MVP-B semantic acceptance remains open; MVP C Role Brief / Intelligent Home follows that acceptance. Older dated entries below describe historical states.

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


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

## Agent Intelligence Kernel release gates

A green routing/unit test is not enough for an Agent release. Any Agent Intelligence Kernel candidate must additionally pass:

1. semantic benchmark over natural Persian business questions and Context combinations;
2. exact EntityRef preservation for explicit multi-entity questions;
3. action-menu validation proving the model can select only server-declared `action_id`;
4. write-bypass tests proving Proposal/Approval remains outside generic read planning;
5. useful deterministic decision fallback when planner/analyst output is invalid;
6. in v1.5 default `auto` mode: bounded deterministic read-policy tests; optional `kernel_planner_mode=model` still requires its own model-planner qualification before claiming that mode live-validated;
7. real local-model **analyst** `grounded_selection_v3` qualification; an installation may report explicit DEGRADED, which is not a model-quality PASS;
8. full existing regression and commercial release gate;
9. live browser acceptance on decision quality, not merely Tool routing.

The real model smoke lives in `engine/agent_kernel_smoke.py`; focused contracts live in `tests/test_cycle12_3_agent_intelligence_kernel.py`; semantic prompts live in `tests/fixtures/agent_intelligence_kernel_benchmark.json`.

### Agent decision-model route gate

For Agent Kernel releases, a model being installed or passing a trivial JSON smoke is insufficient. The v1.5 analyst preflight tries only currently configured decision candidates against three production-contract finding/action-ID scenarios. It persists the first passing model, or an explicit degraded result, only under gitignored `engine/data/agent_kernel_model_route.json`. Runtime validates expiry, version, model configuration and runtime/model fingerprint. Installation integrity, model qualification and ERP product acceptance must be reported separately.
