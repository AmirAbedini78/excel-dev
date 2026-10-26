#!/usr/bin/env python3
"""Scoped, bounded local document retrieval. Current ERP facts never come from RAG.

Lexical retrieval is the default and loads no embedding model. Optional embeddings
are generated in batches of two, outside the interactive query path.
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


class RagIndex:
    def __init__(self, db: str | Path, ollama_url: str, model: str, *, scope: str = ""):
        self.db = Path(db)
        self.db.parent.mkdir(parents=True, exist_ok=True)
        self.ollama_url, self.model, self.scope = ollama_url, model, scope
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

    def close(self):
        self.con.close()

    def index_path(self, root, *, use_embeddings=False):
        root = Path(root)
        total = 0
        for p in root.rglob("*") if root.is_dir() else [root]:
            if not p.is_file() or p.suffix.lower() not in {".txt", ".md", ".csv", ".json", ".log"}:
                continue
            if p.stat().st_size > 2 * 1024 * 1024:
                raise ValueError("rag_file_budget_exceeded")
            source = str(p.resolve())
            parts = list(chunks(p.read_text(encoding="utf-8", errors="replace")))
            checksums = [hashlib.sha256(x.encode()).hexdigest() for x in parts]
            mode = self.model if use_embeddings else ""
            old = self.con.execute("SELECT checksum,embedding_model FROM rag_chunks_v2 WHERE scope=? AND source=? ORDER BY chunk_no",
                                   (self.scope, source)).fetchall()
            if old == [(c, mode) for c in checksums]:
                continue
            # Materialize new document vectors before replacing old rows. A failed
            # embedding call leaves the previous version intact.
            vectors = []
            for start in range(0, len(parts), 2):
                batch = parts[start:start + 2]
                vectors.extend(embed(self.ollama_url, self.model, batch) if use_embeddings else [[] for _ in batch])
            with self.con:
                self.con.execute("DELETE FROM rag_chunks_v2 WHERE scope=? AND source=?", (self.scope, source))
                self.con.executemany("INSERT INTO rag_chunks_v2(scope,source,chunk_no,text,vector,dim,checksum,embedding_model) VALUES(?,?,?,?,?,?,?,?)",
                    [(self.scope, source, i, text, pack(vectors[i]), len(vectors[i]), checksums[i], mode) for i, text in enumerate(parts)])
            total += len(parts)
        return total

    def search(self, query: str, top_k: int = 5, *, max_chars: int = 1600) -> list[dict]:
        top_k = max(1, min(8, int(top_k)))
        tokens = list(dict.fromkeys(re.findall(r"\w+", norm(query))))[:24]
        if not tokens:
            return []
        if self.fts:
            match = " OR ".join('"' + token.replace('"', '""') + '"' for token in tokens)
            rows = self.con.execute("""SELECT c.id,c.source,c.chunk_no,c.text,c.checksum,bm25(rag_fts_v2)
                FROM rag_fts_v2 JOIN rag_chunks_v2 c ON c.id=rag_fts_v2.rowid
                WHERE rag_fts_v2 MATCH ? AND c.scope=? ORDER BY bm25(rag_fts_v2) LIMIT ?""",
                (match, self.scope, top_k))
            selected = [(float(-score), rid, source, no, text, checksum) for rid, source, no, text, checksum, score in rows]
        else:
            # Streaming top-k fallback; memory never scales with the full corpus.
            rows = self.con.execute("SELECT id,source,chunk_no,text,checksum FROM rag_chunks_v2 WHERE scope=?", (self.scope,))
            selected = heapq.nlargest(top_k, ((lexical(query, text), rid, source, no, text, checksum)
                                            for rid, source, no, text, checksum in rows), key=lambda row: row[0])
            selected = [r for r in selected if r[0] > 0]
        out, remaining = [], max(0, min(4000, int(max_chars)))
        for score, rid, source, no, text, checksum in selected:
            if remaining <= 0:
                break
            excerpt = text[:remaining]
            remaining -= len(excerpt)
            out.append({"score": round(score, 6), "id": rid, "source": source, "chunk_no": no,
                        "text": excerpt, "checksum": checksum, "scope": self.scope,
                        "retrieval": "lexical", "truncated": len(excerpt) < len(text)})
        return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("command", choices=["index", "search"])
    ap.add_argument("value")
    ap.add_argument("--db", default="data/rag.sqlite3")
    ap.add_argument("--ollama", default="http://127.0.0.1:11434")
    ap.add_argument("--model", default="embeddinggemma")
    ap.add_argument("--workspace-id", type=int, required=True)
    ap.add_argument("--company-id", type=int, required=True)
    ap.add_argument("--top-k", type=int, default=5)
    ap.add_argument("--embed", action="store_true", help="Optional offline vectors; interactive search remains lexical")
    args = ap.parse_args()
    if args.workspace_id <= 0 or args.company_id <= 0:
        ap.error("positive workspace and company IDs are required")
    idx = RagIndex(args.db, args.ollama, args.model, scope=f"{args.workspace_id}:{args.company_id}")
    try:
        result = idx.index_path(args.value, use_embeddings=args.embed) if args.command == "index" else idx.search(args.value, args.top_k)
        print(json.dumps(result, ensure_ascii=False, indent=2))
    finally:
        idx.close()


if __name__ == "__main__":
    main()
