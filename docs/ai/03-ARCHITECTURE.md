# 03-ARCHITECTURE — معماری Canonical

## 2026-09-11 — v10.10.3 / Kernel v1.6: bounded stream recovery

Current package: `ERPSMART-v10.10.3-stream-recovery-local-validated.zip`, applied over the exact delivered v10.10.2 working state. Current implementation and incident evidence: **30-STREAM-RECOVERY-20260911.md**; install/rollback: **28-LOW-MEMORY-INSTALL-FA.md**; results: **29-LOW-MEMORY-TEST-REPORT-FA.md**.

The supplied Windows log proves v10.10.2 Docker build, 268 offline tests, file installation and Worker startup PASS. Its model gate is DEGRADED: qwen3.5:0.8b provider_error, qwen3:1.7b deadline, gemma3:4b memory_admission. This does not establish an OOM for the first model. A reproducible cross-thread HTTP chunk cancellation bug is fixed in v1.6; first-request allowance, shared endpoint cooldown and redacted per-case evidence are included. v1.6 remains LOCAL-VALIDATED until tested on that engine. MVP-B semantic acceptance remains open; MVP C Role Brief / Intelligent Home follows that acceptance. Older dated entries below describe historical states.

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


## 1. Principle

ERPSMART نباید «Accounting UI + ChatGPT box» باشد.

معماری هدف:

```text
                         Accountant
                             │
                             ▼
                    cPanel Web Application
 ┌─────────────────────────────────────────────────────────┐
 │ Auth / RBAC / Workspace / Company                      │
 │ Accounting & Financial Core                            │
 │ MySQL System of Record                                 │
 │ AI Job Queue                                            │
 │ Tool Gateway                                            │
 │ Proposal / Approval / Audit                            │
 │ Realtime Job Observability                             │
 └───────────────────────┬─────────────────────────────────┘
                         ▲
                         │ outbound HTTPS
              ┌──────────┴───────────┐
              │                      │
              ▼                      ▼
       Local Worker A          Local Worker B
       Ollama / Python         Ollama / Python
              │                      │
              └──────────┬───────────┘
                         ▼
                  Future VPS/GPU
```

## 2. Trust boundaries

### Database
تنها Application/Tool Gateway به DB عملیاتی دسترسی دارد.

### LLM
LLM:
- SQL آزاد نمی‌زند.
- DB credentials ندارد.
- ERP IDs را اختراع نمی‌کند.
- مبالغ جاری را حدس نمی‌زند.
- Write را مستقیم Post نمی‌کند.

### RAG
RAG document context است؛ نه ledger.

### Worker
Worker عملیات را از طریق API کنترل‌شده cPanel انجام می‌دهد.

## 3. Hybrid intelligence path

### Fast deterministic path

برای سؤال‌های شناخته‌شده و محاسبات قطعی:

```text
Prompt
→ deterministic/semantic route
→ safe Tool
→ deterministic formatting
→ answer
```

LLM لازم نیست.

### General planned read

```text
Prompt
→ constrained Planner
→ validated multi-step plan
→ Tools
→ derived deterministic calculations
→ optional LLM explanation
```

### Deep analysis

```text
Prompt
→ collect grounded evidence
→ deterministic core analysis
→ constrained qualitative LLM enhancement
→ safety validation
→ fallback if unsafe/timeout
```

### Mutation

```text
Prompt
→ Planner
→ resolve entities
→ validate
→ Proposal
→ Approval/Risk Policy
→ deterministic execution
→ verify
→ audit
```

## 4. Current Worker guard stack

در Snapshot v9.3 ترتیب نصب Runtime:

```text
Safe Deep
→ Guarded Invoice Agent
→ Grounded/Parameterized Read
→ Adaptive Read Plan Cache
→ Constrained Workflow Planner
→ Accounting Action Orchestrator
→ Financial Intelligence
→ Forecast/Risk/Anomaly
→ Proactive Accounting Agent
→ Model Provider Gateway
→ Commercial Hardening (last wrapper)
```

