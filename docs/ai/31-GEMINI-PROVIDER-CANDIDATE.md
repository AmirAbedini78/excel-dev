# ERPSMART Gemini Gateway — Rebased on v10.10.3 local source

This patch targets the **exact source ZIP** supplied on 2026-10-26, not a fresh clone.
No API key or worker token is bundled. The previously pasted Gemini key must be
revoked and replaced with a new one before making an API request.

- ERP PHP stays on cPanel. Existing Ollama Worker remains untouched until you opt into Gemini.
- Admin chooses workspace default provider/model in AI Settings; user may select provider/model in Copilot.
- Server validates selection, emits an `engine_selection` context field and routes Gemini jobs by
  `required_capability=llm.gemini`; the Worker token and node must both allow that capability.
- The Gateway selects the requested cloud model from the worker allowlist and remains inside the
  existing authenticated ERP Tool / Proposal / Approval boundaries.
- Local `cloud_only` Worker doesn't probe Ollama. The default Docker Compose isn't changed.
- Mixed workers must have a *new* token permitting `llm.gemini`; existing tokens retain previous permissions.

## Installation (on the existing Windows project)

Extract ZIP and execute from the extracted folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Repo 'C:\Projects\excel-dev'
```

Installer checks the SHA-256 of every existing target *including CRLF-normalized comparison*,
backups files outside repo, applies the files, checks `git diff --check`, and reports backup path.
It does NOT restart Docker, alter secrets, modify DB schema, commit, push or deploy cPanel.
Use `.\rollback.ps1 -BackupDir <printed backup path> -Repo <repo>` for rollback.

The Gemini Worker uses its own Docker volume (`gemini_state`), so its Kernel route/cache cannot collide with the existing Ollama worker data.

Existing Windows Docker: leave existing Ollama containers running and first complete an offline
syntax/test check. To run Gemini as separate Worker, obtain a fresh worker token with Gemini
capability from ERP (after publishing the web changes), set it in
`engine/config.gemini.json`, set new Gemini API key only in `engine/.env.gemini`, and run
`docker compose --env-file .env.gemini -f compose.gemini.yaml up -d --build` from engine/.
The web changes require cPanel deployment and a *new* Gemini token; the existing Docker Worker
need not be redeployed until you configure and enable Gemini.

API URL and model names must match the real Google project/key. Run
`docker compose --env-file .env.gemini -f compose.gemini.yaml run --rm worker python gemini_check.py`
to enumerate available models before selecting one. Tests will incur quota/cost.

## Deployment gates

1. php lint and JS syntax; unit tests including `test_gemini_provider.py`.
2. Verify default Ollama capability unchanged, AI Admin setting hidden to non-admin users,
   company/workspace-scoped selection, model whitelist and no secrets in UI/job/trace.
3. Publish PHP+JS to staging cPanel; create NEW worker token with `llm.gemini`.
4. Start Gemini-only worker with **different node_uid**, verify register, availability, tool
   read path and selected model metadata; test one supplier analysis with real ERP evidence.
5. Verify no writes except through approved Proposal flow; test error when Gemini Worker offline.
6. Only then promote to production, record timestamps/results and rerun tests for Ollama.

AI Engine `MODEL=PASS`, `FILE_INSTALL=PASS` and `ERP_LIVE=PASS` are independent gates.
Gemini API and cPanel production cannot be verified by this offline patch.
