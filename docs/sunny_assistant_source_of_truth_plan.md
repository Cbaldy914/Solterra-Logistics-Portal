# Sunny Assistant Source of Truth Plan

Last updated: 2026-02-16  
Project: Solterra Logistics Portal - Sunny AI Assistant  
Primary objective: Turn Sunny into a reliable, context-aware logistics copilot that answers natural questions correctly without requiring users to phrase requests in a specific way.

## How To Use This Document

This file is the canonical plan and memory anchor for Sunny improvements.

When resuming work after context compaction:
1. Read this file first, top to bottom.
2. Continue from the first incomplete item in the active phase.
3. Update the status log at the bottom after each meaningful change.
4. Do not change architecture direction without updating the "Decisions" section.

## North Star Goal

Sunny should:
1. Correctly interpret user intent from natural language (including follow-ups like "those modules").
2. Select and execute the right tools automatically.
3. Return accurate, account-scoped answers for all supported roles.
4. Explain results clearly with numbers, assumptions, and next best actions.
5. Fail gracefully with actionable clarification when data or permissions are missing.

## Success Criteria (Definition of Done)

Sunny is considered "done" for this initiative when all are true:
1. First-answer correctness >= 90% on production-like prompt suite.
2. Follow-up reference resolution >= 90% ("those", "that project", "same warehouse").
3. Tool selection accuracy >= 95% against labeled test prompts.
4. No role-based false denials for valid roles (including `customer_admin`).
5. No cross-account data leakage in any tested path.
6. "Asked it the right way" complaints reduced by >= 70% from baseline feedback.

## Non-Negotiable Guardrails

1. Tenant isolation first: never return data outside user account scope.
2. Read-only DB operations for Sunny tools.
3. Deterministic, auditable tool execution (log selected tool + outcome).
4. Never expose SQL errors/stack traces to user-facing responses.
5. Clarify once when intent is ambiguous; do not repeatedly bounce the user.

## Current Known Gaps (Baseline)

1. Role mapping gap:
   - `customer_admin` is missing in Sunny query allow-lists, causing false "can't access data" failures.
2. Intent routing gap:
   - Regex tool detection misses cost/value follow-ups.
   - Value questions are not consistently routed to cost/valuation logic.
3. Context gap:
   - Follow-up references rely on fragile carryover of previous tool results.
   - No robust conversation state for entities/timeframe continuity.
4. Data semantic gap:
   - Business metrics (inventory value, status semantics) are not centralized in canonical views/contracts.
5. Observability gap:
   - Limited structured telemetry for intent -> tool -> SQL -> response quality.

## Architecture Direction

Move from `regex router -> single tool guess` to:
1. Planner step (Gemini structured output) that returns:
   - intent
   - entities (project, warehouse, account, timeframe)
   - tools to call
   - confidence
   - clarification_needed
2. Executor step (strict backend allow-list + account scoping + read-only SQL).
3. Response composer step (clear summary, metrics, assumptions, next action).

## Phased Execution Plan

## Phase 0 - Reliability and Safety Foundation
Status: In Progress  
Priority: Critical

### Goals
1. Eliminate avoidable access failures.
2. Make current tooling stable enough for iterative upgrades.

### Tasks
1. Add `customer_admin` in all Sunny role allow-lists/config checks. (Completed)
2. Harden account resolution:
   - no ambiguous account selection by `LIMIT 1` without deterministic rule.
   - fail with explicit scoped error if account context cannot be resolved.
3. Standardize tool error envelopes: (In progress)
   - `success`, `error_code`, `error_message`, `actionable_next_step`.
4. Add structured logs per turn: (In progress)
   - user role
   - resolved account_id
   - selected tools
   - tool success/failure
   - row counts
   - latency

### Acceptance Criteria
1. `customer_admin` can successfully run inventory/project/delivery tools in same scenarios where `admin` can.
2. No silent account fallback behavior.
3. Every tool run is auditable in logs.

## Phase 1 - Intent Planner and Smart Tool Selection
Status: In Progress  
Priority: Critical

### Goals
1. Replace brittle regex-only selection with model-assisted planning.
2. Increase tool selection accuracy across broad phrasing.

### Tasks
1. Implement planner schema (JSON): (In progress)
   - `intent`
   - `sub_intent`
   - `entities`
   - `tools[]`
   - `confidence`
   - `needs_clarification`
   - `clarification_question`