Commercial Hardening هیچ منطق مالی جدیدی ندارد. وظیفه آن enforce کردن قرارداد release روی نتیجه همه مسیرهاست: read-only/proposal-only، secret redaction، end-to-end latency، actual Tool/model observability و fail-closed metadata contract.

### Live terminal metadata boundary

```text
redacted terminal result_json
→ authenticated liveJobStateForUser
→ same normalized fields for SSE and Polling
→ browser final renderer
```

در v9.3.0.1 فیلد redacted `commercial_hardening` همراه `mode/model/metrics` از endpoint زنده عبور می‌کند. SSE و Polling هر دو از یک renderer استفاده می‌کنند و باید بدون refresh با رندر PHP پس از refresh از نظر route، model، latency budget و risk هم‌معنا باشند.

v9.3.0.2 همین boundary را برای blocked/fallback attempt observability کامل می‌کند:

```text
persisted tools_used / tools_attempted (names only)
persisted attempted_metrics (numeric allowlist only)
→ server allow-pattern + unique + max 32
→ authenticated owner-scoped live payload
→ text-only SSE/Polling renderer + escaped PHP reload renderer
```

Metric allowlist فقط `first_chunk_seconds`، `elapsed_seconds`، token counts/durations را می‌پذیرد. Tool arguments، Tool results، call IDs و trace details حساس از این boundary عبور نمی‌کنند.

Proposal descriptorها به generic LLM loop داده نمی‌شوند. فقط Guarded Invoice Agent و Accounting Action Orchestrator پس از grounding قطعی می‌توانند Proposal Tool مشخص را مستقیم فراخوانی کنند.

## 5. Tool contract

Toolهای Read می‌توانند مستقیم اجرا شوند.

Toolهای Mutation در حالت فعلی Proposal هستند.

Server باید:
- workspace/company ownership را validate کند.
- identifier و status را allowlist کند.
- dates را normalize/validate کند.
- idempotency را enforce کند.
- audit بنویسد.

### Terminal recovery contract

```text
Worker complete/fail
→ server transaction commits once
→ lost response may retry with same node + lease secret
→ same terminal state acknowledged as replay for 24h
→ opposite terminal state rejected
```

Proposal insert نیز با unique idempotency key و atomic upsert انجام می‌شود. Retry هرگز مجوز Approval را bypass نمی‌کند و اجرای Proposal همچنان با row lock و transaction است.

## 6. RAG boundary

RAG مناسب:

- قوانین/بخشنامه
- قرارداد
- رویه داخلی
- راهنما
- policy
- اسناد غیرساختاریافته

RAG نامناسب برای حقیقت عددی جاری:

- مانده
- فروش
- خرید
- مقدار فاکتور
- تراز
- وضعیت سند
- موجودی قطعی

## 7. ML/Forecast boundary

LLM Forecast Engine نیست.

```text
Historical financial series
→ feature engineering
→ statistical/ML forecast
→ confidence/error
→ risk/anomaly rules
→ LLM explanation
```

## 8. Scale strategy

فعلاً Job-level parallelism.

در آینده بدون شکستن Tool Contract:

- VPS/GPU worker
- specialized model service
- queue service
- Qdrant/vector service در صورت نیاز واقعی
- Ray/vLLM در scale بالا

## 9. Framework adoption policy

### LangGraph
فقط وقتی durable multi-step/resume/HITL پیچیدگی custom loop را واقعاً بالا برد.

### Hermes
فقط به‌صورت Adapter روی Tool Gateway محدود؛ نه shell/SQL unrestricted.

### FastRAG/Qdrant
وقتی حجم Corpus و evaluation نشان دهد retrieval فعلی کافی نیست.

### Fine-tuning
بعد از داشتن حجم مناسب Interaction تاییدشده:
Prompt → Plan → Tools → Approval/Correction → Outcome.

