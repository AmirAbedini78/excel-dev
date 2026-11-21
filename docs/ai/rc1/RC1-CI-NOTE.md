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

## GitHub Actions (on final main)

| Workflow | Final SHA `2c1cf659…` | Result |
|----------|------------------------|--------|
| Commercial MVP Gate | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788 | **SUCCESS** (source-only) |
| RC1 MySQL+PHP Gate | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 | **SUCCESS** (fail-on-skip) |

- Installed: `.github/workflows/rc1-mysql-php-gate.yml`
- Source mirror: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`
- Historical candidate SUCCESS (pre-merge PR #36): https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555
- Evidence: `docs/ai/rc1/evidence/agent_a_gha_status.json` (candidate era); post-merge claim basis = runs above on `2c1cf65…`
- Local green ≠ GHA green — post-merge Actions SUCCESS is the claim basis for main.
