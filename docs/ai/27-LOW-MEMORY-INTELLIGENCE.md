# 27 — Low-memory Intelligence / Cycle 12.3 v10.10.3

Status: LOCAL-VALIDATED. Real Windows/Docker/Ollama performance and product acceptance are pending.

## Development contract

- Baseline commit: 7415f1471573cce6e398317069567894a7209c4a.
- Current patch input: exact delivered v10.10.2 / Kernel v1.5 working state, installed in the supplied target log.
- Historical foundation input: uploaded excel-dev(2).zip with r6 / Kernel v1.4 applied.
- GitHub verification: all 210 committed blob hashes match the ZIP's committed tree.
- Requirement: useful grounded decisions and bounded inference on the existing small models; retain the distributed worker and guarded ERP boundaries.
- Scope: local transport, provider routing, bounded decision selection, supplier comparison, trade context, document retrieval foundation, diagnostics and packaging.
- No change to the North Star, operational database, financial calculations, proposal/approval authority, or existing API schemas.
- Risk: conservative context/memory guards may skip a model that could fit; a small model can still fail semantic qualification. Model-generated general reasoning and accurate financial forecasting are not established by this release.
- Success criteria: the reported supplier portfolio has a per-supplier answer; known risk survives fallback; explicit models are respected; incomplete inference never passes; local memory failures do not destroy available domain results; retry budgets and scoped retrieval are tested.

## Findings from code and history

1. History9 records planner PASS at 71.7 seconds, analyst JSON failures, a provider failure, and a degraded r6 install. It does not contain sufficient RAM/VRAM evidence to attribute every failure to out-of-memory.
2. The old synthesis requested three Persian narrative claims, evidence references, an action, confidence and a limitation within 240 output tokens. Observations and up to 48 evidence rows repeated the same information inside a 2,000-token context. This is a structural mismatch for a tiny model. JSON schema alone does not provide extra generation tokens.
3. ProviderGateway used a mutable role hint to replace an explicitly requested model. This also made preflight model labels unreliable.
4. The old fallback selected a lower-risk supplier only if it was unique. Multiple suppliers without high-risk cases caused the answer to lose the positive observation that another supplier did have a recorded risk.
5. An early stream close or done_reason=length was not treated as a failed contract. Socket read timeouts did not bound a stream that kept emitting bytes.
6. The degraded analyst route had no expiry or model-digest/runtime fingerprint.
7. Trade Kernel reads could ignore an attached trade-case ID and discuss another case from the same company portfolio. The company boundary was preserved, but the selected entity was not.
8. The original local RAG loaded the full vector corpus and requested an embedding on every query. It had no tenant scope and was disabled by default.

## Runtime contract

~~~text
User goal + validated entity refs
→ existing capability selection / known read fast path
→ server-compiled read actions
→ fresh ERP evidence
→ deterministic findings and qualified limitations
→ small model selects finding IDs (optional)
→ server enforces severity / renders complete comparison
~~~

The model may choose only issued finding/action IDs. It cannot write arbitrary claims, dates, amounts, ERP IDs, confidence, or authorizations. The small-model result is advisory prioritization, not proof of general business reasoning. A stronger model can use the same contracts after qualification.

Known Kernel reads default to kernel_planner_mode=auto: evidence rules choose useful read drill-downs without a repeated model call. The bounded model planner is retained with kernel_planner_mode=model for evaluation. The outer capability/legacy guarded routes remain available; this is not universal free-form operation coverage.

- 4 maximum successful tool calls and 3 model-planner calls.
- Default Kernel inference deadline: 75 seconds, including optional steps. A synchronous ERP Tool call still follows the existing API transport timeout and may exceed this deadline; this release does not claim a strict end-to-end network SLA.
- Selection: up to 3 finding IDs and one advisory action ID, 128 output tokens, 2,048 context ceiling.
- Each synthesis attempt is bounded by the remaining Kernel budget and at most 60 seconds.
- Full observations, ERP IDs and narrative facts remain on the server side of selection.
- A long request is never silently sliced to make it fit; the advisory call is skipped.
- There is no answer cache. Every supported comparison reads ERP Tools again.

