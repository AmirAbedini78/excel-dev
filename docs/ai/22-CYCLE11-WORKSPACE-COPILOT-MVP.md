# Cycle 11 — Workspace Copilot MVP

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`
Baseline: `2eefe9fa181b2d705935772bc026a19b91f025f5`

## Why this cycle exists

Live Cycle 10 proved that the Sidecar and deterministic `@` search work on a company that already has data, but also exposed three product-level gaps:

1. `@` was scoped to the currently active company, so an empty company looked like an empty ERP even when the same workspace had useful data elsewhere.
2. conversation identity was technically company-scoped but the Sidecar had no explicit conversation navigator, so prior chats were easy to lose after navigation/company changes;
3. the mention UI was a flat list of Entity types, which becomes noisy as the ERP grows.

Cycle 11 turns the Sidecar from a page helper into the first presentable **workspace-level Business Copilot** while preserving company-level execution isolation.

## Product contract

### 1. Global Workspace Entity Browser

Typing `@` searches every active company inside the current workspace, subject to the user's existing module/RBAC permissions.

The global browser does **not** grant authority. Selecting a result from another company switches only the Copilot conversation scope to that company before the Entity is attached. The ERP page itself is not silently switched and current-page context is not injected when page-company and chat-company differ.

P0 Entity coverage in this cycle is expanded to 17 first-class business types:

- Company
- Customer
- Supplier
- CRM Contact
- CRM Opportunity
- CRM Activity / Follow-up
- Sales Document
- Sales Delivery
- Purchase Document
- Trade Case
- Shipment
- Item / Service
- Warehouse
- Inventory Receipt
- Accounting Voucher
- Cash / Bank Account
- Check

This does not mean every database table is exposed as a mention. The contract is the opposite: business entities are exposed intentionally through typed providers, with server-side workspace/company/RBAC re-resolution. The list will grow by domain without turning raw tables into authority.

Every search result includes canonical `company_id` / `company_name` metadata from the server.

### 2. Hierarchical Mention UX

The `@` browser is organized as collapsible top-level business domains:

- Organization & Companies
- Parties & CRM
- Purchase & Sales
- Trade & Logistics
- Items & Inventory
- Finance

Each category contains Entity-type subgroups and the result count. Bare `@` keeps categories collapsed; a text query automatically opens the first matching domain. Company badges remain visible on every result.

### 3. Company-Scoped Conversation Hub

The Sidecar now has explicit selectors for:

- **Chat Company**
- **Conversation**
- **New Conversation**

Conversation state is stored per user + workspace with a per-company conversation map. Legacy Cycle 10 browser state is migrated once. If local state is missing, the Sidecar restores the latest server-side conversation for the selected company.

This gives the intended behavior:

- chats between Company A and Company B remain separate;
- the same Sidecar remains available everywhere in the ERP;
- switching page/module does not make previous company conversations disappear;
- repeated `@` use is supported in every message.

### 4. Safe cross-company browsing model

Cross-company search is allowed within the same workspace, but execution stays one-company-at-a-time.

When an Entity from another company is selected:

1. Copilot scope changes to that Entity's company;
2. existing attached refs from the previous company are cleared;
3. that company's latest conversation is restored;
4. the selected Entity is attached;
5. current page context is excluded if the page belongs to a different company.

This avoids accidental mixed-company actions while still giving management workspace-wide access to data discovery.

### 5. Grounded quick prompts

The composer exposes four editable templates, never auto-executed:

- CEO Brief
- Trade Risk
- Inventory Risk
- Cash & Collections

They are shortcuts into the existing grounded tool/agent stack and do not create a new authority path.

## No heavy infrastructure in this cycle

- no DB migration;
- no Worker source mutation;
- no vector DB / RAG change;
- no graph DB;
- no new permission model;
- no cross-workspace search;
- no automatic cross-company execution.

## Live acceptance gate

Cycle 11 does not close until all of these pass in the browser:

1. In a company with no operational data, type bare `@`; category headers and Companies must still appear.
2. Search for an Entity that exists only in another company; it must appear with its company badge.
3. Select that Entity; Copilot company scope must change while the ERP page remains where it is.
4. The selected Entity must attach and Quick Preview must work under the new scope.
5. Send a message, navigate to another module, reopen the Sidecar, and confirm the conversation is restored.
6. Use `@` again in the same conversation and attach a second Entity from the same company.
7. Change the Chat Company and confirm a different conversation history is shown.
8. Return to the first Chat Company and confirm its prior conversation returns.
9. Confirm current-page context is not injected when page company != chat company.
10. Confirm no result leaks outside the current workspace or beyond existing RBAC/module permissions.

## MVP vertical immediately after this gate

The next implementation cycle moves from interaction infrastructure to the target commercial wedge: **Foreign Trade Operations Intelligence**.

P0 management questions to support deterministically:

- Which trade cases are most likely to miss ETA / customs / customer delivery commitments?
- Which goods are exposed to FX/Landed-Cost variance and how much margin is at risk under rate scenarios?
- Which inbound parts and warehouse positions threaten an open customer commitment?
- Which supplier / shipment / customs bottleneck is creating the largest cash and time exposure?
- What are the five actions the CEO / trade manager should take today?

Planned first engine primitives:

`Trade Exposure Snapshot → FX Scenario → Landed Cost Variance → ETA/Delay Risk → Stock Coverage → Customer Commitment Risk → Prioritized Executive Brief`.

The first vertical MVP remains recommendation/proposal-first. Low-risk automation can be promoted later through the existing approval/risk contracts.
