# RC1 Independent Verification

**Auditor:** T0 RC1 P1 closure (local tools; prior agent region-denied)  
**Verified at (UTC):** 2026-11-20 (closure)  
**Decision:** `RC1_BLOCKED`  
**Production:** `NOT_DEPLOYED`

## Git / release provenance

| Item | Verified value |
|------|----------------|
| Baseline `origin/main` SHA (pre-closure) | `2a30f5abac6291af2e6d3ccf1f87446335c84bad` |
| Closure branch | `fix/rc1-stabilization-p1-closure` |
| PR #30 | MERGED (product RC1) |
| PR #31 | MERGED (audit docs) |
| PR #29 | OPEN/CONFLICTING vs release — **superseded**; lock/DDL ideas absorbed; close after stabilization merge |
| Schema constant | `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1` |

## Contract classification (closure re-check)

| Contract | Classification | Evidence |
|----------|----------------|----------|
| TR-1 | **PASS_REPRODUCED** | trading cockpit E2E + duplicate code `P2P_SUPPLIER_INVOICE_DUPLICATE`; balances unchanged |
| TR-2 | **PASS_REPRODUCED** | `rc1_acceptance_gate` TR-2 PASS |
| MF-1 | **PASS_REPRODUCED** | concurrent issue test_06 → 1 ok + 1 reservation/insufficient reject; on_hand A=4; valuation **UNVALUED** / GL **BLOCKED** |
| SYS-1 | **BLOCKED_EXTERNAL** | profile smoke script PASS only; **no** authenticated browser; `T6-BROWSER-NOTES.md` |
| OPS-1 | **BLOCKED_EXTERNAL** (partial engineering PASS) | restore/migrate×2 PASS; provenance UNAVAILABLE_NOT_DEPLOYED; browser NOT_RUN |

## Local mandatory gates (this closure)

| Command | Result |
|---------|--------|
| `python scripts/rc1_ci_mysql_php_gate.py` | **PASS** (MF concurrent + trading TR-1; isolated DBs) |
| `python scripts/rc1_acceptance_gate.py --strict-pass` | script **PASS=5** under `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED` — independent acceptance still **BLOCKED** on SYS-1 browser + provenance honesty |
| `python scripts/release_gate.py --require-php --require-node` | unittest PASS (453, skipped=13); Docker PHP lint + Node required on tip |
| Disposable sandbox | `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / port 3307 |

## Verdict rationale

Engineering P1-005 (MF concurrency, trading Unicode/code, restore drill, CI MySQL gate) **closed**. Mandatory SYS-1 browser + OPS-1 deployed-schema provenance **remain external**. Therefore **`RC1_BLOCKED`**, `NOT_DEPLOYED`. Do **not** accept on source-only Commercial MVP CI alone.
