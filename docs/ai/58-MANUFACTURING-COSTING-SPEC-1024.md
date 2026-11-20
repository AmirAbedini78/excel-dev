# v10.24 — Manufacturing Costing Spec (prep)

**Status:** SPEC_ONLY · **NOT_IMPLEMENTED** · Depends on merged v10.23  
**Evidence class:** none yet (no code)

## Goal

Pilot path: BOM → MRP-lite → PO → material issue → ops → QC → FG receipt → costing — using the **same** inventory + AP kernels (no parallel ledger).

## Non-goals (explicit)

- No invented “actual costing” without verifiable stock + voucher evidence.
- No automatic GL from unfinished production orders.
- No taxpayer / Sayad / VAT rate invention.
- No production deploy.

## Proposed phases (post-10.23)

1. **Issue & WIP evidence** — stock movements for component issue with company/warehouse scope; qty fail-closed against BOM + on_hand.
2. **FG receipt** — finished-goods stock in; link to production order; still **no** auto GL until costing policy mapped.
3. **Cost rollup (bounded)** — sum verified material issues (+ optional labor/overhead only if entered as controlled journals); unit cost = total / FG qty with scaled arithmetic.
4. **GL valuation (optional gate)** — only when company map + open period + maker/checker; otherwise remain stock-operational like current GR≠GL model.
5. **MRP-lite** — demand from open SO/PO; suggest replenishment; does not auto-create POs without approval.

## Acceptance sketch

- Issue cannot exceed available; scrap recorded separately.
- FG qty cannot exceed order without amendment.
- Costing report cites source movement ids; re-run idempotent.
- Manufacturing profile reuses AP/P2P for purchased components.

## Blockers until 10.24 coding starts

- v10.23 merged + CI green.
- Decision: keep invoice-based inventory GL vs introduce controlled WIP/FG valuation accounts.
