# ERPSMART HANDOFF — v10.17.0 P2P Hardening

## Environment
- system-2 / `C:\Projects\excel-dev`
- Branch: `feature/erp-p2p-hardening-v10.17.0`
- Base main (post PR #5): `c1db53244599763e8c2a07dd33b8a90e061a09f1`
- Disposable MySQL: docker `erpsmart-p2p-mysql-test` on port 3307 (not production)

## Release status
- v10.16: **MERGED** (PR #5 → `c1db532`); post-merge CI success `44703211785`
- v10.17: implemented on feature branch; see PR after push
- Deploy: **NOT_DEPLOYED**

## Implemented
- Fail-Closed company policy for goods invoices when module `p2p` enabled
- Guards on accounting UI + AI purchase draft
- Rejected bill amend + revision history
- Exception audit + permission
- MySQL sandbox concurrency / partial billing / isolation tests
- Docs: `38-P2P-HARDENING-1017.md`, updated capability matrix

## Explicit gaps
- GL/AP posting still disabled (draft only)
- Staging cPanel not verified
- Production untouched
