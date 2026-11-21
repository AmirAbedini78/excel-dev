# Legacy → RC1 Schema Diff (preparation + structural evidence)

**Date:** 2026-11-21  
**Legacy source:** sanitized schema-only from candidate `zzflgmfd_excel2` (110 tables)  
**RC1 target:** `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1`  
**Label:** preparation-only for **data** claims; structural migrate evidence attached  

## Compatibility summary

| Class | Meaning | Examples |
|-------|---------|----------|
| **SAFE_ADDITIVE** | New tables/columns via `CREATE IF NOT EXISTS` / guarded `ALTER` | `erp_ar_postings`, `erp_ap_*`, `erp_mf_*`, `erp_p2p_*`, `erp_o2c_*`, `erp_company_business_profiles` |
| **REQUIRES_DATA_BACKFILL** | New NOT NULL / maps without legacy rows | AR/AP company maps, business profiles, costing policy |
| **UNIT_SENSITIVE** | Money/qty semantics must not silently change | `acc_voucher_lines.debit/credit`, `acc_stock_movements.quantity/unit_cost`, trade `amount_irr` / `currency_code` default IRR |
| **PRESERVE_IDENTITY** | Do not renumber | voucher ids, stock movement ids, company/workspace ids |
| **UNKNOWN** | Needs column-level compare on verified dump | charset edge cases, NULL defaults, missing indexes on legacy phpMyAdmin form |

## Legacy markers vs RC1

| Marker | Legacy candidate | RC1 repo |
|--------|------------------|----------|
| `settings.schema_version` | `3.1.0` | App gate `10.24.0-rc1` (RuntimeCache) |
| `saas_schema_version` | `5.0.0` | Tenant/SaaS tables already present in dump |
| `accounting_extended_schema_version` | `7.0.0` | `AccountingExtendedSchema` migrates forward |
| `ai_schema_version` | `1.1.0` | `AiSchema::migrate` additive |

## Tables present in legacy (sample critical)

Present: `acc_accounts`, `acc_vouchers`, `acc_voucher_lines`, `acc_stock_movements`, `acc_items`, `companies`, `workspaces`, `users`, `settings`, CRM/AI/workflow base tables (110 total).

## RC1 tables missing in legacy (additive — observed after migrate)

Missing before migrate; **present after** disposable structural migrate:

- `erp_ar_postings`, `erp_ar_receipts`, `erp_ar_allocations`
- `erp_ap_postings`, `erp_ap_payments`, `erp_ap_allocations`
- `erp_p2p_vendor_bills` (+ lines/policy)
- `erp_o2c_invoice_links` (+ idempotency/locks)
- `erp_mf_production_orders`, issues, FG, QC, scrap
- `erp_wms_stock_events`, `erp_company_business_profiles`, `erp_costing_company_policy`, `erp_ext_events`

Structural result: **110 → 182** tables on migrate clone (`ops1_legacy_schema_structural_rehearsal.json`).

## IRR / Toman / isolation risks

- Code defaults currency to **IRR** (`AccountingPostingDomain::CURRENCY`, trade costs `amount_irr`).  
- **Forbidden:** silent ÷10 / ×10 reinterpretation of stored amounts.  
- Preserve `workspace_id` + `company_id` on all financial/stock rows.  
- Posted vouchers: keep `status`, `total_debit`/`total_credit`, line debit/credit unchanged during expand phase.

## Ordered migration phases (expand → backfill → validate → enforce)

1. **Expand:** run offline cumulative migrates (bootstrap order) — idempotent `IF NOT EXISTS`.  
2. **Backfill:** maps/policies only where safe defaults exist; no rewrite of posted history.  
3. **Validate:** row counts, Dr=Cr per voucher, stock movement identity, FK checks (needs **data** dump).  
4. **Enforce:** stricter NOT NULL / FK only after validate PASS.

## DDL not fully TX-rollback-safe (MySQL)

Many `CREATE TABLE` / `ALTER TABLE` / index builds **commit implicitly** and cannot be rolled back by `ROLLBACK`. Mitigation: untouched baseline DB + dump restore to a new schema (`erpsmart_rc1_legacy_schema_rollback` drill PASS).

## Implementation gate

New production migrations/tests against **unredacted** dump: **NOT ALLOWED**.  
Structural offline runner added: `scripts/rc1_legacy_schema_migrate_rehearsal.php` (disposable only).