## Capacity and transport

local_inference.py uses only Python's standard library.

- Explicit per-request context, output, batch, CPU thread, keep-alive and response-byte limits.
- A conservative UTF-8 byte-based token upper bound, including framing/tools and a reserved output budget. This is not a measured tokenizer count.
- Context can grow from a caller preference only within the configured maximum; oversized prompts are rejected before submission.
- One in-flight call per endpoint among workers sharing the data directory, using an OS file lock.
- Ollama Compose: one model loaded, one parallel request, maximum queue two.
- Conservative CPU memory admission using model artifact size, local host MemAvailable and target resident memory when available; an optional per-endpoint memory budget.
- The worker's cgroup memory limit is not mistaken for the sibling Ollama container's capacity.
- A peer endpoint never uses this worker's RAM as its capacity estimate.
- Wall deadline interrupts continuous partial-byte streams. A terminal done event is required. Truncation, stream errors, oversized output and missing terminal events fail validation.
- Classified failures are redacted; raw provider error bodies are not published.
- Per-endpoint/model capacity circuits: 300 seconds for memory/missing-model problems, 30 seconds for other transport failures.
- A model digest change invalidates the corresponding in-memory circuit.
- A preflight degraded route expires after 300 seconds; a qualified route expires after 24 hours. Both depend on the exact Kernel/selection contract, model inventory and runtime configuration.

Memory admission is a heuristic. It cannot guarantee execution on arbitrary RAM/VRAM, protect against all host OOM events, or make a model's weights fit by chunking the prompt. Quantization/model selection and sufficient actual capacity are still required. Flash Attention/KV quantization are not forced on unknown hardware.

- HTTP response cleanup belongs only to the reading thread; the timer performs socket shutdown only.
- A timed-out endpoint enters a 30-second cooldown shared through its data directory across Worker instances and models; explicitly configured peer endpoints remain eligible.
- The first qualification request allows at most 60 seconds, subsequent scenarios 30 each, within one 240-second default total. Existing lower runtime/configured limits still apply. No separate model preload is added. Administrative qualification may wait through the cooldown within its total budget before testing the next model; interactive jobs never sleep through it.
- Per-case evidence preserves partial successes, timing, generated/thinking character counts and bounded exception types. A cooling endpoint is SKIPPED, never a semantic FAIL or PASS.

## Multiple machines

Existing topology remains:

- Each machine runs a separately named worker with a unique node_uid and a properly scoped worker token.
- The control-plane lease queue distributes independent jobs.
- Additional workers increase throughput; they do not combine RAM to execute one indivisible model.

This release also supports an explicit Ollama endpoint failover list. The same requested model can run on an explicitly configured peer when the first endpoint is unavailable. This is remote inference and sends the bounded prompt to that endpoint.

Merge this OPTIONAL fragment into engine/config.json, retaining all existing credentials:

~~~json
{
  "ollama_fallback_endpoints": [
    {"url": "http://YOUR_TRUSTED_LAN_HOST:11434", "memory_budget_mb": 4096}
  ],
  "local_runtime": {
    "max_context": 2048,
    "num_batch": 32,
    "num_thread": 4,
    "keep_alive": "5m",
    "max_call_seconds": 60
  },
  "kernel_budget_seconds": 75,
  "kernel_planner_mode": "auto"
}
~~~

The URL and memory figure are placeholders, not discovered hardware facts. Use only a trusted reachable endpoint and its measured budget. The peer needs the requested model installed and its own single-model/concurrency limits. HTTPS/protected networking is needed outside a trusted local network. No peers are discovered automatically; no model is downloaded by the patch.

