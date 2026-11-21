# RC1 Staging Deployment Plan (PLAN ONLY)

**Lane:** D — Staging deployment plan (docs only)  
**Campaign:** ERPSMART RC1 STAGING RELEASE READINESS  
**Date:** 2026-11-21  
**Authoritative staging-release SHA:** `POST_MERGE_MAIN_TIP_CI_GREEN`  
**Product feature baseline:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Includes:** PR #38 staging executor + safety patch  
**Authorization:** Remote staging access **NOT** granted. This document is a **plan only**. No remote mutate, no cPanel/SSH/FTP by agents, no production language that implies go-live authorization.

---

## 0. Hard constraints (non-negotiable)

| Rule | Meaning |
|------|---------|
| Docs/plan only | No remote staging or production changes without explicit owner auth (not granted). |
| Label separation | `RC1_ACCEPTED` â‰  `STAGING_READY` â‰  `STAGING_VERIFIED` â‰  `PRODUCTION_READY`. |
| MF residual | WIP/FG valuation = **not fully supported**; cost may be `UNVALUED`; GL = `GL_BLOCKED` / no auto WIP/FG GL. |
| Regulatory | **No invented Iranian regulatory / bank / tax / Sayad compliance claims.** |
| Path1 | `FRESH_INSTALL` is **invalid** — owner confirmed prior ERPSMART install (`EXISTING_DEPLOYMENT_CONFIRMED`). |
| Migrate path | Operator-controlled **offline** migrate — **not** first HTTP hit / not `install.php` as upgrade path. |
| Secrets | Unredacted dumps / credentials stay **out of Git**. |

### Provenance sources used (read-only)

- `RC1-ROLLBACK-RUNBOOK.md`
- `RC1-MIGRATION-PREFLIGHT.md` / `RC1-MIGRATION-PRE-FLIGHT.md`
- `RC1-MIGRATION-REHEARSAL.md`
- `OPS1-OWNER-ACTIONS.md`
- `LEGACY-DEPLOYMENT-PROVENANCE.md`
- `RC1-RELEASE-MANIFEST.md`
- `RC1-BLOCKERS-AND-REMEDIATION.md`
- Plus: `T6-BROWSER-NOTES.md`, `LEGACY-SCHEMA-EXPORT-INSTRUCTIONS.md`

---

## 1. Target architecture (shared hosting / cPanel staging assumptions)

Assumptions are drawn from campaign provenance (candidate dump signature + owner confirmation), **not** from live host inspection (forbidden).

| Layer | Staging assumption | Notes |
|-------|--------------------|-------|
| Hosting | Shared hosting / **cPanel** / CloudLinux-style (`*-cll-lve` signature on candidate dump) | Staging must be a **separate** account or subdomain from production. |
| Web | PHP **8.2+** document root (File Manager / Git deploy / ZIP extract) | Match release manifest runtime: PHP 8.2+. |
| DB | MySQL **8.0.x** via cPanel MySQL / phpMyAdmin | Candidate claimed `MySQL 8.0.46-cll-lve`. |
| DB naming | Prefixed schema (e.g. `account_staging…`) | Must be a **dedicated staging DB** — never the production schema name (candidate prod-like name was `zzflgmfd_excel2`). |
| App config | Host-local `app/config.php` (gitignored) pointing at **staging** DSN only | Confirm DB name/host/user before any migrate. |
| Cache | Writable RuntimeCache dir scoped to staging DB fingerprint | Clear staging `schema.json` only if migrate/cache desync; never touch prod cache. |
| Secrets | Staging `app_key`, DB password, cron/API tokens distinct from prod | `app_key` mismatch vs encrypted credential fingerprint â†’ hard fail (`enforce_data_persistence_guard`). |
| Network | Staging URL (HTTPS) provided by owner | No staging URL exists in evidence today â†’ cannot claim `STAGING_VERIFIED`. |

**Out of scope for this plan:** production cutover, live bank/tax/Sayad, agent SSH/cPanel login.

---

## 2. Release package (immutable SHA)

