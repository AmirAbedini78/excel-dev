# RC1 acceptance CI

## Local

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

Do **not** forward loopback into PHP containers. Gate remaps loopback → `host.docker.internal` when `O2C_DB_HOST_PHP` is unset. E2E harnesses pass `--add-host=host.docker.internal:host-gateway`.

## GitHub Actions (enabled on candidate)

- Installed: `.github/workflows/rc1-mysql-php-gate.yml`
- Source mirror: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`
- Real SUCCESS: https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555 on SHA `03728b145e67f0f6fe2179fe6a7e1df0badad8e5` (PR #36)
- Evidence: `docs/ai/rc1/evidence/agent_a_gha_status.json`
- Local green ≠ GHA green — claim basis is the Actions SUCCESS above.
