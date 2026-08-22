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
