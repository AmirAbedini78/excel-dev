# Defect RC1-P0-003 — CI release_gate does not mandatorily run PHP+MySQL E2E

| Field | Value |
|-------|-------|
| ID | RC1-P0-003 |
| Severity | P0 |
| Contract | OPS-1 / RC gate |
| Owner | **T7** |
| Status | RESOLVED — `scripts/rc1_acceptance_gate.py --strict-pass` PASS=5 on release HEAD |
| Baseline | G0 `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| Reporter | T7 |
| Date | 2026-11-20 |

## Summary
`scripts/release_gate.py` + `.github/workflows/commercial-mvp-gate.yml` are **source-only**. Mandatory PHP domain E2E against MySQL can SkipTest without failing Commercial MVP Gate.

## Mitigation delivered (this PR)
- `scripts/rc1_acceptance_gate.py` — RC1 REQUIRED; SKIP/missing dependency → nonzero
- `.github/workflows/rc1-acceptance.yml` — additive workflow; documents insufficiency of Commercial MVP Gate
- Note appended to `release_gate.py` output

## Residual
GitHub-hosted runner may lack `erpsmart-o2c-php:8.2` and the G0-named MySQL container. Local verified sandbox remains authoritative until CI image/service parity. Set repo variable `RC1_ACCEPTANCE_REQUIRED=true` when ready to hard-fail PRs.