## 10. v10 Module architecture

از v10، Platform علاوه بر Domain/AI contract، یک Module Kernel دارد:

```text
Workspace
  → Module Registry
      → enabled / disabled
      → dependencies
      → pages/routes/menu
      → permissions
      → schema/assets/API
      → AI tools/RAG/events/background jobs
```

Rule: Module غیرفعال نباید در navigation یا route قابل استفاده باشد و در Cycleهای بعد AI Tool/Background Job آن نیز فقط در حالت فعال expose می‌شود.

`workspace_modules` منبع وضعیت فعال/غیرفعال هر Workspace است. `ModuleRegistry` تنها Catalog/Dependency contract را نگه می‌دارد؛ Module Center مدیریت این وضعیت را برای Platform Admin انجام می‌دهد.

Target AI runtime بعدی:

```text
Model Provider Gateway
  → Ollama local primary
  → OpenAI-compatible fallback/alternate
  → task/provider policy
```

Business-critical calculations و domain execution مستقل از Provider باقی می‌مانند.

## 5. v10 Model Provider topology

```text
cPanel Queue
   ├── Local Worker: local_first → Ollama → cloud fallback
   └── Optional always-on Worker: cloud_only → OpenAI-compatible API
```

Provider routing changes only LLM transport. Current business facts still come from Tool Gateway; financial mutations still require Proposal/Approval and deterministic server execution. Remote cloud endpoints require HTTPS. Cloud credentials stay in local gitignored runtime config.

## 11. ERPSMART Intelligence Platform architecture — 2026-08-31

The v9.3 trust boundaries and v10 Domain/Module architecture remain valid. The next layer is a pervasive Copilot architecture, not a replacement for the operational core.

```text
                         ERPSMART UI
                             │
       Sidecar ───── Intelligent Home ───── Analysis Workspace
                             │
                       Copilot Gateway
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
   Context Engine      Identity/Policy       Role UX Profile
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                     Intent Interpreter
                             │
                    Capability Retriever
                             │
                    Planner / Supervisor
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
      Skills                Tools             Engines
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                       Policy Engine
                             │
                      Execution Engine
                             │
                    Verify / Compensate
                             │
                      Experience Store
                             │
                          Evals
```

Detailed canonical contract: `19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md`.

## 12. Universal Entity and Context boundary

Cycle 8 r1 established the principle that the browser may send a typed pointer while the server owns canonical resolution. This becomes a registry contract:

```text
Untrusted typed ref / current page / selected rows
                 ↓
          AiEntityRegistry
                 ↓
          AiContextResolver
                 ↓
 workspace + company + module + RBAC
                 ↓
       Context Envelope v2
                 ↓
       Capability / Tool layer
```

Context is not authority and is not a cached fact snapshot. Every business read/mutation re-enters the Domain/Tool authorization boundary.

P0 uses a Relation Registry over the relational operational DB. A graph database is deferred.

## 13. UI composition boundary

Global Copilot must be integrated once at the application shell level and reused across modules. Module pages provide typed page/selection handles; they do not embed independent chat implementations.

```text
Application Shell
  → BusinessCopilot component
      → composer
      → thread
      → entity search/chips
      → preview/rich cards

Module Page
  → optional page-context provider
  → optional selection provider
```

The existing dedicated `AiModule` remains as a Command Center/Analysis Workspace path and shares lower-level components/contracts with the Sidecar.

## 14. Entity provider boundary

A universal provider exposes search/resolve/preview/relations/deep-link and capability metadata while delegating truth to existing Domain services.

Examples:

```text
party.customer   → canonical acc_parties / Accounting/CRM truth
item             → canonical item master / Inventory truth
sales.document   → SalesDomain
trade.case       → TradeDomain
shipment         → TradeDomain
warehouse        → InventoryDomain
finance.voucher  → Accounting domain
```

Do not create AI-specific duplicate master tables.

