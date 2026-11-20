# T4 Regulatory Evidence Matrix (RC1)

**Version:** `rc1-t4-reg-1`  
**Date:** 2026-11-20  
**Rule:** No invented VAT rates, deadlines, or live integrations. Legal integrations remain **OFF**.  
**Status labels:** `UNVERIFIED` | `SOURCE_RECORDED` | `DATA_MODEL_ONLY` | `OFF`

| Topic | Status | Issuer / source | Official URL | Publication / effective | Implementation requirement | Uncertainty |
|---|---|---|---|---|---|---|
| VAT configuration | UNVERIFIED | — | — | — | Keep rates configurable; **no default statutory rate** shipped | No authoritative rate/version captured for RC1 |
| Electronic invoicing / سامانه مودیان | UNVERIFIED | سازمان امور مالیاتی (claimed historically) | — | — | Metadata fields only (`taxpayer_status`); **no submission** | Protocol, credentials, and schema not verified in-repo |
| Cheque / صیاد (Sayad) | DATA_MODEL_ONLY / OFF | بانک مرکزی / سامانه صیاد (unverified here) | — | — | `acc_checks` data model only; **no bank/Sayad API** | External API contract not verified |
| Payroll / insurance / tax withholding | UNVERIFIED | — | — | — | Extended HR/payroll schema may exist; **not PRODUCTION statutory** | Contribution tables and filing calendars unverified |
| Statutory accounting reports | UNVERIFIED | — | — | — | No RC1 claim of legal report formats | Format/version unknown |
| Customs / FX statutory posting | UNVERIFIED | — | — | — | Out of RC1 live scope; trade landed-cost remains operational/reporting | Legal mapping not verified |
| Bank reconciliation vs payment posting | OFF (policy) | — | — | — | Explicitly separate from AR receipt / AP payment posting | N/A — product policy, not a legal claim |

## Non-claims (explicit)
- No live tax, bank, or Sayad communication in RC1.
- No hardcoded VAT percentages or filing deadlines.
- Marking a row `UNVERIFIED` is intentional honesty, not a blocker for bounded RC1 trading/manufacturing pilots.
- Promoting any row to `SOURCE_RECORDED` requires: official URL, issuer, publication date, effective date, topic, and rule version recorded in this matrix before enablement.

## Supersedes / related
- Baseline gap notes: `docs/ai/53-REGULATORY-GAP-MATRIX-1021.md`
- Architecture: `docs/ai/51-IRAN-FIRST-ARCHITECTURE-1021.md`
