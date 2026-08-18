#!/usr/bin/env python3
"""Small-footprint local RAG index for the accounting AI engine.

Designed for the bootstrap phase on low-resource Windows/Linux machines. It uses
Ollama embeddings and SQLite only. For large production corpora this module can
be replaced behind the same interface with FAISS/Qdrant/pgvector.
"""
from __future__ import annotations
import argparse, hashlib, json, math, re, sqlite3, struct, urllib.request
from pathlib import Path
from typing import Iterable


def chunks(text: str, size: int = 1400, overlap: int = 220) -> Iterable[str]:
    text = re.sub(r"\r\n?", "\n", text).strip()
    if not text: return
    start = 0
    while start < len(text):
        end = min(len(text), start + size)
        if end < len(text):
            cut = max(text.rfind("\n", start, end), text.rfind(". ", start, end), text.rfind("؟", start, end))
            if cut > start + size // 2: end = cut + 1
        piece = text[start:end].strip()
        if piece: yield piece
        if end >= len(text): break
        start = max(start + 1, end - overlap)


def embed(base: str, model: str, inputs: list[str], timeout: int = 300) -> list[list[float]]:
    req = urllib.request.Request(base.rstrip("/") + "/api/embed", data=json.dumps({"model": model, "input": inputs}, ensure_ascii=False).encode(), method="POST", headers={"Content-Type":"application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as r: data = json.loads(r.read().decode())
    return data["embeddings"]


def pack(v: list[float]) -> bytes: return struct.pack(f"<{len(v)}f", *v)
def unpack(b: bytes, n: int) -> tuple[float,...]: return struct.unpack(f"<{n}f", b)

def lexical(q: str, text: str) -> float:
    toks = {x for x in re.findall(r"[\w\u0600-\u06FF]+", q.lower()) if len(x) > 1}
    if not toks: return 0.0
    low = text.lower(); return sum(1 for t in toks if t in low) / len(toks)


class RagIndex:
    def __init__(self, db: str | Path, ollama_url: str, model: str):
        self.db = Path(db); self.db.parent.mkdir(parents=True, exist_ok=True)
        self.ollama_url=ollama_url; self.model=model
        self.con=sqlite3.connect(self.db)
        self.con.execute("PRAGMA journal_mode=WAL")
        self.con.execute("CREATE TABLE IF NOT EXISTS chunks(id INTEGER PRIMARY KEY, source TEXT NOT NULL, chunk_no INTEGER NOT NULL, text TEXT NOT NULL, vector BLOB NOT NULL, dim INTEGER NOT NULL, checksum TEXT NOT NULL, UNIQUE(source,chunk_no))")
        self.con.execute("CREATE INDEX IF NOT EXISTS idx_rag_source ON chunks(source)"); self.con.commit()

    def index_path(self, root: str | Path) -> int:
        root=Path(root); files=[p for p in (root.rglob('*') if root.is_dir() else [root]) if p.is_file() and p.suffix.lower() in {'.txt','.md','.csv','.json','.log'}]
        total=0
        for p in files:
            try: text=p.read_text(encoding='utf-8', errors='ignore')
            except Exception: continue
            parts=list(chunks(text));
            if not parts: continue
            self.con.execute("DELETE FROM chunks WHERE source=?", (str(p.resolve()),))
            for base in range(0,len(parts),16):
                batch=parts[base:base+16]; vecs=embed(self.ollama_url,self.model,batch)
                for off,(piece,v) in enumerate(zip(batch,vecs)):
                    no=base+off; cs=hashlib.sha256(piece.encode()).hexdigest()
                    self.con.execute("INSERT INTO chunks(source,chunk_no,text,vector,dim,checksum) VALUES(?,?,?,?,?,?)",(str(p.resolve()),no,piece,pack(v),len(v),cs)); total+=1
            self.con.commit(); print(f"indexed {p}: {len(parts)} chunks")
        return total

    def search(self, query: str, top_k: int=5) -> list[dict]:
        qv=embed(self.ollama_url,self.model,[query])[0]
        rows=self.con.execute("SELECT id,source,chunk_no,text,vector,dim FROM chunks").fetchall(); scored=[]
        for rid,src,no,text,b,dim in rows:
            if dim != len(qv): continue
            v=unpack(b,dim); semantic=sum(a*x for a,x in zip(qv,v))  # Ollama embeddings are normalized
            score=.86*semantic+.14*lexical(query,text)
            scored.append((score,rid,src,no,text))
        scored.sort(reverse=True,key=lambda x:x[0])
        return [{"score":round(s,6),"id":rid,"source":src,"chunk_no":no,"text":text} for s,rid,src,no,text in scored[:max(1,top_k)]]


def main():
    ap=argparse.ArgumentParser(); ap.add_argument('command',choices=['index','search']); ap.add_argument('value'); ap.add_argument('--db',default='data/rag.sqlite3'); ap.add_argument('--ollama',default='http://127.0.0.1:11434'); ap.add_argument('--model',default='embeddinggemma'); ap.add_argument('--top-k',type=int,default=5); a=ap.parse_args()
    here=Path(__file__).resolve().parent; db=Path(a.db); db=db if db.is_absolute() else here/db; idx=RagIndex(db,a.ollama,a.model)
    if a.command=='index': print(f"total chunks: {idx.index_path(a.value)}")
    else: print(json.dumps(idx.search(a.value,a.top_k),ensure_ascii=False,indent=2))
if __name__=='__main__': main()
