# Legacy Schema Export Instructions (Owner / DBA)

**Purpose:** Produce a **verified, anonymized** Path2 artifact suitable for disposable RC1 upgrade rehearsal.  
**Forbidden:** Agents connecting to production/cPanel; committing unredacted dumps to Git.

## Why the current candidate is insufficient alone

Owner path `zzflgmfd_excel2.sql` is a useful **candidate** (SHA-256 recorded in provenance docs) but classified **`UNSAFE_UNREDACTED_SECRETS_AND_PII`**. It must stay offline. Schema-only sanitization can prove **structure**, not business-data preservation.

## Owner evidence checklist

1. Written authorization to export from the **prior/staging** host (not a live mutate).
2. Source identity: hostname role, MySQL version, schema/DB name, approximate dump time, code SHA if known.
3. Artifact kind: prefer **schema + anonymized data** (or synthetic rows preserving amounts/IDs structure).
4. Published SHA-256 of the final anonymized file.
5. Provenance env id, e.g. `prior-cpanel-anon-20261121`, for `RC1_PROD_SCHEMA_PROVENANCE=<id>`.
6. Confirmation that secrets were rotated if the unredacted dump was shared (cron secret, API tokens, passwords).

## Secure extraction (cPanel / phpMyAdmin / mysqldump)

### Option A — mysqldump (preferred)

```bash
# On the legacy host, as DBA — example only; owner executes
mysqldump --single-transaction --routines=false --triggers=false \
  --default-character-set=utf8mb4 \
  YOUR_DB_NAME > erpsmart_legacy_YYYYMMDD.sql
sha256sum erpsmart_legacy_YYYYMMDD.sql
```

### Option B — phpMyAdmin

Export → SQL → structure + data → utf8mb4. Record generation time and server version from header.

## Anonymization / sanitization (before agent use)

Scrub or replace at minimum:

| Area | Action |
|------|--------|
| `users.password_hash` | Replace with disposable bcrypt for test users only |
| `settings.cron_secret` / API keys | Remove or replace with random disposable values |
| `workspace_api_tokens` / `ai_worker_tokens` | Truncate or synthetic |
| `portal_credentials.password_enc` | NULL / synthetic |
| Emails, phones, national IDs, addresses | Synthetic Iranian-format fakes |
| Company legal names (if sensitive) | Pseudonyms preserving company_id graph |
| Bank / tax tokens | Remove |

**Keep intact for reconciliation:** `company_id` / `workspace_id` graphs, posted voucher debit/credit, stock movement quantities/costs, document links, IRR amounts (no silent ÷10 Toman reinterpretation).

## Delivery

1. Place anonymized file **outside Git** (e.g. Downloads or secure drop).
2. Tell T0: path + SHA-256 + provenance id.
3. T0 restores **only** into verified disposable MySQL (`erpsmart-p2p-mysql-test` / known container id + `@@server_uuid`).
4. Commit **redacted metadata + evidence JSON only**.

## Reject conditions

- Live secrets / unredacted PII in anything destined for Git  
- Dump claimed as prod without anonymization approval  
- Using git `main` / `RuntimeCache::SCHEMA_VERSION` as substitute provenance  
- Asking agents to SSH/cPanel into production
