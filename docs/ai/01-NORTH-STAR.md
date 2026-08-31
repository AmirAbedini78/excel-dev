# 01-NORTH-STAR — هدف ثابت ERPSMART

## تعریف یک‌خطی

**ERPSMART یک AI-native Business Operating System برای شرکت‌های بازرگانی/واردات/توزیع B2B است: ERP و Domainهای عملیاتی Source of Truth می‌مانند و ERPSMART Business Copilot به‌عنوان لایه هوشمند همیشه‌حاضر، Role-Adaptive، Cross-module و Guarded برای جستجو، تحلیل، تصمیم، اقدام و پایش روی آن‌ها عمل می‌کند.**

نام معماری:

```text
ERPSMART Intelligence Platform
```

لایه‌ای که کاربر می‌بیند:

```text
ERPSMART Business Copilot
```

## Vision بلندمدت

ERPSMART نباید فقط مجموعه‌ای از فرم‌های ERP با یک Chatbox باشد. هدف نهایی ترکیب سه لایه است:

```text
Trusted Business System / ERP
        ↓
Unified Entity + Relationship + Capability Layer
        ↓
Business Copilot + Guarded Agent Workforce
```

ERP مسئول ثبت حقیقت، قواعد، transaction و audit است. AI مسئول فهم زبان طبیعی، یافتن Context مناسب، ترکیب قابلیت‌ها، تحلیل، پیشنهاد و orchestration است. اجرای business mutation از Domain service و Policy عبور می‌کند.

Platform می‌تواند در آینده Finance، Sales/CRM، Inventory، Procurement، Trade/Logistics، Production، HR، Project، Service و Marketing را پوشش دهد؛ ولی عمق هر Domain بر اساس Vertical و شواهد واقعی بازار تکمیل می‌شود، نه با ساخت ده‌ها Module سطحی قبل از Pilot.

## Vertical و بازار اول

اولین ICP:

- شرکت بازرگانی / Importer
- Distributor / Wholesaler
- شرکت B2B با جریان قوی Procurement → Trade → Inventory → Sales → Finance

Wedge رقابتی:

```text
Trade Resilience
+ Commercial Intelligence
+ Cross-module Guarded Automation
```

## چهار نقش Business Copilot

1. **Assistant** — پیدا کردن، باز کردن، توضیح دادن و خلاصه‌سازی داده.
2. **Analyst** — مقایسه، علت‌یابی، محاسبه اثر، تشخیص ریسک و Forecast با Engine مناسب.
3. **Operator** — آماده‌سازی و اجرای عملیات واقعی از طریق Tool/Policy/Approval.
4. **Autonomous Supervisor** — پایش مداوم، کشف Exception و اقدام محدود در چارچوب Policy.

Autonomy مرحله‌ای است. سیستم ابتدا قابلیت را با Human-in-the-loop اثبات می‌کند و فقط با Eval/Policy/Undo مناسب Agency افزایش می‌یابد.

## تجربه کاربری هدف

Business Copilot باید در جریان کار حضور داشته باشد:

```text
Global Sidecar
+ Intelligent Home
+ Analysis Workspace / Command Center
+ @ Universal Entity Mention
+ + Context/File
+ / Skill/Action
```

کاربر نباید برای هر سؤال از صفحه جاری خارج شود. Current Page برای Context آماده است ولی به‌صورت کور داخل Prompt تزریق نمی‌شود.

Role Experience جدا از Permission است: CEO، مدیر بازرگانی، مدیر مالی، فروش، خرید و انبار می‌توانند همان Platform را با اولویت و نمایش متناسب با نقش ببینند، بدون اینکه UI adaptation مجوز جدید ایجاد کند.

## قابلیت‌های تعریف‌کننده MVP جدید

### 1. Ask the Business

- زبان طبیعی روی داده‌های Business
- `@` Entity selection
- multi-entity context
- page/selection awareness
- navigation/deep links
- پاسخ Grounded با داده تازه

### 2. Business Intelligence

