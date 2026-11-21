#!/usr/bin/env python3
"""Scoped, bounded local document retrieval. Current ERP facts never come from RAG.

Lexical retrieval is the default and loads no embedding model. Optional embeddings
are generated in batches of two, outside the interactive query path.

Wave2 adds document lifecycle, citation contracts (DOCUMENT_EVIDENCE), prompt-injection
sanitization, and retrieval-time ACL allow-lists issued by the PHP control plane.
The worker must never escalate document scope beyond job workspace:company + allow-list.
"""
from __future__ import annotations
import argparse
import hashlib
import heapq
import json
import math
import re
import sqlite3
import struct
import time
import urllib.request
from pathlib import Path
from local_inference import endpoint_lock


# --- Lifecycle (document pipeline) -------------------------------------------------
LIFECYCLE_PIPELINE = (
    "UPLOAD",
    "VALIDATE",
    "EXTRACT",
    "NORMALIZE",
    "CLASSIFY",
    "CHUNK",
    "INDEX",
    "VERIFY",
    "AVAILABLE",
)
LIFECYCLE_OPS = ("ARCHIVE", "DELETE", "REINDEX")
LIFECYCLE_STATES = LIFECYCLE_PIPELINE + LIFECYCLE_OPS

_LIFECYCLE_TRANSITIONS: dict[str, frozenset[str]] = {
    "UPLOAD": frozenset({"VALIDATE", "DELETE"}),
    "VALIDATE": frozenset({"EXTRACT", "DELETE"}),
    "EXTRACT": frozenset({"NORMALIZE", "DELETE"}),
    "NORMALIZE": frozenset({"CLASSIFY", "DELETE"}),
    "CLASSIFY": frozenset({"CHUNK", "DELETE"}),
    "CHUNK": frozenset({"INDEX", "DELETE"}),
    "INDEX": frozenset({"VERIFY", "DELETE"}),
    "VERIFY": frozenset({"AVAILABLE", "DELETE"}),
    "AVAILABLE": frozenset({"ARCHIVE", "DELETE", "REINDEX"}),
    "ARCHIVE": frozenset({"REINDEX", "DELETE"}),
    "REINDEX": frozenset({"VALIDATE", "DELETE"}),
    "DELETE": frozenset(),
}

EVIDENCE_KIND = "DOCUMENT_EVIDENCE"
FORBIDDEN_INDEX_NAME_RE = re.compile(
    r"(?i)(?:^|[/\\])(?:\.env(?:\..+)?|credentials(?:\.[^/\\]+)?|.*secrets?.*|"
    r".*\.pem$|id_rsa(?:\.pub)?|.*(?:dump|backup).*\.sql(?:\.gz|\.bz2)?$|"
    r"config\.json|wp-config\.php|\.htpasswd|id_ed25519)$"
)
# Document text must never be interpreted as tool invocations or authority.
_INJECTION_PATTERNS = (
    re.compile(r"(?is)<\s*(?:tool|function|invoke|api)[\s>]"),
    re.compile(r"(?is)\b(?:ignore|disregard)\b.{0,40}\b(?:previous|prior|above)\b.{0,40}\binstructions?\b"),
    re.compile(r"(?is)\b(?:you are now|act as|system\s*:)\b"),
    re.compile(r"(?is)```(?:json)?\s*\{\s*\"(?:tool|name|function)\""),
    re.compile(r"(?is)\bcall\s+tool\b|\btool_calls?\b|\bfunction_call\b"),
    re.compile(r"(?is)\bEXECUTE_(?:SQL|TOOL|PROPOSAL)\b"),
)


def chunks(text: str, size: int = 1000, overlap: int = 120):
    if size <= 0 or overlap < 0 or overlap >= size:
        raise ValueError("invalid_chunk_bounds")
    text = re.sub(r"\r\n?", "\n", text).strip()
    start = 0
    while start < len(text):
        end = min(len(text), start + size)
        if end < len(text):
            cut = max(text.rfind("\n", start, end), text.rfind(". ", start, end), text.rfind("؟", start, end))
            if cut > start + size // 2:
                end = cut + 1
        piece = text[start:end].strip()
        if piece:
            yield piece
        if end == len(text):
            break
        start = end - overlap


def norm(text):
    return str(text).lower().replace("ي", "ی").replace("ك", "ک").replace("\u200c", " ")


def sanitize_document_text(text: str) -> str:
    """Neutralize prompt-injection / tool-invocation patterns in DOCUMENT_EVIDENCE."""
    out = str(text)
    for pat in _INJECTION_PATTERNS:
        out = pat.sub("⟦redacted-untrusted-doc⟧", out)
    # Never leave raw tool-shaped JSON fences that models may treat as calls.
    out = re.sub(r"(?is)```(?:xml|tool|function).*?```", "⟦redacted-untrusted-doc⟧", out)
    return out