2. Integrate Gemini planner call path for tool routing. (In progress)
3. Add deterministic fallback if planner fails: (In progress)
   - fallback heuristic with explicit low-confidence marker.
4. Add policy rules: (In progress)
   - if confidence < threshold and multiple likely entities -> ask one targeted clarification.
   - otherwise execute.
5. Add planner evaluation harness with labeled prompts.

### Acceptance Criteria
1. Tool-selection accuracy >= 95% on test suite.
2. Value/cost questions route to valuation/cost tools without prompt hacks.
3. Clarifications are concise and specific.

## Phase 2 - Conversation State and Follow-Up Resolution
Status: Pending  
Priority: High

### Goals
1. Make Sunny robust across multi-turn conversations.
2. Resolve pronouns and shorthand references reliably.

### Tasks
1. Introduce session state object:
   - `last_project_id`
   - `last_warehouse_id`
   - `last_account_id`
   - `last_timeframe`
   - `last_metric_context`
   - `last_result_signature`
2. Build reference resolver for phrases:
   - "those modules"
   - "that project"
   - "same warehouse"
   - "what about value/cost now?"
3. Add stale-context protection:
   - do not blindly reuse old context if conversation changed topics.
4. Persist/restore state with conversation history.

### Acceptance Criteria
1. Follow-up accuracy >= 90% on test suite.
2. No incorrect carryover when topic changes.

## Phase 3 - Data Semantic Layer and Query Contracts
Status: Pending  
Priority: High

### Goals
1. Make DB outputs easier and safer for Sunny to consume.
2. Standardize business metrics and field semantics.

### Tasks
1. Define canonical SQL views (or equivalent query-contract layer):
   - `v_account_inventory_current`
   - `v_account_inventory_value_current`
   - `v_project_delivery_summary`
   - `v_delivery_performance`
2. Centralize valuation formula:
   - inventory value = `cost_per_watt * wattage * quantity`
   - define fallback behavior when `cost_per_watt` missing.
3. Normalize status vocabulary and mapping table.
4. Create AI data dictionary:
   - field meaning
   - units
   - null interpretation
   - caveats
5. Add contract tests to ensure views match portal metrics.

### Acceptance Criteria
1. Same metric question returns consistent values across tools/pages.
2. Missing-cost cases are explicitly reported, never hidden.

## Phase 4 - Tool Surface Expansion
Status: Pending  
Priority: Medium

### Goals
1. Cover high-frequency business questions directly.
2. Reduce custom reasoning burden in prompt layer.

### Tasks
1. Add/upgrade tools:
   - `getAccountInventorySummary`
   - `getAccountInventoryValue`
   - `getInventoryAging`
   - `getExceptionsAndRisks`
2. Ensure each tool returns:
   - summary metrics
   - top breakdowns
   - drill-down links where possible
3. Add explicit export support metadata (CSV/PDF endpoints).

### Acceptance Criteria
1. Common operations questions can be answered without custom fallback logic.

## Phase 5 - Response Quality and UX Behavior
Status: Pending  
Priority: Medium

### Goals
1. Improve usefulness, clarity, and actionability of Sunny responses.

### Tasks
1. Response contract:
   - direct answer first
   - key metrics
   - assumptions/limits
   - next best action
2. Always include active scope in data answers:
   - account/project/warehouse/time range.
3. Improve page-aware quick prompts and suggestions.
4. Add graceful inability patterns:
   - "No results found" + one specific next step.

### Acceptance Criteria
1. User feedback indicates reduced ambiguity and fewer repetitive re-prompts.

## Phase 6 - Testing, Rollout, and Continuous Improvement
Status: Pending  
Priority: Critical

### Goals
1. Launch safely and measure impact.

### Tasks
1. Build evaluation suite from real user prompts and failures.
2. Add role-matrix tests (`user`, `admin`, `customer_admin`, `global_admin`).
3. Add red-team tests for cross-account leakage attempts.
4. A/B test old vs new Sunny:
   - answer correctness
   - clarifications needed
   - time to useful answer
5. Progressive rollout + rollback switch.

### Acceptance Criteria
1. KPI targets met for 2+ consecutive evaluation windows.
2. No critical security regressions.

## Data + Security Requirements (Always Enforced)