- Cross-module analysis
- compare / trend / KPI
- risk / anomaly / impact
- Customer/Supplier behavior signals
- Manager/Executive Brief
- Evidence قابل Drill-down

### 3. Guarded Business Operator

- intent → context/entity resolution
- Capability/Skill discovery
- constrained plan
- deterministic Tools
- Proposal/Approval/Risk Policy
- Verify + Audit
- Undo/Compensation در Domainهای پشتیبانی‌شده

### 4. Proactive Supervisor

- Watchers
- Exception prioritization
- business impact
- next-best action
- notification
- configurable low-risk automation after evidence

## معماری Capability، نه هزاران Workflow دستی

Tool = primitive deterministic capability.

Skill = capability کسب‌وکاری composed از Toolها/Engineها/Workflow primitives.

Workflow Grammar پایه:

```text
Resolve Read Filter Aggregate Compare Join Rank Calculate Predict Detect
Recommend Propose Approve Execute Verify Notify Wait Branch Loop Escalate
```

هدف این است که تعداد محدود و استاندارد از Primitiveها و Skillهای versioned بتوانند رفتارهای زیاد بسازند، نه اینکه برای هر جمله کاربر یک Route/Regex/Workflow اختصاصی رشد کند.

## مرز هوش

```text
Current structured facts       → Domain/SQL/Tools
Documents/policies             → RAG with access control/eval
Entity/context resolution      → Server registry + deterministic retrieval
Planning/interpretation        → LLM under constrained capability set
Forecast numeric output        → Statistical/ML engine
Execution                      → Deterministic Domain services
Authorization/approval/policy  → Server-side controls
Experience learning            → Trace → Eval → controlled promotion
```

## Orchestration direction

ابتدا Single Supervisor/Manager با Toolهای استاندارد و Domain Engineهای deterministic. Multi-Agent فقط زمانی وارد می‌شود که Eval نشان دهد Tool/Instruction complexity یا تخصص Domain واقعاً نیاز دارد. تجربه کاربر همچنان یک Business Copilot واحد باقی می‌ماند.

## Model direction

```text
Deterministic
→ Small Local
→ Strong Local
→ Cloud Reasoning
```

Routing بر اساس complexity، risk، latency، privacy، cost و capability است. Evals ابتدا baseline دقت را قفل می‌کنند؛ سپس برای Taskهای ساده‌تر مدل کوچک‌تر جایگزین می‌شود.

## Learning direction

Production Agent از هر رفتار کاربر مستقیماً «آموزش» نمی‌بیند.

```text
Trace
→ Outcome Eval
→ Human Feedback
→ Experience Dataset
→ Skill Candidate
→ Offline Eval
→ Promotion
```

Conversation، Preference، Business Experience، Workflow/Skill و Knowledge memory جدا هستند.

## تصمیم مهم فعلی

```text
بازنویسی کامل ERP قبل از Pilot                           ❌
Chatbot جدا از جریان کار                                ❌
یک Ask-AI button اختصاصی برای هر صفحه                    ❌
هزاران Workflow hard-coded                               ❌
Multi-Agent از روز اول                                   ❌
Graph DB/Lakehouse/RAG بزرگ در Critical Path              ❌

ERP/Domainهای واقعی + Universal Entity/Context Layer      ✅
Business Copilot همیشه‌حاضر                               ✅
Composable Tools/Skills/Workflow Grammar                  ✅
Role-Adaptive Experience                                  ✅
Cross-module reasoning                                    ✅
Guarded execution + Evidence + Evals                      ✅
48h increments که هر کدام Demoable باشند                  ✅
```

## چیزهایی که North Star نیستند

این‌ها implementation option/subsystem هستند، نه هویت محصول:

- Qwen/Gemma یا هر مدل خاص
- Ollama
- OpenAI-compatible provider
- LangGraph/Hermes
- Qdrant/vector DB
- Graph database
- specific caching strategy
- regex router
- frontend framework

هرکدام فقط با نیاز و Eval وارد می‌شوند.
