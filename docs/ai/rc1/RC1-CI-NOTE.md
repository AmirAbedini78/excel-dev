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

## Dual DB host (host vs PHP container)

| Env | Purpose | Typical GHA value |
|-----|---------|-------------------|
| `O2C_DB_HOST` | Runner / pymysql | `127.0.0.1` |
| `O2C_DB_HOST_PHP` | Passed into PHP Docker | `host.docker.internal` |

Do **not** forward loopback into PHP containers: inside the container `127.0.0.1` is not the MySQL service. The gate remaps loopback → `host.docker.internal` when `O2C_DB_HOST_PHP` is unset. E2E harnesses always pass `--add-host=host.docker.internal:host-gateway`.

## GitHub Actions

- Source of truth YAML: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`
- Install path: `.github/workflows/rc1-mysql-php-gate.yml`
- **One-time owner auth if push rejected:** `gh auth refresh -h github.com -s workflow`
- Until a real Actions **SUCCESS** exists: **do not claim GHA E2E enabled**. Local green ≠ GHA green.
- Evidence: `docs/ai/rc1/evidence/agent_a_gha_status.json`
