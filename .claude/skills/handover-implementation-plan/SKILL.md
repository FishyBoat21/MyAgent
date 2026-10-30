---
name: handover-implementation-plan
description: Write comprehensive, execution-ready implementation plans designed for seamless engineering handovers. Use when the user asks for an implementation plan, technical plan, engineering handover doc, task breakdown for another developer, or execution blueprint for a feature, refactor, or system migration.
---

# Handover-Ready Implementation Plan

Generate technical implementation plans detailed enough that another engineer can execute them without needing extra clarification meetings, guesswork, or architecture reverse-engineering.

## When to Use

- A technical design or feature specification needs translation into an actionable engineering plan.
- The plan will be executed by another developer, junior engineer, contractor, or autonomous coding agent.
- A complex refactoring, migration, or multi-service architecture change requires zero-downtime sequencing.
- The user asks for a "handover doc", "implementation checklist", "execution plan", or "runbook".

## Core Principles of Handover-Readiness

1. **Explicit File Paths and Touchpoints:** Never say "update the database service." Specify `src/services/db/user-repository.ts` and the exact method signatures to modify or add.
2. **Deterministic Verification:** Every milestone must have a verifiable check (command to run, curl script, automated test command, or expected log line).
3. **Atomic, Committable Units:** Group steps so each task can ideally represent a single PR or atomic commit that passes CI/CD.
4. **Failure Modes & Fallbacks:** Include migration rollbacks, feature flag fallbacks, and boundary conditions upfront.

## Plan Structure to Produce

When invoked, generate the implementation plan following this standard structure:

### 1. Overview & Scope
- **Objective:** 1-2 sentences stating what is being built and why.
- **Success Criteria / Definition of Done (DoD):** Bulleted list of verifiable functional and non-functional requirements.
- **Out of Scope:** Explicitly what will NOT be done in this iteration.

### 2. Architecture & Contract Changes
- **Data Model / Schema Changes:** Migration scripts, tables, columns, indexes, types, and constraints.
- **API Contracts / Interfaces:** New endpoints, request/response JSON schemas, RPC methods, event schemas.
- **Environment & Config:** Required environment variables, secrets, feature flag definitions.

### 3. Step-by-Step Execution Sequence
Group the work into ordered Milestones (e.g., M1: Foundation/Data Layer, M2: Core Business Logic, M3: API/Integration, M4: UI/Client, M5: Cleanup).

For each step within a milestone, provide:
- **Files Modified / Created:** Exact relative paths.
- **Changes Required:** Clear technical explanation with pseudocode or interface definitions where helpful.
- **Dependencies:** What must be completed before starting this step.
- **Validation Step:** Concrete command (e.g., `npm run test:unit path/to/test.spec.ts`) or manual test check.

### 4. Testing & Verification Strategy
- **Unit & Integration Tests:** List of test cases and target coverage.
- **End-to-End / Manual Smoke Test Runbook:** Step-by-step reproduction path with realistic sample payloads.
- **Performance & Security Checks:** Rate limiting, auth enforcement, DB query inspection.

### 5. Deployment, Rollout & Rollback Plan
- **Migration Strategy:** Zero-downtime sequence (e.g., expand/contract pattern for DB columns).
- **Feature Flagging:** Flag name, default state, progressive rollout strategy.
- **Rollback Procedure:** Concrete, step-by-step instructions if deployment fails.

## Gotchas & Guidelines

- **Avoid Hand-Waving:** Don't write "implement error handling" or "add validations." Name the specific error codes (e.g., `409 Conflict`, `422 Unprocessable Entity`) and exact validation rules.
- **Preserve Existing Conventions:** If existing code patterns, linters, or libraries are visible in context, align the implementation plan strictly with those conventions.
- **Surface Blockers Early:** Explicitly call out any third-party credential requirements, team dependencies, or architectural prerequisites in a dedicated Prerequisites section before Step 1.
