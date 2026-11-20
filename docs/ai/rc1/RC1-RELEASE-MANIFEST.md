# RC1 Release Manifest (template — filled with known state)

**Status:** NOT_READY_FOR_RELEASE (`RC1_BLOCKED`)  
**Filled by:** T7  
**Date:** 2026-11-20  

## Identity

| Field | Value |
|-------|-------|
| Integration branch | `release/erpsmart-rc1` |
| T7 feature branch | `feature/rc1-t7-qa-release` |
| G0 baseline SHA | `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| T7 HEAD (fill at merge) | _(see PR)_ |
| Product version constant | `RuntimeCache::SCHEMA_VERSION = 10.23.0` |
| Deploy | **NOT_DEPLOYED** / no cPanel |

## Artifacts owned by this PR

| Path | Purpose |
|------|---------|
| `scripts/rc1_acceptance_gate.py` | RC1 REQUIRED gate (nonzero on SKIP/missing deps; exit 2 on BLOCKED) |
| `scripts/release_gate.py` | Note that Commercial MVP is source-only |
| `.github/workflows/rc1-acceptance.yml` | Additive CI workflow |
| `tests/rc1/*` | Acceptance modules TR-1…OPS-1 |
| `tests/harness/rc1_*` | Composite PHP runners + probes |
| `docs/ai/rc1/RC1-*.md`, `T7-HANDOFF.md`, `defects/*`, `evidence/*` | Release evidence |

## Schema / DB

| Field | Value |
|-------|-------|
| Verified container | `erpsmart-p2p-mysql-test` / `02eb215a87f6…` |
| PHP image | `erpsmart-o2c-php:8.2` |
| T7 disposable schema | `erpsmart_rc1_t7_test` (same container; avoids cross-lane races on `erpsmart_p2p_test`) |
| Production schema provenance | **MISSING** → OPS-1 BLOCKED |

## Env checklist (non-secret)

```
RC1_REQUIRED=1
O2C_DB_HOST=host.docker.internal
O2C_DB_PORT=3307
O2C_DB_USER=p2p
O2C_DB_NAME=erpsmart_rc1_t7_test
# pass from secret store
```

## Backup / rollback

- Production backup: **NOT PERFORMED** (no deploy).
- Disposable restore rehearsal: blocked on staging dump (RC1-P1-001).
- Rollback procedure: restore prior dump; do not reverse-migrate speculative DROP.

## Checksums / evidence

See `docs/ai/rc1/evidence/rc1_gate_report.json` and per-contract JSON files after gate run.

## Sign-off

| Role | Status |
|------|--------|
| T7 QA | REJECT exit — MF-1 + OPS-1 BLOCKED |
| T0 | pending |
| User prod auth | not requested |
