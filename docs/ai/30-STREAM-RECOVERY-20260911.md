# v10.10.3 — Stream recovery from the supplied target log

Status: **LOCAL-VALIDATED**. This patch is based on the exact delivered v10.10.2 files. It changes no financial formulas, ERP schema, tool permissions, model files or approval contract.

## Evidence and diagnosis

The supplied `Pasted text(20260911-213325).txt` shows:

| Gate | Observed result on Windows / Docker |
| --- | --- |
| Candidate Docker build | PASS, 45.7 seconds |
| Repository tests | 268 PASS, 7.145 seconds |
| Installed files / Worker import and startup | PASS, 32 files verified |
| qwen3.5:0.8b qualification | provider_error after an llm_stream event |
| qwen3:1.7b qualification | deadline |
| gemma3:4b qualification | memory_admission, request blocked before inference |
| Overall model / product acceptance | DEGRADED / PENDING |
| MemAvailable before probes | 5070.078125 MB; not total physical RAM or VRAM |

The log does not include an exception traceback for the first model. It therefore cannot establish its exact failure cause or an OOM. Local reproduction against the delivered v10.10.2 transport found a matching failure mechanism: its timer called `HTTPConnection.close()` while `HTTPResponse.readline()` was consuming an HTTP/1.1 chunked response. Both paths mutate/close `response.fp`. The same socket deadline produced `AttributeError: NoneType.close`, which the gateway rendered as `provider_error`.

The before/after experiment sent identical incomplete chunked responses, with a 0.12-second deadline, three times per version. All three v10.10.2 attempts raised AttributeError; all three v10.10.3 attempts raised the expected classified deadline near 0.12 seconds. This reproduces a code defect consistent with the target log, not a measured target-model repair. See `evidence/chunked-before-after.json` in the package.

## Changes and tradeoffs

- The timer now only shuts down the socket. Response and connection cleanup run in the reading thread. The GET inventory transport receives the same correction.
- Failure evidence records request phase, elapsed time, first chunk/content time, chunk/content/thinking counts and HTTP status. Bounded exception types survive gateway wrapping. Raw prompts, model output and exception messages are excluded from this evidence.
- The first semantic qualification request permits up to 60 seconds, the two subsequent cases up to 30 each. All candidates share a 240-second default total, capped at 300; the runtime and smaller configured limits still win. No model qualifies unless all three cases pass. The first request is not automatically labelled cold: actual load duration is needed.
- Production synthesis also permits up to 60 seconds per attempt within a 75-second default Kernel budget, still capped at 120 for configuration. Existing lower explicit budgets remain effective. This allows more waiting on slower hardware; it is not a speed improvement. Synchronous ERP tool calls retain their own transport timeout.
- After a deadline, that endpoint cools down for 30 seconds before another model is submitted. The timestamp is shared by Worker instances using the same data directory. Other explicitly configured endpoints remain available within the original request deadline. The cooldown does not prove that Ollama stopped the cancelled work; no forced unload or daemon restart is performed.
- `local_runtime.endpoint_cooldown_seconds` can set 5–120 seconds; default 30. The cache stores only `until_epoch`, under `engine/data/ollama-<endpoint-id>.cooldown.json`. Failed disk persistence retains the in-process guard but cannot coordinate other processes. Stale entries expire and contain no model output or ERP data.
- Administrative qualification waits for the earliest endpoint cooldown to end before the next candidate, only if time remains in the same total budget. Interactive jobs fail over/fall back immediately. This prevents repeated failure of the first model from permanently starving qualification of the alternatives.
- Model memory admission and single-model/concurrency limits remain enabled. A larger model is not forced into memory to make a test pass.
- Diagnostics now include the daemon version and allowlisted `/api/ps` residency facts before and after qualification. Missing diagnostic fields are reported as unavailable, not guessed.
- Partial scenario passes remain in `model-qualification.json` when a later case fails. Requests skipped because the endpoint is cooling have status SKIPPED; they do not establish model incapability. Installer output includes the same bounded failure evidence.

## File map and next gate

| File | Change |
| --- | --- |
| engine/local_inference.py | Single-thread cleanup, failure details, shared endpoint cooldown |
| engine/agent_kernel_smoke.py | First/subsequent budgets, partial-case evidence and SKIPPED classification |
| engine/agent_intelligence_kernel.py | v1.6 identity and matching synthesis budget |
| engine/provider_gateway.py | Safe handling of endpoint_cooling for generic requests |
| engine/runtime_diagnostics.py | Version and model residency facts |
| tests/test_chunked_inference_recovery.py | Chunked HTTP, Worker, cooldown and qualification regressions |
| docs/ai/28-LOW-MEMORY-INSTALL-FA.md | Installation over v10.10.2 and recovery commands |
| docs/ai/29-LOW-MEMORY-TEST-REPORT-FA.md | Executed checks and explicit limits |

MVP-B remains open until the target model completes the selection contract and the three ERP/UI questions reconcile with real data. MVP C Role Brief / Intelligent Home follows the accepted semantic gate. This incident does not change North Star, establish general intelligence, prove forecast accuracy or activate production RAG.

References checked 2026-09-11: [Ollama usage metrics](https://docs.ollama.com/api/usage) defines final-event timings in nanoseconds; [Ollama FAQ](https://docs.ollama.com/faq) describes loading, residency and memory-dependent queueing. The runtime change itself was verified against the installed CPython 3.12 `http.client` and real loopback HTTP fixtures. Target Ollama/OS/Python image behavior still requires the new installer run.