| Field | Value |
|-------|-------|
| Git ref | `main` @ `2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| Short | `2c1cf65` |
| Schema constant (repo) | `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1` |
| Functional currency | IRR (Toman display opt-in) |
| Package method (owner choice) | `git archive` / tagged ZIP / cPanel Git deploy of **exact** SHA — record SHA after extract |
| Do not deploy from | Unmerged feature branches, older pins (`52fa7f1`, `c5c644c`, etc.), or "whatever is on diskâ€‌ |

**Package build checklist (owner/operator, offline):**

1. `git fetch` + verify `git rev-parse origin/main` == `2c1cf659…`.
2. Export tree at that SHA only (no dirty working tree).
3. Exclude secrets: `.env`, `app/config.php`, dumps, cache artifacts, `__pycache__`.
4. Record package SHA-256 of the archive (optional but recommended).
5. Upload to staging document root **only after** maintenance window starts and DB isolation is confirmed.

**Acceptance context (not deploy authorization):** Independent verdict `RC1_ACCEPTED` is already landed on this SHA via PR #36 merge. That proves disposable rehearsal + CI + local browser classes — **not** staging host verification and **not** production readiness.

---

## 3. Database isolation

| Requirement | Staging rule |
|-------------|--------------|
| Separate schema | Create / use a **staging-only** MySQL database. Never point staging code at production DB. |
| Separate credentials | Staging DB user with rights limited to the staging schema. |
| Data source | Prefer **anonymized** restore of Path2-style dump, or a dedicated staging seed — **not** live production writes. |
| Baseline retention | Keep an **untouched** pre-migrate dump (and optional `*_baseline` clone pattern from rehearsal). |
| Identity verify | Before DROP/restore/migrate: confirm hostname role = staging, DB name, and that credentials are not prod. |
| Cache isolation | RuntimeCache fingerprint must bind to staging DB — avoid shared cache dirs with prod. |

**Known Path2 rehearsal artifact (offline, not in Git; for reference only):**

- Anonymized SHA-256: `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2`
- Provenance id: `prior-cpanel-anon-20261121`
- Unredacted candidate: **UNSAFE** — never deploy/restore unredacted into shared staging without scrub + rotation plan

Disposable local rehearsal used container MySQL; **staging** uses owner cPanel MySQL. Principles transfer; container IDs from runbooks do **not** apply to cPanel.

---

## 4. Migration procedure (operator-controlled offline)

### 4.1 Principles

1. MySQL DDL is often **not** transaction-safe — prefer **dump restore** over `ROLLBACK`.
2. Migrate with an **offline CLI runner** under maintenance — **not** first browser/HTTP hit.
3. Do **not** use `install.php` / incomplete `migrate_v2.php` as the RC1 **upgrade** path (base `Schema::migrate` only — incomplete for RC1).
4. Prefer additive migrations; no automated down-migration shipped.
5. Run migrate **twice** (idempotency) on staging after first successful pass, mirroring disposable rehearsal.

### 4.2 Preferred offline path (staging operator CLI)

**Staging entrypoint (no Docker dependency; shared hosting / cPanel friendly):**

```text
scripts/rc1_staging_schema_migrate.php
```

Shared cumulative steps (no duplicated DDL): `scripts/lib/rc1_cumulative_migrate.php`  
Disposable rehearsal runner (Docker identity gates **unweakened**): `scripts/rc1_legacy_schema_migrate_rehearsal.php`

Required staging env (secrets out-of-band — never commit):

```text
RC1_STAGING_OPERATOR_AUTH=<owner-issued-token>
RC1_STAGING_ENV_IDENTITY=<must-identify-staging>
RC1_STAGING_PREFLIGHT_REVIEWED=1
RC1_STAGING_DB_HOST / PORT / USER / PASS / NAME
RC1_PROD_DB_NAME=<declared-prod-db-for-refuse-gate>
RC1_PRE_MIGRATE_BACKUP_PATH + RC1_PRE_MIGRATE_BACKUP_SHA256
RC1_STAGING_CACHE_DIR=<path-containing-staging>
# Optional: RC1_STAGING_DRY_RUN=1  then apply without dry-run
```

Remote execute still requires **new** written owner auth — see `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md`. Agents must not initiate remote migrate.

### 4.3 Staging migrate sequence (planned)

| Step | Action | Exit criteria |
|------|--------|---------------|
| M0 | Confirm staging DB â‰  prod DB; record names | Written checklist signed by owner/operator |
| M1 | Full mysqldump (or phpMyAdmin export) of staging DB **before** code cut | Dump file + SHA-256 stored offline |
| M2 | Optional: clone/restore into temporary staging schema for dry-run | Dry-run migrateأ—2 PASS or abort |
| M3 | Place code package at SHA `2c1cf65…` on staging (files only; app offline) | Document root SHA verified |
| M4 | Point `app/config.php` at staging DSN; verify `app_key` | Config smoke OK |
| M5 | Run offline cumulative migrate (CLI) once | No fatal errors; table inventory recorded |
| M6 | Run offline migrate second time | Idempotent PASS |
| M7 | Clear **staging** RuntimeCache `schema.json` only if gate/cache mismatch | Staging-only |
| M8 | Spot-check `information_schema` / row counts vs pre-migrate notes | No unexplained loss |
| M9 | Bring staging URL online for smoke (Section 6) | Smoke started |

### 4.4 Failure modes to watch (from preflight)

- `RuntimeCache::schemaReady` skipping migrates after partial failure â†’ clear staging `schema.json` only
- Cache dir wrong fingerprint / non-writable â†’ split schema gate
- `app_key` mismatch vs encrypted credentials â†’ hard fail
- Legacy `workspace_api_tokens` vs RC1 `api_access_tokens` — no silent DROP during expand
- Partial DDL â†’ treat as dirty; restore from pre-migrate dump into clean schema name; do not "fix forwardâ€‌ blindly

---

## 5. Maintenance window

| Item | Plan |
|------|------|
| Scope | **Staging only** — production remains untouched unless owner separately authorizes (out of band; not this plan). |
| Mode | Staging URL returns maintenance / 503 or DNS not cut until M5â€“M6 complete. |
| Suggested length | Owner-set; budget time for dump + migrateأ—2 + smoke + rollback decision (rehearsal suggests migrate is feasible on 110â†’182 table path, but staging size may differ). |
| Freeze | No concurrent schema edits, plugin installs, or phpMyAdmin structural changes during window. |
| Communications | Owner notifies staging consumers only — **not** a production go-live announcement. |
| Abort authority | Owner/DBA can abort to rollback (Section 7) at any failure gate. |

---

## 6. Smoke checklist (staging)

**Goal of smoke:** evidence toward `STAGING_VERIFIED` — **not** automatic grant of production readiness.

Adapt from `T6-BROWSER-NOTES.md` local cases; capture under agreed path (e.g. `docs/ai/rc1/evidence/browser/staging/`) **after** owner authorizes remote work.

**Executable UAT (step-by-step):** [`RC1-STAGING-UAT-PROCEDURES.md`](RC1-STAGING-UAT-PROCEDURES.md) — maps each smoke row to procedure IDs (UAT-AUTH-*, UAT-TENANT-*, etc.), pass criteria, and evidence filenames. Optional Playwright examples: `tests/staging_uat/` (`STAGING_BASE_URL` required; fail-closed if unset or production-like host). **Current browser class remains `LOCAL_BROWSER_VERIFIED` only** — not `STAGING_VERIFIED`.

| # | Case | Pass criteria | UAT procedure(s) |
|---|------|---------------|------------------|
| S1 | Health / login page | Staging URL loads; login form RTL | UAT-AUTH-001 |
| S2 | Login / logout | Auth session works; logout â†’ login | UAT-AUTH-002, UAT-AUTH-003 |
| S3 | Company select / isolation | Company filter isolates data | UAT-TENANT-001, UAT-TENANT-002 |
| S4 | Trading cockpit | Loads; RTL; no 500 | UAT-P2P-001, UAT-UX-001 |
| S5 | Manufacturing cockpit | Loads; expect WIP/FG **BLOCKED** / valuation banners — **do not** expect auto GL FG | UAT-MF-001 |
| S6 | Service / retail profiles | Loads; IRR / Toman display behavior coherent | UAT-SVC-001, UAT-RTL-RETAIL-001, UAT-UX-003 |
| S7 | Jalali date | Header/calendar Jalali coherent | UAT-UX-002 |
| S8 | Limited role | Viewer cannot perform forbidden writes | UAT-TENANT-003 |
| S9 | CSRF | Bad/missing token â†’ HTTP 419 | UAT-SEC-001, UAT-SEC-002 |
| S10 | Empty / error states | Empty pipelines without fatal errors | UAT-EMPTY-001 |
| S11 | Post-migrate schema gate | App does not re-run destructive migrate on first hit; cache consistent | UAT-FIN-003 |
| S12 | Financial spot check (if seeded) | Sample voucher Dr=Cr; stock qty not silently altered | UAT-FIN-001, UAT-FIN-002 |

**Explicit non-goals for smoke:** claiming Iranian regulatory compliance; posting live bank/tax/Sayad; proving WIP/FG valuation complete.

---

## 7. Rollback (staging)

Principles from `RC1-ROLLBACK-RUNBOOK.md`, adapted to staging (owner/DBA only):

| Scenario | Action |
|----------|--------|
| Failed migrate on staging | Stop traffic; restore staging DB from **pre-migrate dump** (prefer new schema restore if partial DDL). |
| Code mismatch | Redeploy previous known-good staging code tree (prior SHA) + matching DB dump. |
| Cache desync | Clear **staging** RuntimeCache fingerprint only; re-run offline migrate after restore if needed. |
| Interrupted DDL | Assume dirty â†’ restore dump; do not invent forward-fix without inventory. |

**Not shipped:** automated down-migration.  
**Not authorized by this doc:** production restore commands or agent-executed cPanel restores.

---

## 8. Operator runbook (ordered)

### Pre-flight (before remote staging auth)

1. Confirm release SHA `2c1cf659…` on package.
2. Confirm staging host/URL/DB names in writing (owner).
3. Confirm staging DB â‰  production DB.
4. Confirm secrets store: staging DB pass, `app_key`, no prod reuse.
5. Confirm pre-migrate dump plan + offline storage.
6. Confirm migrate executor (who runs PHP CLI / approved equivalent).
7. Confirm maintenance window + abort owner.
8. Re-read MF residual: `UNVALUED` / `GL_BLOCKED` expected.

### Execute (only after owner remote auth — **not granted yet**)

1. Enable maintenance on staging.
2. Dump staging DB; record SHA-256.
3. Deploy code package `2c1cf65…`.
4. Verify config DSN â†’ staging only.
5. Offline migrate أ—1; record output.
6. Offline migrate أ—2; confirm idempotent.
7. Optional recon queries (counts / Dr=Cr / stock).
8. Disable maintenance; run smoke S1â€“S12.
9. File evidence; owner decides `STAGING_VERIFIED` or rollback.

### Post

1. Retain pre-migrate dump until next agreed retention.
2. Do **not** promote labels to production without separate owner go-live auth.
3. Rotate any secrets that appeared in unredacted exports historically (owner).

---

## 9. Go / No-Go criteria (label dictionary)

| Label | Meaning | Current status (2026-11-21) | Required to claim |
|-------|---------|-----------------------------|-------------------|
| `RC1_ACCEPTED` | Independent engineering acceptance on SHA (contracts + disposable OPS + CI + local browser) | **YES** on `2c1cf65…` | Already met on main tip |
| `STAGING_READY` | Immutable SHA + green post-merge CI + Path2/migrate evidence + staging plan packaged; **eligible** to deploy when owner authorizes | **YES (engineering)** on `2c1cf65…` — remote execute still needs آ§10 owner auth | Remote auth is required to *execute*, not to claim engineering READY |
| `STAGING_VERIFIED` | Staging host deploy + offline migrate + smoke evidence recorded | **NO** | Staging URL + smoke S1â€“S12 evidence + migrate proof on staging DB |
| `PRODUCTION_READY` / go-live | Explicit owner production authorization + separate prod runbook | **NOT CLAIMED** / **NOT_DEPLOYED** | Out of scope of this plan |
| `SCHEMA_UPGRADE_TESTED` | Proven on **disposable** Path2 anonymized data | **YES** (disposable only) | Does **not** equal staging/prod verified |
| `LOCAL_BROWSER_VERIFIED` | Local disposable UAT | **YES** | Does **not** equal `STAGING_VERIFIED` |
| `FRESH_INSTALL` | No prior install | **INVALID** for this campaign | Do not invent |

### Go / No-Go summary

| Decision | When |
|----------|------|
| **GO for planning / packaging** | SHA verified; `RC1_ACCEPTED`; Path2 disposable rehearsal PASS; docs plan exist. |
| **NO-GO for remote staging execute** | Until owner grants remote auth + staging URL/DB isolation + dump/migrate operator named (آ§10). |
| **GO for `STAGING_VERIFIED` claim** | Only after successful staging migrateأ—2 + smoke evidence. |
| **NO-GO for production language** | Always under this document — production requires separate explicit owner authorization. |

---

## 10. Owner actions required before remote staging can proceed

These are **blocking** for remote *execute* and for `STAGING_VERIFIED`. Engineering may still be labeled `STAGING_READY`. Agents must not perform remote steps without auth.

1. **Written remote staging authorization** (scope: staging host only; exclude production).
2. **Provide staging URL** (HTTPS) and confirm it is non-production.
3. **Provision / identify staging MySQL database** separate from production; share **non-secret** identifiers (host role, DB name) + secret out-of-band.
4. **Confirm document root / deploy method** (cPanel Git, ZIP, rsync) and that target will be pinned to `2c1cf659…`.
5. **Name the operator** who will run offline migrate (SSH PHP CLI or approved equivalent) — not first HTTP hit.
6. **Approve maintenance window** (start/end, abort owner).
7. **Confirm data policy for staging DB:** anonymized restore vs synthetic seed vs scrubbed copy; no unredacted prod dump in Git.
8. **Confirm secret rotation** if unredacted candidate dump was previously shared (`LEGACY-SCHEMA-EXPORT-INSTRUCTIONS.md`).
9. **Confirm MF expectation** in writing: WIP/FG may show `UNVALUED` / `GL_BLOCKED` — not a staging defect.
10. **Explicitly withhold production go-live** unless a separate production authorization is issued later.

Until items 1â€“6 are done, status remains: **`RC1_ACCEPTED` + plan exists; remote staging `NOT_AUTHORIZED` / `NOT_RUN`.**

---

## 11. T0 doc hygiene — obsolete SHA / "PR #36 pendingâ€‌ references

**Verified truth:** `origin/main` / local `HEAD` = `2c1cf6592f246553c66c3bc90d00385bf65d4c29` (PR **#36 MERGED**).

The following acceptance/manifest docs still speak as if PR #36 is pending or pin older tips. **Lane D does not edit those shared acceptance files (T0 owns).** Flag for T0 update:

| Obsolete / stale pin | Example locations | Issue |
|----------------------|-------------------|-------|
| "Merge PR #36â€‌ / "candidateâ€‌ / "pendingâ€‌ | `RC1-RELEASE-MANIFEST.md`, `RC1-BLOCKERS-AND-REMEDIATION.md`, `RC1-ACCEPTANCE-REPORT.md`, `RC1-INDEPENDENT-VERIFICATION.md`, `RC1-EXECUTIVE-HANDOFF-FA.md`, `RC1-P1-CLOSURE-STATUS-BOARD.md` | PR #36 **already merged** into `2c1cf65…` |
| `2a30f5abac6291af2e6d3ccf1f87446335c84bad` | Manifest, Acceptance, Evidence Index, IV, P1 board | Pre-closure baseline — historical OK if labeled; must not be "final mainâ€‌ |
| `52fa7f12f465653d6e445d484f1a8dda48215f45` | Manifest "Final origin/mainâ€‌, PRE-FLIGHT pin, T6 notes, Acceptance | PR #32-era tip — **superseded** by later merges including #36 |
| `c5c644c386cbedde788c4e12e0585c43dc1b5a5d` | Provenance, Blockers, Acceptance, Executive FA | PR #35 tip — **superseded** by `2c1cf65…` |
| `03728b145e67f0f6fe2179fe6a7e1df0badad8e5` | Blockers, CI note, Acceptance (GHA candidate) | Pre-merge PR #36 commit — GHA evidence still valid historically; tip is now merge `2c1cf65…` |
| `e474ff377731ad2d482b2bf4fec6c3d1a7d32c5c` | `RC1-MIGRATION-PREFLIGHT.md` `origin/main` field | Stale vs current main |

**Recommended T0 one-liner:** Retitle pins to "historical / pre-mergeâ€‌ and set **Release / verified main SHA = `2c1cf6592f246553c66c3bc90d00385bf65d4c29`**. Keep GHA run links; note they were on ancestor `03728b1…` of the merge commit.

---

## 12. Residual product truths (do not weaken on staging)

- TR-1 / TR-2 / MF-1 lifecycle: accepted on disposable evidence (`PASS_REPRODUCED`).
- MF WIP/FG valuation: **not fully supported** â†’ `UNVALUED`.
- MF GL: **`GL_BLOCKED`** — no unsupported automatic WIP/FG GL.
- SYS-1: `LOCAL_BROWSER_VERIFIED` until staging smoke evidence exists.
- OPS-1: `PASS_PATH2_DISPOSABLE_REHEARSAL` / `SCHEMA_UPGRADE_TESTED` (disposable) — staging migrate still to be proven on staging host.
- Production: **`NOT_DEPLOYED`**.
- Compliance: no fabricated Iranian regulatory certifications in staging reports.

---

## 13. Document control

| Field | Value |
|-------|-------|
| Path | `docs/ai/rc1/RC1-STAGING-DEPLOYMENT-PLAN.md` |
| Authority | Plan / readiness — **not** execute authorization |
| Remote systems mutated | **None** |
| Shared acceptance files edited | **None** (T0 owns) |
