# 06 — RAG Architecture and ACL (Wave2)

**Owner:** T2 (Enterprise RAG)  
**Status:** READY_FOR_T0_REVIEW (schema + ACL surfaces)  
**Baseline:** extends `engine/rag.py` SQLite FTS5 — **no Qdrant/Elasticsearch** unless a measured necessity is documented and T0-approved.  
**Contract dependency:** `04-AGENT-RUNTIME-CONTRACT.md` evidence kinds.

---

## 1. Separation of authority

| Kind | Source | May drive tools? | May claim balances / live ERP state? |
|------|--------|------------------|--------------------------------------|
| `LIVE_ERP_FACT` | PHP `AiToolRegistry` reads | Yes (read path) | Yes |
| `DOCUMENT_EVIDENCE` | RAG retrieval | **No** | **No** |
| `CALCULATED_FINDING` | Deterministic domain math | No | Derived only |
| `MODEL_INTERPRETATION` | LLM narration | No | No |

RAG never upgrades itself to `LIVE_ERP_FACT`. Worker system prompt and citation payload set `tools_invocable: false`.

---

## 2. Index engine (local)

- **Store:** SQLite `rag_chunks_v2` + FTS5 `rag_fts_v2` (lexical default).
- **Metadata (worker-local):** `rag_documents` lifecycle + document_id linkage.
- **Scope key:** `{workspace_id}:{company_id}` — legacy unscoped rows are never promoted.
- **Optional embeddings:** offline batch of ≤2; interactive search stays lexical.
- **Forbidden to index:** secrets/config/DB dumps (`.env`, credentials, `*.pem`, SQL dumps, `config.json`, `wp-config.php`, etc.) — enforced in Python (`assert_indexable_path`) and PHP (`AiRagAcl::isForbiddenIndexPath`).

Qdrant/ES: **not used**. Revisit only with latency/recall measurements + T0 amendment here.

---

## 3. Document lifecycle

```
UPLOAD → VALIDATE → EXTRACT → NORMALIZE → CLASSIFY → CHUNK → INDEX → VERIFY → AVAILABLE
```

Operations from terminal-ish states:

| From | Allowed ops |
|------|-------------|
| `AVAILABLE` | `ARCHIVE`, `DELETE`, `REINDEX` |
| `ARCHIVE` | `REINDEX`, `DELETE` |
| `REINDEX` | re-enters at `VALIDATE` (or `DELETE`) |
| `DELETE` | terminal (chunks purged from worker index) |

PHP mirrors transitions in `AiRagAcl`; worker SQLite enforces via `RagIndex.set_lifecycle`. Only `AVAILABLE` documents are retrievable.

Control-plane rows: `ai_rag_documents.lifecycle_state` (+ `available_at` / `archived_at` / `deleted_at`).

---

## 4. ACL model (workspace → company → document → role)

```
workspace_id
  └── company_id
        └── ai_rag_documents (document_key)
              └── ai_rag_document_acl (role_key, can_retrieve)
```

**Enforcement point:** retrieval time, PHP control plane.

1. PHP computes `rag_allowed_document_ids` via `AiRagAcl::workerAllowListContext` (requires `ai.rag.retrieve` / `ai.use`, role ACL match, `lifecycle_state=AVAILABLE`).
2. Job `context_json` carries `rag_acl_enforced: true` + allow-list + `rag_scope`.
3. Worker opens `RagIndex` **only** for job `workspace_id:company_id` and calls `search(..., enforce_acl=True, allowed_document_ids=...)`.
4. Worker **must not** invent document IDs, widen company scope, or disable ACL locally.

Permissions (additive in `AiSchema` 1.2.0):

- `ai.rag.retrieve` — appear in allow-list / retrieve
- `ai.rag.manage` — lifecycle / index administration

---

## 5. Citation contract (retrieval hit shape)

Every hit returned to prompts/UI:

```json
{
  "evidence_kind": "DOCUMENT_EVIDENCE",
  "document_id": "...",
  "title": "...",
  "chunk_no": 0,
  "checksum": "...",
  "excerpt": "...",
  "text": "...",
  "workspace_id": 1,
  "company_id": 1,
  "scope": "1:1",
  "tools_invocable": false,
  "lifecycle": "AVAILABLE",
  "citation": {
    "label": "[doc:...#0]",
    "document_id": "...",
    "chunk_no": 0,
    "checksum": "...",
    "evidence_kind": "DOCUMENT_EVIDENCE"
  }
}
```

PHP re-sanitizes via `AiRagAcl::sanitizeCitations` before any user-facing replay.

---

## 6. Prompt-injection defenses

- Document text is untrusted data; patterns for tool/function fences, “ignore previous instructions”, `EXECUTE_*` are redacted (`sanitize_document_text` / `AiRagAcl::redactInjection`).
- Docs never invoke tools; model instructions state RAG ≠ authority.
- Indexing secrets/config/dumps is rejected fail-closed.

---

## 7. Schema note (T0)

Additive MySQL (AiSchema **1.2.0**):

- `ai_rag_documents`
- `ai_rag_document_acl`
- permissions `ai.rag.retrieve`, `ai.rag.manage`

Existing `ai_rag_sources` retained (legacy catalog). Shared-file freeze: merge only after T0 schema review.

---

## 8. Tests (synthetic / isolated)

| Case | Coverage |
|------|----------|
| Cross-company isolation | Same DB file, scopes `1:1` vs `1:2` — no leak |
| ACL allow-list | `enforce_acl=True` empty/foreign IDs → no hits |
| Delete invalidation | `DELETE` purges chunks; search empty |
| Citation shape | `evidence_kind`, `citation.label`, `tools_invocable=false` |
| Injection / forbidden path | Redaction + index path rejection |
| PHP lifecycle/ACL helpers | `tests/php_enterprise_rag_acl_test.php` |

**Do not claim `AI2_ACCEPTED` without ACL tests + T0 schema acceptance.** This pack targets `READY_FOR_AI2_RAG` / T0 review, not final AI2 gate.

---

## 9. Non-goals (this wave)

- Production / cPanel deploy
- MCP (T4)
- Parallel vector DB productization
- Claiming live ERP facts from documents
