# 10 — Tests, Evaluation and Metrics (Wave1 seed)

## Wave1 mandatory (AI1)

| Case | Harness | Status |
|------|---------|--------|
| Invalid tool ID rejected | `tests/test_agentic_wave1_security_adversarial.py` + registry source | required |
| Cross-company envelope strips entities | kernel adversarial | required |
| Financial writes approval-gated | guardrails + AiRepository source | required |
| Durable task transitions / idempotent mutation | `tests/test_agentic_wave1_agent_task_state.py` | required |
| Lease recovery mid-mutation → VERIFYING | agent_task_state | required |
| Privilege escalation free-text blocked | `AiProposalGuardrails` + PHP harness | required |
| PHP proposal bypass modes | `tests/php_agentic_wave1_security_harness.php` | required |

## Regression (must remain green)

- Commercial MVP Gate
- RC1 MySQL+PHP Gate
- TR-1 / TR-2 / MF-1 / SYS-1 / OPS-1

## Minimum matrix (later waves)

| Category | Minimum |
|----------|--------:|
| Multi-module workflows | 60 |
| RAG retrieval | 40 |
| Security adversarial | 30 |
| Approval/retry/idempotency | 20 |
| Provider qualification | per provider |
| Browser journeys | per core UX |

Record skip/fail/block separately. Do not weaken assertions for green CI.