## 15. Capability and Skill boundary

Tool = deterministic typed primitive.

Skill = versioned business workflow that composes Tools/Engines and declares role/entity/risk/eval contracts.

With a growing catalog:

```text
Intent + Entity Context
→ Capability Retriever
→ bounded Skills/Tools
→ exact deterministic Skill fast path OR constrained Supervisor
```

The whole Tool catalog is not injected into every model prompt.

## 16. Workflow grammar and orchestration

Canonical workflow primitives:

```text
Resolve Read Filter Aggregate Compare Join Rank Calculate Predict Detect
Recommend Propose Approve Execute Verify Notify Wait Branch Loop Escalate
```

P0 maximizes one Supervisor/Manager and deterministic Domain Engines. Multi-agent execution is deferred until eval proves need; if introduced, a Manager Pattern preserves one user-facing assistant.

## 17. Model Router boundary

```text
Deterministic
→ Small Local Model
→ Strong Local Model
→ Cloud Reasoning Model
```

Accuracy/eval baseline precedes latency/cost optimization. Model choice never changes authorization, Domain calculations or Proposal/Approval semantics.

## 18. Memory / Experience boundary

Separate stores/concepts:

```text
Conversation Memory
User Preference Memory
Business Experience Memory
Workflow/Skill Memory
Knowledge Memory
```

Learning path is offline/controlled:

```text
Trace → Outcome Eval → Human Feedback → Dataset → Skill Candidate → Eval → Promotion
```

No raw user action updates production policy automatically.

## 19. Security additions for agentic scope

Existing v9.3 controls remain frozen. Universal Copilot adds explicit protection for:

- Goal hijack and prompt-injection boundaries;
- Tool misuse / action-level authorization;
- identity/privilege abuse;
- memory/context poisoning;
- agentic supply-chain and code-execution exposure;
- cascading failures via budgets/timeouts/step limits;
- human-agent trust exploitation via clear Fact/Prediction/Recommendation/Proposal labeling;
- future inter-agent schema/authentication before multi-agent is enabled.

No Context, Memory or external document can increase authority.

## 20. Performance additions

- entity search/preview are deterministic and LLM-free;
- search results are bounded/debounced;
- Context stores refs rather than large snapshots;
- capability retrieval bounds model descriptors;
- exact reads keep deterministic fast paths;
- large analyses move to Analysis Workspace;
- Sidecar assets are shell-level and not copied/loaded repeatedly by each module.

## 21. Agent Intelligence Kernel — current orchestration contract

The Agent Intelligence Kernel is the current concrete implementation of the existing canonical `Intent → Capability Retrieval → Supervisor → Tools/Engines` direction. It is not a replacement for the North Star.

```text
Natural-language Goal
       ↓
Validated Entity/Context boundary
       ↓
Capability family
       ↓
Server-generated Action Menu
       ↓
Planner model selects opaque action_id
       ↓
Server maps action_id → approved read Tool + typed arguments
       ↓
Fresh Tool result / compact Observation
       ↓
Re-plan until sufficient evidence or budget exhausted
       ↓
Decision Evidence
       ↓
Reasoning model → validated business answer
       ↓
Deterministic decision-first fallback if model output is invalid
```

Authority remains server-side. The planner cannot invent Tool names, SQL, ERP IDs, amounts, dates, quantities or write arguments. Tool budget, planner budget and evidence budget are explicit. Proposal descriptors are excluded from the generic kernel.

Runtime source: `engine/agent_intelligence_kernel.py`. Real model capability smoke: `engine/agent_kernel_smoke.py`. The legacy `engine/business_supervisor.py` remains temporarily as compatibility for paths not yet promoted, and the Agent Intelligence Kernel is installed after it so supported capabilities supersede the fixed-report path.

Numerical prediction remains outside this kernel and belongs to statistical/ML engines. Document knowledge remains outside current ERP truth and belongs to the future permission-aware RAG subsystem.
