---
id: rag_knowledge
status: foundation_only
touches_code:
  - engine/rag.py
---

# RAG & Knowledge

## Current priority

Low relative to Planner/Agent/Financial Intelligence unless a document-based workflow explicitly requires it.

## Use RAG for

- tax/legal documents
- contracts
- company procedures
- accounting manuals
- client document evidence

## Do not use RAG as current financial truth

- current balance
- sales total
- purchase total
- invoice amount
- trial balance
- current document status

## Scaling gate

Do not add Qdrant/FastRAG/reranking merely because available.

Adopt when measured corpus size/latency/retrieval quality requires it and an evaluation set exists.

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.

RAG is a scoped local foundation: lexical FTS5 default, no interactive embedding load, incremental checksums and bounded top-k. It remains disabled by default; production per-document ACL/freshness/deletion/eval work is pending.