1. All non-global users must be scoped by resolved account_id.
2. Tool layer must enforce scope, not just prompt instructions.
3. Query layer remains read-only.
4. Sensitive fields excluded or masked where required.
5. Error handling must be user-safe and developer-actionable.

## Engineering Standards For This Work

1. Small, reversible PRs by phase.
2. Add tests with each behavior change.
3. Update this plan file after each milestone.
4. Keep architecture docs and tool contracts synchronized.

## Product Decisions (Resolved)

1. Account behavior:
   - One account per role (except `global_admin`).
   - Sunny must only answer within the authenticated account scope.
2. Ambiguity behavior:
   - If project is unspecified, default to portfolio-level results for the current account.
   - Sunny should not ask users to choose account in normal flow.
3. Latency behavior:
   - Explicit "thinking" UX is acceptable; prioritize correctness over aggressive timeout.
4. Numeric precision:
   - Use best judgment defaults, provide more precision when user asks.
5. Clarification threshold:
   - Engineering judgment; ask only when confidence is materially low or entity ambiguity is real.

## Risks and Mitigations

1. Risk: Planner adds latency.
   - Mitigation: cache entity resolution, parallelize safe tool calls, keep concise prompt context.
2. Risk: Over-clarification annoys users.
   - Mitigation: confidence threshold tuning and one-question cap.
3. Risk: Metric inconsistency across portal and Sunny.
   - Mitigation: canonical SQL views + contract tests.
4. Risk: Tenant scope bugs.
   - Mitigation: centralized scope enforcement + role-matrix tests.

## Immediate Next Actions (Execution Queue)

1. Implement Phase 0 role/account fixes.
2. Add structured tool execution logging.
3. Build Phase 1 planner JSON schema and wire initial planner call.
4. Add regression tests for:
   - generic inventory query
   - follow-up value query
   - `customer_admin` access scenario

## Decisions Log

1. Adopt planner-executor architecture as primary direction.
2. Keep Sunny read-only for database operations.
3. Prioritize correctness and scope safety over stylistic response polish.
4. Enforce strict account-scoped answers for all non-global roles.
5. Portfolio-level default when project is not specified.

## Status Log (Update Every Work Session)

2026-02-16:
1. Created source-of-truth plan document.
2. Baseline issues identified: role mapping, regex routing, follow-up context, semantic layer gaps.
3. Next work target: Phase 0 execution.
4. Product decisions captured from stakeholder:
   - strict account scope only
   - portfolio default when project unspecified
   - thinking latency acceptable
   - adaptive numeric precision
   - clarification threshold by engineering judgment
5. Phase 0 work started:
   - role allow-list updates in Sunny config/query executor
   - strict account-context resolver design + implementation in chat stream
   - structured request/tool telemetry scaffold added
6. Additional Phase 0 hardening completed:
   - report generation endpoint now enforces strict account-context resolution
   - account mapping ambiguity now fails closed for non-global roles
7. Phase 1 started:
   - added planner config (enabled/model/timeout/confidence/history turns)
   - integrated Gemini planner call returning strict JSON decision
   - added confidence-gated planner->tools routing with regex fallback
   - added one-question planner clarification bypass path (no extra model call)
8. Added inventory valuation capability for module value questions:
   - new `getInventoryValue` tool in `sunny-tools.php` using `cost_per_watt * wattage * quantity` on in-storage pallets
   - added priced/unpriced coverage metrics and project/wattage breakdowns in tool payload
   - expanded query allow-list to include `unassigned_module_items` for safe joins
   - wired dispatcher aliases (`getInventoryValue`, `inventory_value`, `inventoryvalue`)
9. Improved routing for "value of modules in storage" prompts:
   - planner allowed-tools now includes `getInventoryValue`
   - planner guidance explicitly maps module/inventory value intent to `getInventoryValue`
   - heuristic fallback now routes inventory+value questions to `getInventoryValue` (with inventory context), while preserving freight/accessorial routing to `getProjectCostAnalysis`
10. Tightened project-name resolution to preserve account scope:
    - added scoped project resolver helper in Sunny tools
    - updated POD/doc project-name lookup paths to use scoped resolver
11. Updated system prompt contract:
    - documented inventory valuation capability and `getInventoryValue` tool usage
    - clarified that freight/accessorial analysis is not a substitute for inventory module value requests
12. Improved project-cost tool entity resolution:
    - `getProjectCostAnalysis` dispatcher path now resolves project names to scoped project IDs before query execution
