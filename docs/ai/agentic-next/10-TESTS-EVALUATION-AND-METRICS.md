# 10 — Tests, Evaluation and Metrics

**Owner:** T6 (QA / Security / Evaluation)  
**Authority:** Master directive §12 + `04-AGENT-RUNTIME-CONTRACT.md` §6  
**Branch evidence:** `test/agentic-evaluation-wave1`  
**Baseline SHA (campaign start):** `db849ca09b54c52e4729ee813abb1f6695549ac9`  
**AI1_ACCEPTED:** **NOT claimed by T6** (independent acceptance only after T0 + evidence)

---

## 1. Minimum evaluation matrix (master directive)

| Category | Minimum cases | Wave1 status |
|----------|--------------:|--------------|
| Multi-module business questions/workflows | 60 | PENDING (Waves 2–4; existing cycle9–12 suites count toward baseline) |
| RAG retrieval and grounded answers | 40 | PENDING (T2 Wave2) |
| Security and adversarial tests | 30 | **IN_PROGRESS** — Wave1 harness seeded (see §3) |
| Approval, retry, recovery and idempotency | 20 | **IN_PROGRESS** — stubs + main lease/idempotency contracts; T1 behavioral cases when `agent_task_state` merges |
| Provider/model compatibility | Per supported provider | PENDING (T4) |
| UI/browser functional scenarios | Per core journey | PENDING (T5 Wave4) |

These are coverage targets, not permission to invent trivial duplicates.

---

## 2. Acceptance thresholds

| Gate | Threshold |
|------|-----------|
| Security / financial integrity | **100% pass** (no silent skip of required cases) |
| Mandatory E2E workflows | Pass with PHP/MySQL or browser evidence |
| Retrieval / semantic quality | Numerical thresholds agreed **before** final eval (not retrofitted) |
| Regression | Commercial MVP + RC1 MySQL/PHP + TR-1..OPS-1 remain runnable |
| Skips | Recorded separately; required tests must fail if unexpectedly skipped |
| Assertion weakening | **Forbidden** |

Evidence classes must be labeled: `source`, `pure_php`, `python_unit`, `mysql_runtime`, `browser`, `live_model`.

---

## 3. Wave1 security / adversarial harness

| ID | Case | Evidence class | Owner | Harness |
|----|------|----------------|-------|---------|
| S1 | Invalid tool ID rejected | source + pure_php + python_unit | T6 | `tests/test_agentic_wave1_security_adversarial.py`, `tests/php_agentic_wave1_security_harness.php` |
| S2 | Cross-company context cannot leak | source + python_unit; mysql_runtime SKIP until fixture | T6 (+infra) | same |
| S3 | Unsupported / invented operation fails closed | python_unit + pure_php | T6 | same |
| S4 | Proposal / write bypass blocked | pure_php + source | T6 | same + existing `php_rc1_t6_guardrails_test.php` |
| S5 | Prompt-injection via tool/RAG text cannot escalate authority | python_unit + source (worker untrusted clause) | T6 | same |
| S6 | Financial writes remain approval-gated | source | T6 | same + `AiRepository::approveProposal` |
| G1 | Pure privilege-escalation free text (no mutation keywords) | pure_php | T6 harden | `expectedFailure` until patterns extended — **must clear before AI1_ACCEPTED** |

---

## 4. Idempotency / recovery stubs (coordinate T1)

Contract states (`result_json.agent_task`):

`CREATED → VALIDATING → PLANNING → EXECUTING_READS → ANALYZING → PROPOSAL_READY → AWAITING_APPROVAL → EXECUTING_APPROVED_ACTION → VERIFYING → COMPLETED`  
(+ terminal: `FAILED|CANCELLED|EXPIRED|BLOCKED|PARTIALLY_COMPLETED`)

| ID | Case | Owner | Status on this branch |
|----|------|-------|------------------------|
| R1 | Duplicate job completion / callback | T6 source + T1 state | Source contracts pass; T1 API tests SKIP until `engine/agent_task_state.py` present |
| R2 | Worker restart mid-mutation → VERIFYING | T1 | SKIP until module lands |
| R3 | Lease expiry requeue | T6 source (AiRepository) | PASS (source) |
| R4 | Company change → re-VALIDATING; keep mutation keys | T1 | SKIP until module lands |
| R5 | Invalid tool → BLOCKED | T1 + T6 | SKIP until module lands |
| R6 | Proposal idempotency unique key | T6 source | PASS |

Harness: `tests/test_agentic_wave1_idempotency_recovery_stubs.py`

---

## 5. Mandatory regression (must remain runnable)

- Commercial MVP Gate (`scripts/release_gate.py`)
- RC1 MySQL/PHP Gate
- TR-1, TR-2, MF-1, SYS-1, OPS-1
- Existing finance/stock/tenant isolation tests
- Existing AI guardrails + Worker regression (`tests/php_rc1_t6_guardrails_test.php`, cycle12 kernel suites)

---

## 6. Metrics to record (when environments exist)

P50/P95 latency, tool calls/request, tokens/request (if available), provider fallback rate, retrieval latency, indexing throughput, memory, error rate, workflow completion rate.

Do not claim improvement without measurements on disposable/local fixtures.

---

## 7. How to run Wave1 T6 suite locally

```text
python -m unittest tests.test_agentic_wave1_security_adversarial -v
python -m unittest tests.test_agentic_wave1_idempotency_recovery_stubs -v
python -m unittest tests.test_cycle12_3_agent_intelligence_kernel tests.test_release_contracts tests.test_rc1_t6_platform_ux -v
# PHP (Docker if host PHP missing):
docker run --rm -v "%CD%":/app -w /app php:8.2-cli php tests/php_agentic_wave1_security_harness.php
docker run --rm -v "%CD%":/app -w /app php:8.2-cli php tests/php_rc1_t6_guardrails_test.php
```

Evidence log: `docs/ai/agentic-next/evidence/t6_wave1_local_run.md`
