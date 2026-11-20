# ERPSMART RC1 — Consolidated Handoff

**Verdict:** `RC1_ACCEPTED`
**Release branch:** `release/erpsmart-rc1`
**Final SHA:** f0293553f0e6d2b0f1e3ebdda8ff176a5a13ad99
**Date:** 2026-11-20
**Deploy:** `NOT_DEPLOYED`

## Go / No-Go

| Measure | Result |
|---------|--------|
| Acceptance contracts | **5/5 PASS** |
| P0/P1 open | **0** |
| DATABASE_TESTED | **true** |
| Product deploy | **NOT_DEPLOYED** |

## Gate

`python scripts/rc1_acceptance_gate.py --strict-pass` with `RC1_REQUIRED=1` and `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`.

Evidence: `docs/ai/rc1/evidence/rc1_gate_report.json` (+ per-contract `*_acceptance.json`).

Sandbox: `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port `3307` / PHP `erpsmart-o2c-php:8.2`.

## Merged lane PRs

| Lane | PR |
|------|-----|
| T2 | https://github.com/AmirAbedini78/excel-dev/pull/21 |
| T5 | https://github.com/AmirAbedini78/excel-dev/pull/22 |
| T1 | https://github.com/AmirAbedini78/excel-dev/pull/23 |
| T4 | https://github.com/AmirAbedini78/excel-dev/pull/19 |
| T3 | https://github.com/AmirAbedini78/excel-dev/pull/24 |
| T6 | https://github.com/AmirAbedini78/excel-dev/pull/20 |
| T7 | https://github.com/AmirAbedini78/excel-dev/pull/25 |

Duplicate follow-ups #26/#27/#28 superseded (core already on release). Lock-hardening from #28 deferred as post-RC stabilize (not required for 5/5).

## Non-claims

WIP/FG GL BLOCKED; no taxpayer/Sayad/bank; no cPanel/prod deploy; commercial MVP source gate ≠ RC1 E2E.

## Sign-off

| Role | Decision |
|------|----------|
| T0 | **RC1_ACCEPTED** |
| User deploy | **NOT REQUESTED / NOT_DEPLOYED** |