The inference deadline is shared across the endpoint attempts. Metadata records the actual model and an endpoint fingerprint. Endpoint failover was tested against local HTTP fixtures, not a real second machine.

## Supplier decision semantics

- Report each returned supplier, confirmed purchase volume/count, recorded receipt completion and ETA/ATA sample.
- A recorded high-risk trade case triggers follow-up priority, not a claim that the supplier caused the risk.
- Zero recorded receipts do not prove late delivery; zero known high-risk cases do not prove good performance.
- Do not rank quality by gross purchase volume, mixed-unit open-quantity ratios, or a fabricated weighted score.
- Portfolio coverage is explicitly bounded to five suppliers by confirmed spend unless explicit refs are supplied.
- Supported named periods are one through twelve previous months. Unsupported periods are blocked rather than silently changed.
- Trade signals may come from a different time/sample scope than purchase totals; the answer says so.
- Optional case drill-down validates supplier_id before using case observations.
- Attached entities take precedence over unrelated page context.
- Selected trade cases are filtered by their server-resolved IDs. Missing/ambiguous case scope cannot become another case's answer.
- Shipment impact continues to distinguish potential exposure from proven causal dependency.

## RAG foundation

- Local SQLite FTS5, lexical retrieval by default; interactive queries load no embedding model.
- Streaming top-k fallback when FTS5 is unavailable.
- Total retrieved text budget and bounded document/chunk size.
- Scope = workspace_id:company_id.
- Legacy unscoped rows are retained but never automatically exposed to a tenant.
- Incremental checksums skip unchanged documents; failed embedding reindex keeps the last valid document.
- Persian/Arabic yeh and kaf variants are normalized consistently for query and indexed terms; returned excerpts retain original text.
- Optional embeddings use batches of two with keep_alive=0 and the same endpoint lock, for offline indexing only. This version does not use those vectors for semantic reranking.
- RAG remains disabled by default. Only index a corpus explicitly approved for every user who can access that company context. Per-document/user ACL ingestion, revocation, deletion synchronization, malware scanning and retrieval-quality evaluation remain a production-RAG gate.
- Do not index worker config files, credentials or logs containing secrets. Documents are untrusted knowledge, never authorization or current ERP financial truth.

Example indexing of an approved corpus:

~~~text
python engine/rag.py index PATH_TO_APPROVED_CORPUS --db engine/data/rag.sqlite3 --workspace-id W --company-id C
~~~

## Evidence and validation

The regression suite includes actual Worker-class integration against controlled HTTP endpoints, stream truncation/OOM/error/slow-byte cases, peer failover, local lock contention, model identity, semantic rejection, scoped retrieval and the reported portfolio tie. These are executable transport/behavior tests, not a live local-model benchmark.

Synthetic four-supplier input measurement:

| Measurement | Bytes |
| --- | ---: |
| Old synthesis system + user message contents | 8,466 |
| New synthesis system + user message contents | 650 |

This is about 92.3% fewer UTF-8 message-content bytes in that fixture, using identical questions and four synthetic supplier rows. This excludes HTTP framing, output-schema bytes and generation. It is not a measured speedup or RAM/VRAM saving. Real model tokenization, cold load, generation speed and RAM/VRAM usage must be measured in the user engine. The v10.10.2 package includes that measurement script and JSON result. v10.10.3 retains this prompt contract.

The new preflight uses the production decision builder, prompt, schema and validator for three scenarios. It does not tell the model which ID is the expected answer. It records actual model/endpoint and available timings, and reports degraded capability separately from install integrity. These three cases are a qualification smoke, not a broad semantic benchmark.

Local environment: Python and Node available; Docker, Ollama, PHP and PowerShell executables unavailable. Therefore this artifact is LOCAL-VALIDATED. The installer stages the exact candidate outside the target project, builds and tests it before source mutation, saves original file bytes and model route, then applies the payload and checks the running image. The previous image is retained for rollback. The supplied target log validates v10.10.2 installation but no model; v10.10.3 target execution is still pending. See `30-STREAM-RECOVERY-20260911.md`, `28-LOW-MEMORY-INSTALL-FA.md` and `29-LOW-MEMORY-TEST-REPORT-FA.md` for commands, observed results and remaining gates.

