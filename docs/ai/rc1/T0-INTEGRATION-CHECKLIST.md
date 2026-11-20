# T0 Integration Checklist (RC1)

**Release branch:** `release/erpsmart-rc1`  
**G0 freeze commit:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`

## Merge order
1. T2 stock API (foundational)
2. T5 valuation/BLOCKED policy
3. T1 manufacturing (consumes T2/T5)
4. T3 trading cockpit
5. T4 localization
6. T6 UX
7. T7 QA gates + acceptance report refresh

## Pre-merge checks (each lane)
- [ ] PR targets `release/erpsmart-rc1` only
- [ ] Ownership respected (no cross-lane file theft)
- [ ] Lane tests green OR defects filed with severity
- [ ] Handoff doc present under `docs/ai/rc1/TN-HANDOFF.md`
- [ ] No weakened preexisting tests
- [ ] No production deploy claims

## Post-merge combined
- [ ] ModuleRegistry/bootstrap wiring applied by T0 if lanes filed requests
- [ ] Combined E2E TR-1/TR-2/MF-1/SYS-1/OPS-1
- [ ] Status board updated
- [ ] Final PR to main only at G5

## Lane agent IDs (G1)
| Lane | Agent |
|------|-------|
| T2 | fdd69709-2cc5-486f-b43c-84ea726bbba0 |
| T5 | de4db89f-baaf-42ba-b52f-c90118717566 |
| T1 | e7c49b02-987f-43c7-a796-1a39401c033b |
| T3 | a4addd94-97ce-4f59-9433-cf0facae66d8 |
| T4 | bd8aaefa-fe17-4700-8763-d1f9ee7d6818 |
| T6 | 14a89cb8-e10e-4f3f-a6e7-1d9f1dec91cc |
| T7 | 6aeabd99-3df6-4ffd-a1b2-57c337fa0947 |
