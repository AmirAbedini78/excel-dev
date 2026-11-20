# RC1 acceptance CI (manual / local until workflow scope available)

## Local (authoritative until GHA installed)

```
set RC1_REQUIRED=1
set O2C_DB_NAME=erpsmart_rc1_test
python scripts/rc1_acceptance_gate.py --strict-pass
```

Mandatory fail-on-skip MySQL+PHP pair:

```
python scripts/rc1_ci_mysql_php_gate.py
```

Do not treat Commercial MVP Gate alone as RC1 pass.

## GitHub Actions (NOT enabled yet)

- Proposed workflow: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml` (uses `O2C_DB_HOST=127.0.0.1` for Linux runners).
- **Not** present under `.github/workflows/` as of Final main `52fa7f12…`.
- Token lacked `workflow` scope (`gist`, `read:org`, `repo` only).
- **One-time owner auth:** `gh auth refresh -h github.com -s workflow`
- Then copy YAML into `.github/workflows/rc1-mysql-php-gate.yml`, push, and require a real Actions **SUCCESS**.
- Until that SUCCESS exists: **do not claim GHA E2E enabled**. Local green ≠ GHA green.
- Evidence: `docs/ai/rc1/evidence/agent_a_gha_status.json`