## File map

| File | Responsibility |
| --- | --- |
| engine/local_inference.py | Capacity, bounded streaming, endpoint failover, locks |
| engine/provider_gateway.py | Explicit model identity, provider isolation, classified failure propagation |
| engine/agent_intelligence_kernel.py | Read scopes, bounded planning, compact synthesis, route expiry |
| engine/decision_brief.py | Grounded findings, comparisons, selection schema, deterministic rendering |
| engine/agent_kernel_smoke.py | Production-equivalent three-scenario qualification and route persistence |
| engine/runtime_diagnostics.py | Redacted runtime/model/capacity report |
| engine/rag.py | Scoped incremental lexical document retrieval |
| engine/worker.py | Runtime integration and scoped RAG consumption |
| engine/compose.yaml | Explicit Ollama concurrency/load limits |
| engine/Dockerfile | Include new runtime modules |
| tests/test_low_memory_runtime.py | Behavioral and HTTP integration regressions |
| tests/test_chunked_inference_recovery.py | Chunked cancellation, shared cooldown, CLI qualification evidence |

## Continuation of the accepted roadmap

1. On-target install/build/model qualification and three product flows: supplier comparison, selected trade risk, shipment impact.
2. Measure cold and warm latency, provider failure codes and ERP reconciliation. Do not publish a semantic PASS merely because installation completed.
3. MVP C: Role Brief / Intelligent Home, after the accepted semantic gate.
4. Expand Operator coverage using registered capability primitives and existing Proposal/Approval; maintain a coverage matrix and adversarial evaluations.
5. Forecast quality: acquire longer real time series, define targets and run rolling backtests against naive/seasonal baselines before promising accuracy.
6. Production RAG: ACL-aware ingestion, freshness, deletions, retrieval/generation evals; semantic reranking only if measured gains justify its cost.

## Research trace

Relevant sections were inspected; this is not a claim to have read every page of every attached book.

- A Practical Guide to Building Agents, model choice/evaluation, tools, bounded run loop and single-agent orchestration.
- The Developer's Playbook for LLM Security, prompt injection, excessive agency and context/resource exhaustion.
- Hands-on RAG for Production, chunking, access control, retrieval evaluation and hybrid retrieval tradeoffs.
- Building LLM Powered Applications, orchestration, model evaluation and application-level grounding.
- Machine Learning Infrastructure and Best Practices for Software Engineers (EPUB), testing versus evaluation and ML lifecycle controls.
- Innovations in Optimization and Machine Learning, optimization/evaluation framing. This is not used as proof that an LLM will fit a particular device.
- OWASP Top 10 for Agentic Applications 2026, least privilege, goal/tool boundaries, memory/context poisoning and cascading failures.

Primary current implementation references, accessed 2026-09-10:

- [Ollama FAQ: memory, concurrency, residency and KV cache](https://docs.ollama.com/faq)
- [Ollama chat API: format, metrics, done/done_reason](https://docs.ollama.com/api/chat)
- [Ollama thinking controls](https://docs.ollama.com/capabilities/thinking)
- [Ollama structured outputs implementation documentation](https://github.com/ollama/ollama/blob/main/docs/capabilities/structured-outputs.mdx)
- [LangGraph workflow/agent patterns](https://docs.langchain.com/oss/python/langgraph/workflows-agents)
- [llama.cpp server: slots, context and concurrency](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md)
- [Verified ERPSMART baseline](https://github.com/AmirAbedini78/excel-dev/commit/7415f1471573cce6e398317069567894a7209c4a)

No new orchestration framework, model training dependency or vector service was added. These references inform design choices; observed tests determine this release's claims.
