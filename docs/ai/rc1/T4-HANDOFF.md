# T4 Handoff — Iran-first Localization (RC1)

**NOT_DEPLOYED · No legal compliance claims · Legal integrations OFF**

## Baseline
| Item | Value |
|---|---|
| G0 SHA | `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| Branch | `feature/rc1-t4-iran-first` |
| Worktree | `C:\Projects\erp-worktrees\rc1-t4` |
| Integration target | `release/erpsmart-rc1` |
| Author | AmirHosein `<abedinnezhad1999@gmail.com>` |
| Feature commit | `5d7194ad6740375c68b1133c9fa39bbb7af13fa4` |
| PR | https://github.com/AmirAbedini78/excel-dev/pull/19 |

## Delivered
### Localization APIs
| Class | Version | Role |
|---|---|---|
| `IranLocalizationDate` | `rc1-t4-date-1` | Jalali↔Gregorian round-trip; store `Y-m-d` Gregorian; explicit date roles |
| `IranLocalizationMoney` | `rc1-t4-money-1` | Explicit IRR/Toman display; ledger stays IRR; `1 Toman = 10 IRR` |
| `IranLocalizationDocument` | `rc1-t4-doc-1` | RTL print helpers; LTR/`<bdi>` numerics; invoice/stock templates |
| `BusinessProfileDomain` | extended | Delegates money helpers; SYS-1 doc requirements |

**Key methods**
- `IranLocalizationDate::jalaliToGregorianDate` / `gregorianDateToJalali` / `roundTripJalali` / `datedField`
- `IranLocalizationMoney::displayToIrrScaled` / `irrScaledToDisplay` / `formatLabeled` / `dualCompanyDisplay` / `companyDisplayContext`
- `IranLocalizationDocument::renderPrintableDocument` / `numericCell` / `composeDocumentNumber`
- `BusinessProfileDomain::requiresManufacturingDocs` / `assessDocumentRequirements` / `localizationApis`

Templates: `app/Core/IranLocalizationTemplates/{invoice_print,stock_print}.php`

### Regulatory matrix
`docs/ai/rc1/T4-REGULATORY-EVIDENCE-MATRIX.md` (`rc1-t4-reg-1`) — VAT, مودیان, صیاد, payroll, statutory reports, customs marked **UNVERIFIED** or **OFF**; no invented rates.

### SYS-1
Service and retail profiles set `requires_manufacturing_docs => false` and do not list BOM/production doc families as required. Manufacturing remains the only profile that gates on manufacturing docs.

### Jalali hardening
`Jalali::fromGregorian` prefers pure `Y-m-d` parsing (no timezone shift) before `strtotime` fallback.

## Files touched
- `app/Core/BusinessProfileDomain.php`
- `app/Core/Jalali.php`
- `app/Core/IranLocalizationDate.php` (new)
- `app/Core/IranLocalizationMoney.php` (new)
- `app/Core/IranLocalizationDocument.php` (new)
- `app/Core/IranLocalizationTemplates/*` (new)
- `tests/php_iran_localization_rc1_test.php` (new)
- `tests/test_iran_localization_rc1.py` (new)
- `docs/ai/rc1/T4-REGULATORY-EVIDENCE-MATRIX.md` (new)
- `docs/ai/rc1/T4-HANDOFF.md` (this file)
- `docs/ai/rc1/prompt-pack/**` (present in worktree; orchestrator pack — include if untracked)

## Migrations
None. No schema version bump (T0-owned). Profile table already exists from 10.21.

## Tests
| Suite | Command | Result |
|---|---|---|
| PHP unit | `docker run --rm -v <repo>:/app -w /app erpsmart-o2c-php:8.2 php tests/php_iran_localization_rc1_test.php` | **PASS** (`IRAN_LOCALIZATION_PHP=PASS`; leap/boundary/invalid dates; IRR/Toman; RTL doc; SYS-1) |
| Python contracts | `python -m unittest tests.test_iran_localization_rc1 tests.test_iran_first_contracts_1021` | **PASS** (12 tests) |
| DATABASE_TESTED | — | **none** (localization helpers are pure PHP; no disposable MySQL mutations) |

Container identity (available, unused for T4 mutations): `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / DB `erpsmart_p2p_test` per RC1 contracts.

## Non-claims
- No live tax / bank / Sayad communication
- No hardcoded VAT rates or filing deadlines
- No production or staging deploy
- No GL silent IRR↔Toman conversion
- Prompt-pack docs alone do not constitute product compliance

## Integration requests (T0)
1. Optional: `require_once` IranLocalization* in `app/bootstrap.php` (currently loaded via `BusinessProfileDomain.php`).
2. Optional: wire printable templates into Sales/Purchase/Inventory UI pages (T3/T6 coordination).
3. Merge order: after T2/T5 foundations per G2; T4 has no stock/GL kernel dependency.

## Open issues
| ID | Severity | Note | Owner |
|---|---|---|---|
| T4-P2-01 | P2 | Browser DOM screenshot smoke not captured in this lane (PHP HTML assertions only) | T6/T7 |
| T4-P2-02 | P2 | Official regulatory URLs still UNVERIFIED — enablement blocked until sources recorded | Legal/T0 post-RC1 |

## Next integrator action
Merge PR into `release/erpsmart-rc1` after lane CI; do not enable legal integrations.
