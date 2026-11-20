# RC1 Independent Verification

**Auditor:** T0 final RC1 acceptance closure  
**Verified at (UTC):** 2026-11-21  
**Decision:** `RC1_BLOCKED`  
**Production:** `NOT_DEPLOYED`

## Git / release provenance

| Item | Verified value |
|------|----------------|
| Pre-closure `origin/main` SHA | `2a30f5abac6291af2e6d3ccf1f87446335c84bad` |
| **Final `origin/main` SHA** | **`52fa7f12f465653d6e445d484f1a8dda48215f45`** |
| Closure merge | **PR #32** MERGED (`fix/rc1-stabilization-p1-closure`) |
| PR #30 | MERGED (product RC1) |
| PR #31 | MERGED (audit docs) |
| PR #29 | **CLOSED** — superseded by PR #32; lock/DDL ideas absorbed |
| Schema constant | `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1` |

## Contract classification (closure re-check)

| Contract | Classification | Evidence |
|----------|----------------|----------|
| TR-1 | **PASS_REPRODUCED** | trading cockpit E2E + duplicate code `P2P_SUPPLIER_INVOICE_DUPLICATE`; balances unchanged |
| TR-2 | **PASS_REPRODUCED** | `rc1_acceptance_gate` TR-2 PASS |
| MF-1 | **PASS_REPRODUCED** | Manufacturing lifecycle tested; concurrent issue test_06 → 1 ok + 1 reject; on_hand A=4; cost **UNVALUED**; WIP/FG valuation **not fully supported**; GL **BLOCKED** — no unsupported auto GL (`T5-VALUATION-DECISION.md`, `mf1_acceptance.json`) |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | profile smoke + Agent B local UAT screenshots; **not** STAGING_VERIFIED |
| OPS-1 | **BLOCKED_EXTERNAL** | restore/migrate×2 eng **CLOSED**; provenance `UNAVAILABLE_NOT_DEPLOYED`; no FRESH_INSTALL (`agent_c_provenance_status.json`) |

## Local mandatory gates (this closure)

| Command | Result |
|---------|--------|
| `python scripts/rc1_ci_mysql_php_gate.py` | **PASS** (MF concurrent + trading TR-1; isolated DBs) |
| `python scripts/rc1_acceptance_gate.py --strict-pass` | script **PASS=5** under `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED` — independent acceptance still **BLOCKED** |
| `python scripts/release_gate.py --require-php --require-node` | tip evidence logs under `docs/ai/rc1/evidence/` |
| Disposable sandbox | `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / port 3307 |
| Agent A GHA on Final main | **NOT_RUN** — workflow not installed; `gh auth refresh -h github.com -s workflow` required; **GHA E2E not enabled** |
| Agent B browser screenshots | **LOCAL_BROWSER_VERIFIED** — `docs/ai/rc1/evidence/browser/` |

## Verdict rationale

Engineering P1-005 (MF concurrency, trading Unicode/code, restore drill, local CI MySQL gate) **closed** and merged via PR #32 @ `52fa7f12…`. Local SYS-1 browser UAT is **LOCAL_BROWSER_VERIFIED** (not staging). OPS-1 deployed-schema provenance and installed GHA MySQL+PHP workflow **remain external**. Therefore **`RC1_BLOCKED`**, `NOT_DEPLOYED`. Do **not** accept on source-only Commercial MVP CI alone. Do **not** redefine PASS or invent `STAGING_VERIFIED` / `FRESH_INSTALL`.