def assert_indexable_path(path: Path) -> None:
    name = str(path)
    if FORBIDDEN_INDEX_NAME_RE.search(name.replace("\\", "/")):
        raise ValueError("rag_forbidden_secret_or_config_path")
    suffix = path.suffix.lower()
    if suffix in {".env", ".pem", ".key", ".p12", ".pfx", ".sqlite3-journal"}:
        raise ValueError("rag_forbidden_secret_or_config_path")


def embed(base: str, model: str, inputs: list[str], timeout: int = 30) -> list[list[float]]:
    if len(inputs) > 2 or any(len(x.encode("utf-8")) > 4096 for x in inputs):
        raise ValueError("embedding_batch_budget")
    endpoint = base.rstrip("/")
    key = hashlib.sha256(endpoint.encode()).hexdigest()[:12]
    with endpoint_lock(Path(__file__).resolve().parent / "data", key, time.monotonic() + 1):
        req = urllib.request.Request(endpoint + "/api/embed", data=json.dumps({
            "model": model, "input": inputs, "keep_alive": 0, "truncate": False,
        }, ensure_ascii=False).encode(), method="POST", headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as response:
            raw = response.read(1024 * 1024 + 1)
        if len(raw) > 1024 * 1024:
            raise ValueError("embedding_response_budget")
        vectors = json.loads(raw)["embeddings"]
        if len(vectors) != len(inputs) or any(not v or not all(isinstance(x, (int, float)) and math.isfinite(x) for x in v) for v in vectors):
            raise ValueError("embedding_shape")
        return vectors


def pack(v):
    return struct.pack(f"<{len(v)}f", *v)


def unpack(b, n):
    return struct.unpack(f"<{n}f", b)


def lexical(query, text):
    tokens = {x for x in re.findall(r"\w+", norm(query)) if len(x) > 1}
    return sum(x in norm(text) for x in tokens) / len(tokens) if tokens else 0.0


def parse_scope(scope: str) -> tuple[int, int]:
    parts = str(scope).split(":", 1)
    if len(parts) != 2 or not parts[0].isdigit() or not parts[1].isdigit():
        raise ValueError("invalid_rag_scope")
    wid, cid = int(parts[0]), int(parts[1])
    if wid <= 0 or cid <= 0:
        raise ValueError("invalid_rag_scope")
    return wid, cid


def transition_lifecycle(current: str, target: str) -> str:
    cur = str(current).upper()
    tgt = str(target).upper()
    if cur not in _LIFECYCLE_TRANSITIONS or tgt not in LIFECYCLE_STATES:
        raise ValueError("invalid_lifecycle_state")
    if tgt not in _LIFECYCLE_TRANSITIONS[cur]:
        raise ValueError(f"invalid_lifecycle_transition:{cur}->{tgt}")
    return tgt


def citation_payload(
    *,
    document_id: str,
    source: str,
    title: str,
    chunk_no: int,
    checksum: str,
    excerpt: str,
    score: float,
    scope: str,
    truncated: bool,
    retrieval: str = "lexical",
    lifecycle: str = "AVAILABLE",
) -> dict:
    wid, cid = parse_scope(scope)
    safe = sanitize_document_text(excerpt)
    return {
        "evidence_kind": EVIDENCE_KIND,
        "document_id": document_id,
        "source": source,
        "title": title,
        "chunk_no": chunk_no,
        "checksum": checksum,
        "text": safe,
        "excerpt": safe,
        "score": round(score, 6),
        "scope": scope,
        "workspace_id": wid,
        "company_id": cid,
        "retrieval": retrieval,
        "truncated": truncated,
        "lifecycle": lifecycle,
        "tools_invocable": False,
        "citation": {
            "label": f"[doc:{document_id}#{chunk_no}]",
            "document_id": document_id,
            "chunk_no": chunk_no,
            "checksum": checksum,
            "evidence_kind": EVIDENCE_KIND,
        },
    }


class RagIndex:
    def __init__(self, db: str | Path, ollama_url: str, model: str, *, scope: str = ""):
        self.db = Path(db)
        self.db.parent.mkdir(parents=True, exist_ok=True)
        self.ollama_url, self.model, self.scope = ollama_url, model, scope
        if scope:
            parse_scope(scope)
        self.con = sqlite3.connect(self.db)
        self.con.create_function("rag_normalize", 1, norm, deterministic=True)
        self.con.execute("PRAGMA journal_mode=WAL")
        # Legacy unscoped rows remain untouched and are never promoted into tenant scope.
        self.con.execute("""CREATE TABLE IF NOT EXISTS rag_chunks_v2(
            id INTEGER PRIMARY KEY, scope TEXT NOT NULL, source TEXT NOT NULL,
            chunk_no INTEGER NOT NULL, text TEXT NOT NULL, vector BLOB NOT NULL,
            dim INTEGER NOT NULL, checksum TEXT NOT NULL, embedding_model TEXT NOT NULL,
            UNIQUE(scope,source,chunk_no))""")
        self.con.execute("CREATE INDEX IF NOT EXISTS idx_rag_v2_scope ON rag_chunks_v2(scope)")
        self._ensure_chunk_document_column()
        self.con.execute("""CREATE TABLE IF NOT EXISTS rag_documents(
            id INTEGER PRIMARY KEY,
            scope TEXT NOT NULL,
            document_id TEXT NOT NULL,
            source TEXT NOT NULL,
            title TEXT NOT NULL,
            lifecycle TEXT NOT NULL,
            checksum TEXT,
            role_acl TEXT NOT NULL DEFAULT '[]',
            indexed_revision INTEGER NOT NULL DEFAULT 0,
            updated_at REAL NOT NULL,
            UNIQUE(scope, document_id))""")
        self.con.execute("CREATE INDEX IF NOT EXISTS idx_rag_docs_scope_life ON rag_documents(scope, lifecycle)")
        self.fts = True
        try:
            exists = self.con.execute("SELECT 1 FROM sqlite_master WHERE name='rag_fts_v2'").fetchone()
            self.con.execute("CREATE VIRTUAL TABLE IF NOT EXISTS rag_fts_v2 USING fts5(text, content='rag_chunks_v2', content_rowid='id')")
            self.con.executescript("""
                CREATE TRIGGER IF NOT EXISTS rag_ai_v2 AFTER INSERT ON rag_chunks_v2 BEGIN
                    INSERT INTO rag_fts_v2(rowid,text) VALUES (new.id,rag_normalize(new.text)); END;
                CREATE TRIGGER IF NOT EXISTS rag_ad_v2 AFTER DELETE ON rag_chunks_v2 BEGIN
                    INSERT INTO rag_fts_v2(rag_fts_v2,rowid,text) VALUES ('delete',old.id,rag_normalize(old.text)); END;
            """)
            if not exists:
                self.con.execute("INSERT INTO rag_fts_v2(rowid,text) SELECT id,rag_normalize(text) FROM rag_chunks_v2")
        except sqlite3.OperationalError:
            self.fts = False
        self.con.commit()

    def _ensure_chunk_document_column(self) -> None:
        cols = {r[1] for r in self.con.execute("PRAGMA table_info(rag_chunks_v2)")}
        if "document_id" not in cols:
            self.con.execute("ALTER TABLE rag_chunks_v2 ADD COLUMN document_id TEXT NOT NULL DEFAULT ''")
        if "title" not in cols:
            self.con.execute("ALTER TABLE rag_chunks_v2 ADD COLUMN title TEXT NOT NULL DEFAULT ''")
        self.con.execute("CREATE INDEX IF NOT EXISTS idx_rag_v2_doc ON rag_chunks_v2(scope, document_id)")

    def close(self):
        self.con.close()

    def _document_id_for_source(self, source: str) -> str:
        return hashlib.sha256(f"{self.scope}|{source}".encode()).hexdigest()[:24]

    def set_lifecycle(self, document_id: str, target: str) -> str:
        row = self.con.execute(
            "SELECT lifecycle FROM rag_documents WHERE scope=? AND document_id=?",
            (self.scope, document_id),
        ).fetchone()
        if not row:
            raise ValueError("rag_document_not_found")
        nxt = transition_lifecycle(row[0], target)
        with self.con:
            self.con.execute(
                "UPDATE rag_documents SET lifecycle=?, updated_at=? WHERE scope=? AND document_id=?",
                (nxt, time.time(), self.scope, document_id),
            )
            if nxt == "DELETE":
                self.con.execute(
                    "DELETE FROM rag_chunks_v2 WHERE scope=? AND document_id=?",
                    (self.scope, document_id),
                )
            if nxt == "ARCHIVE":
                # Archived docs remain stored but must not be retrieved.
                pass
        return nxt

    def delete_document(self, document_id: str) -> None:
        self.set_lifecycle(document_id, "DELETE")

    def archive_document(self, document_id: str) -> None:
        self.set_lifecycle(document_id, "ARCHIVE")

    def reindex_document(self, document_id: str) -> str:
        return self.set_lifecycle(document_id, "REINDEX")

    def index_path(self, root, *, use_embeddings=False, title: str | None = None, role_acl: list[str] | None = None):
        root = Path(root)
        total = 0
        for p in root.rglob("*") if root.is_dir() else [root]:
            if not p.is_file():
                continue
            # Fail closed on secrets/config/dumps before extension allow-list.
            assert_indexable_path(p)
            if p.suffix.lower() not in {".txt", ".md", ".csv", ".json", ".log"}:
                if not root.is_dir():
                    raise ValueError("rag_unsupported_extension")
                continue
            if p.stat().st_size > 2 * 1024 * 1024:
                raise ValueError("rag_file_budget_exceeded")
            source = str(p.resolve())
            document_id = self._document_id_for_source(source)
            doc_title = title or p.name
            raw_parts = list(chunks(p.read_text(encoding="utf-8", errors="replace")))
            parts = [sanitize_document_text(x) for x in raw_parts]
            checksums = [hashlib.sha256(x.encode()).hexdigest() for x in parts]
            mode = self.model if use_embeddings else ""
            old = self.con.execute(
                "SELECT checksum,embedding_model FROM rag_chunks_v2 WHERE scope=? AND source=? ORDER BY chunk_no",
                (self.scope, source),
            ).fetchall()
            if old == [(c, mode) for c in checksums]:
                with self.con:
                    self.con.execute(
                        """INSERT INTO rag_documents(scope,document_id,source,title,lifecycle,checksum,role_acl,indexed_revision,updated_at)
                           VALUES(?,?,?,?,?,?,?,?,?)
                           ON CONFLICT(scope,document_id) DO UPDATE SET
                             lifecycle='AVAILABLE', title=excluded.title, checksum=excluded.checksum,
                             role_acl=excluded.role_acl, updated_at=excluded.updated_at""",
                        (
                            self.scope,
                            document_id,
                            source,
                            doc_title,
                            "AVAILABLE",
                            checksums[0] if checksums else "",
                            json.dumps(role_acl or [], ensure_ascii=False),
                            0,
                            time.time(),
                        ),
                    )
                continue
            # Materialize new document vectors before replacing old rows. A failed
            # embedding call leaves the previous version intact.
            vectors = []
            for start in range(0, len(parts), 2):
                batch = parts[start:start + 2]
                vectors.extend(embed(self.ollama_url, self.model, batch) if use_embeddings else [[] for _ in batch])
            with self.con:
                self.con.execute("DELETE FROM rag_chunks_v2 WHERE scope=? AND source=?", (self.scope, source))
                self.con.executemany(
                    """INSERT INTO rag_chunks_v2(scope,source,chunk_no,text,vector,dim,checksum,embedding_model,document_id,title)
                       VALUES(?,?,?,?,?,?,?,?,?,?)""",
                    [
                        (
                            self.scope,
                            source,
                            i,
                            text,
                            pack(vectors[i]),
                            len(vectors[i]),
                            checksums[i],
                            mode,
                            document_id,
                            doc_title,
                        )
                        for i, text in enumerate(parts)
                    ],
                )
                self.con.execute(
                    """INSERT INTO rag_documents(scope,document_id,source,title,lifecycle,checksum,role_acl,indexed_revision,updated_at)
                       VALUES(?,?,?,?,?,?,?,?,?)
                       ON CONFLICT(scope,document_id) DO UPDATE SET
                         source=excluded.source, title=excluded.title, lifecycle='AVAILABLE',
                         checksum=excluded.checksum, role_acl=excluded.role_acl,
                         indexed_revision=rag_documents.indexed_revision+1, updated_at=excluded.updated_at""",
                    (
                        self.scope,
                        document_id,
                        source,
                        doc_title,
                        "AVAILABLE",
                        checksums[0] if checksums else "",
                        json.dumps(role_acl or [], ensure_ascii=False),
                        1,
                        time.time(),
                    ),
                )
            total += len(parts)
        return total

    def search(
        self,
        query: str,
        top_k: int = 5,
        *,
        max_chars: int = 1600,
        allowed_document_ids: list[str] | None = None,
        enforce_acl: bool = False,
    ) -> list[dict]:
        """Retrieve DOCUMENT_EVIDENCE only.

        When enforce_acl is True (Wave2 control-plane path), allowed_document_ids is
        required and empty means fail-closed (no hits). Worker must not widen scope.
        """
        top_k = max(1, min(8, int(top_k)))
        tokens = list(dict.fromkeys(re.findall(r"\w+", norm(query))))[:24]
        if not tokens:
            return []
        if enforce_acl:
            if allowed_document_ids is None:
                raise ValueError("rag_acl_allow_list_required")
            if not allowed_document_ids:
                return []
        allow = set(allowed_document_ids) if allowed_document_ids is not None else None

        # Lifecycle map: registered docs must be AVAILABLE; unknown (legacy) chunks remain searchable.
        doc_lifecycle = {
            r[0]: r[1]
            for r in self.con.execute(
                "SELECT document_id, lifecycle FROM rag_documents WHERE scope=?",
                (self.scope,),
            )
        }

        if self.fts:
            match = " OR ".join('"' + token.replace('"', '""') + '"' for token in tokens)
            rows = self.con.execute(
                """SELECT c.id,c.source,c.chunk_no,c.text,c.checksum,c.document_id,c.title,bm25(rag_fts_v2)
                FROM rag_fts_v2 JOIN rag_chunks_v2 c ON c.id=rag_fts_v2.rowid
                WHERE rag_fts_v2 MATCH ? AND c.scope=? ORDER BY bm25(rag_fts_v2) LIMIT ?""",
                (match, self.scope, max(top_k * 4, top_k)),
            )
            selected = [
                (float(-score), rid, source, no, text, checksum, doc_id or self._document_id_for_source(source), title or Path(source).name)
                for rid, source, no, text, checksum, doc_id, title, score in rows
            ]
        else:
            rows = self.con.execute(
                "SELECT id,source,chunk_no,text,checksum,document_id,title FROM rag_chunks_v2 WHERE scope=?",
                (self.scope,),
            )
            selected = heapq.nlargest(
                max(top_k * 4, top_k),
                (
                    (
                        lexical(query, text),
                        rid,
                        source,
                        no,
                        text,
                        checksum,
                        doc_id or self._document_id_for_source(source),
                        title or Path(source).name,
                    )
                    for rid, source, no, text, checksum, doc_id, title in rows
                ),
                key=lambda row: row[0],
            )
            selected = [r for r in selected if r[0] > 0]

        out, remaining = [], max(0, min(4000, int(max_chars)))
        for score, rid, source, no, text, checksum, doc_id, title in selected:
            if remaining <= 0 or len(out) >= top_k:
                break
            # Lifecycle gate: only AVAILABLE documents are retrievable.
            life = doc_lifecycle.get(doc_id)
            if life is not None and life != "AVAILABLE":
                continue
            if allow is not None and doc_id not in allow:
                continue
            excerpt = text[:remaining]
            remaining -= len(excerpt)
            hit = citation_payload(
                document_id=doc_id,
                source=source,
                title=title,
                chunk_no=no,
                checksum=checksum,
                excerpt=excerpt,
                score=score,
                scope=self.scope,
                truncated=len(excerpt) < len(text),
            )
            hit["id"] = rid
            out.append(hit)
        return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("command", choices=["index", "search", "lifecycle"])
    ap.add_argument("value")
    ap.add_argument("--db", default="data/rag.sqlite3")
    ap.add_argument("--ollama", default="http://127.0.0.1:11434")
    ap.add_argument("--model", default="embeddinggemma")
    ap.add_argument("--workspace-id", type=int, required=True)
    ap.add_argument("--company-id", type=int, required=True)
    ap.add_argument("--top-k", type=int, default=5)
    ap.add_argument("--embed", action="store_true", help="Optional offline vectors; interactive search remains lexical")
    ap.add_argument("--document-id", default="")
    ap.add_argument("--target-state", default="")
    ap.add_argument("--allowed-document-id", action="append", default=[])
    ap.add_argument("--enforce-acl", action="store_true")
    args = ap.parse_args()
    if args.workspace_id <= 0 or args.company_id <= 0:
        ap.error("positive workspace and company IDs are required")
    idx = RagIndex(args.db, args.ollama, args.model, scope=f"{args.workspace_id}:{args.company_id}")
    try:
        if args.command == "index":
            result = idx.index_path(args.value, use_embeddings=args.embed)
        elif args.command == "lifecycle":
            if not args.document_id or not args.target_state:
                ap.error("--document-id and --target-state required for lifecycle")
            result = {"document_id": args.document_id, "lifecycle": idx.set_lifecycle(args.document_id, args.target_state)}
        else:
            result = idx.search(
                args.value,
                args.top_k,
                allowed_document_ids=args.allowed_document_id or None,
                enforce_acl=args.enforce_acl,
            )
        print(json.dumps(result, ensure_ascii=False, indent=2))
    finally:
        idx.close()


if __name__ == "__main__":
    main()
