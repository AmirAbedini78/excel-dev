# 06 - RAG Architecture and ACL

Extends engine/rag.py (SQLite FTS5). PHP AiRagAcl + AiSchema document metadata enforce Workspace->Company->Document->Role at retrieval time. Citations distinguish DOCUMENT_EVIDENCE from LIVE_ERP_FACT. Documents never invoke tools.
