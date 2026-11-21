# T6 Wave1 local evidence

**Worktree:** `C:\Projects\erp-worktrees\agentic-t6`  
**Branch:** `test/agentic-evaluation-wave1`  
**Date:** 2026-11-21  
**Host:** Windows + Docker `php:8.2-cli` (no host PHP)

## Wave1 T6 harness

```text
python -m unittest tests.test_agentic_wave1_security_adversarial tests.test_agentic_wave1_idempotency_recovery_stubs -v
→ Ran 24 tests in 54.541s
→ OK (skipped=8, expected failures=1)
```

| Result | Count | Notes |
|--------|------:|-------|
| PASS | 15 | Source + kernel adversarial + PHP harness + recovery source contracts |
| SKIP | 8 | 7× OWNER:T1 (`agent_task_state` absent on branch); 1× MySQL cross-tenant fixture absent |
| xfail | 1 | Pure privilege-escalation free text (G1) — OWNER:T6 harden before AI1_ACCEPTED |

Raw log: `_wave1_harness_run.txt`

## PHP pure harness (Docker)

```text
T6_WAVE1_SECURITY_HARNESS_PASS cases=22
T6_GUARDRAILS_PASS
```

## Existing AI-related suites (local)

```text
python -m unittest
  tests.test_cycle12_3_agent_intelligence_kernel
  tests.test_cycle12_2_agent_core_rescue
  tests.test_cycle12_skills_capability_nlp
  tests.test_release_contracts
  tests.test_rc1_t6_platform_ux
  tests.test_cycle11_workspace_copilot_mvp
  tests.test_cycle10_universal_copilot_foundation
  tests.test_cycle9_universal_copilot_spec -v
→ Ran 140 tests in 19.253s
→ OK
```

Raw log: `_existing_ai_run.txt`

## Not run here (preserve RC1; no claim)

- Commercial MVP / RC1 MySQL+PHP full gates (CI)
- TR-1..OPS-1 MySQL E2E
- Live model / browser journeys

## Verdict (T6)

`READY_FOR_AI1_QA` — Wave1 security harness + metrics doc + recovery stubs delivered with recorded skips/xfails.  
**Does not claim `AI1_ACCEPTED`.**
