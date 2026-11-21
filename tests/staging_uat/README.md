# RC1 Staging UAT — Playwright skeleton (optional)

**Purpose:** Executable examples for **future authorized staging** browser checks.  
**Staging-release SHA:** `POST_MERGE_MAIN_TIP_CI_GREEN`  
**Product baseline:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Status:** Not run by default. Repo has **no** root `package.json`; adopt Playwright locally when owner provisions staging.  
**Classification:** see [`docs/ai/rc1/RC1-STAGING-UAT-INVENTORY.md`](../../docs/ai/rc1/RC1-STAGING-UAT-INVENTORY.md) — page-open â‰  full pay proof.

## Guardrails

1. Set `STAGING_BASE_URL` to the owner-approved **non-production** staging origin (e.g. `https://staging.example.com`), no trailing slash.
2. Tests **fail closed** if `STAGING_BASE_URL` is unset (see `staging-guard.ts`).
3. Tests **fail closed** if the host looks production-like (`prod`, `production`, `live`, `cpanel`, `excel2`, etc.).
4. **Do not** point at production. **Do not** run this suite in CI against remote hosts without explicit owner workflow.
5. Credentials via env only (`STAGING_UAT_EMAIL`, `STAGING_UAT_PASSWORD`) — never commit.

## Procedures cross-reference

Manual steps and evidence rules: [`docs/ai/rc1/RC1-STAGING-UAT-PROCEDURES.md`](../../docs/ai/rc1/RC1-STAGING-UAT-PROCEDURES.md).

## Local disposable UAT

For local disposable runs, use Agent B evidence (`T6-BROWSER-NOTES.md`) or MCP browser — **not** this suite unless you explicitly set `STAGING_BASE_URL=http://127.0.0.1:8765` and accept that label remains `LOCAL_BROWSER_VERIFIED`, not `STAGING_VERIFIED`.

## Setup (operator machine)

```bash
cd tests/staging_uat
npm init -y
npm install -D @playwright/test
cp playwright.config.example.ts playwright.config.ts
# Linux/macOS:
export STAGING_BASE_URL="https://your-staging-host"
export STAGING_UAT_EMAIL="..."
export STAGING_UAT_PASSWORD="..."
npx playwright test
```

Windows PowerShell:

```powershell
$env:STAGING_BASE_URL = "https://your-staging-host"
$env:STAGING_UAT_EMAIL = "..."
$env:STAGING_UAT_PASSWORD = "..."
npx playwright test
```

## Layout

| File | Role |
|------|------|
| `staging-guard.ts` | URL + production hostname guard |
| `playwright.config.example.ts` | Copy to `playwright.config.ts` |
| `specs/*.spec.ts` | Example specs (some require credentials) |

## Claiming verification

Passing these tests **does not** imply `STAGING_VERIFIED`. Owner must follow procedure doc آ§16 and capture evidence under `docs/ai/rc1/evidence/browser/staging/`.