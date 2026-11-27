# Domain gaps — BLOCKED until owner decisions

These capabilities are intentionally **not faked**. Product work continues around them via typed Domain Services only.

| Gap | Status | Why blocked | Safe interim |
|---|---|---|---|
| Bank reconciliation | **BLOCKED** | Matching rules, multi-currency FX policy, and statement import format need domain decisions | Read tools for cash accounts / checks remain; no auto-match mutation |
| Payroll / statutory | **BLOCKED** | Legal/tax jurisdiction + payslip posting map owned by customer counsel | HR leave/attendance Suite entities only; no payslip/GL |
| MF costing → WIP/FG GL | **BLOCKED** | Cost class may be UNVALUED; GL_BLOCKED by design until valuation policy | MF read tools expose UNVALUED + GL_BLOCKED honestly |

## Non-negotiables (still enforced)

- Financial ops only via typed Domain Services / AiToolRegistry proposals
- No LLM SQL
- No physical delete of financial documents
- No permission bypass
- Master data archive = soft `active=0` only

## Related

- Staging CI/CD: `19-STAGING-AUTO-DEPLOY-EXCEL2.md`
- Migration inventory: `20-STAGING-MIGRATION-INVENTORY.md`
