# Defect RC1-P0-002 — Manufacturing valuation resolver missing

| Field | Value |
|-------|-------|
| ID | RC1-P0-002 |
| Severity | P0 |
| Contract | MF-1 |
| Owner | **T5** |
| Status | RESOLVED |
| Baseline | G0 `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| Reporter | T7 |
| Date | 2026-11-20 |

## Summary
No `CostingAccounting*` / valuation resolver. Shared contract requires classes `POSTED_ACTUAL | PROVISIONAL | UNVALUED | BLOCKED`. RC1 default WIP/FG GL = **BLOCKED** unless T5 proves safe mapping. Missing resolver means MF-1 cannot prove source-backed cost rollup or honest UNVALUED/BLOCKED labeling.

## Reproduction
```bash
# No CostingAccounting*.php under app/Core/
docker run --rm -v "%CD%:/app" -w /app erpsmart-o2c-php:8.2 php tests/harness/rc1_capability_probe.php mf1
```

## Expected
Verified cost rollup citing movement/invoice/voucher IDs; never label estimates as `actual`.

## Actual
Resolver absent → MF-1 **BLOCKED**.

## Minimal fix path
T5 delivers valuation decision record + resolver; coordinates GL with T0 exclusive lock if posting. T7 retests.
