# RC1 acceptance CI note

**Commercial MVP Gate is insufficient for RC1.**

| Gate | What it proves |
|------|----------------|
| `.github/workflows/commercial-mvp-gate.yml` + `scripts/release_gate.py` | Source/runtime syntax + contract unit tests |
| `docs/ai/rc1/workflows/rc1-acceptance.yml` (install to `.github/workflows/`) + `scripts/rc1_acceptance_gate.py` | Real PHP domain methods + disposable MySQL E2E |

SKIP of mandatory PHP+MySQL E2E = **FAIL** RC gate.

## Local (authoritative G0 sandbox)

```powershell
$env:RC1_REQUIRED='1'
$env:O2C_DB_NAME='erpsmart_rc1_t7_test'
py -3 scripts/rc1_acceptance_gate.py --strict-pass
```

Sandbox: container `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / PHP image `erpsmart-o2c-php:8.2`.

## GitHub Actions

Workflow source lives at `docs/ai/rc1/workflows/rc1-acceptance.yml` (additive). Copy into `.github/workflows/` with a token that has the GitHub `workflow` scope (OAuth apps without that scope cannot push workflow files). Set repository variable `RC1_ACCEPTANCE_REQUIRED=true` to hard-fail when the runner cannot prove E2E (missing PHP image still fails honestly — never synthetic PASS).
