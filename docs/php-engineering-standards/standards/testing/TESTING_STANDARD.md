# TESTING_STANDARD

**Maatify Testing Architecture and Regression Protection Standard**

## Standard Metadata

- **Standard ID:** `std-testing`
- **Standard Version:** `3.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`

This document establishes the canonical, repository-wide Testing Standard for the Maatify ecosystem. Its primary purpose is to protect implemented behavior from regressions and unintended damage during future development.

---

## 1. Core Principle

> Every externally observable behavior, critical workflow, and integration boundary MUST be protected by an appropriate end-to-end or system-level test. Where this protection is required, a change MUST NOT be considered complete when its correctness depends solely on unit-level verification.

> Every resolved regression MUST have a test capable of detecting recurrence, selected according to the actual defect boundary as detailed in Section 4.5. The narrowest useful recurrence-detecting test MAY be sufficient on its own only when the regression did not affect any behavioral boundary that independently requires System/E2E protection under this Standard. When such a boundary is affected, including externally observable behavior, a critical workflow, or an integration boundary covered by this Core Principle, the relevant System/E2E protection MUST also remain present; a narrow regression test alone is insufficient.

This is a mandatory engineering rule, not a suggestion.

---

## 2. Normative Language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in RFC 2119.

---

## 3. Testing Layers

The testing strategy MUST distinguish between the following layers:

### 3.1. Unit Tests

Unit tests verify isolated logic and components.

Their purpose includes:
- Logic correctness.
- Edge case handling.
- Deterministic isolated behavior.
- Fast developer feedback.

Unit tests alone MUST NOT be treated as sufficient proof for externally observable workflows where integration or system behavior matters.

### 3.2. Integration Tests

Integration tests cover actual collaboration between components or infrastructure boundaries.

Examples include:
- Service + Repository collaboration.
- Persistence behavior.
- Database interactions.
- Adapters and infrastructure.
- Serialization/deserialization.
- Framework integration.

While mocks or fakes MAY be useful at some test levels, a mocked dependency chain MUST NOT be described as end-to-end or system-level verification.

### 3.3. End-to-End (E2E) / System-Level Tests

System-level and E2E tests are defined by **behavioral boundary**, not by technology. E2E does NOT automatically mean browser testing.

The test MUST exercise the system through an externally meaningful or public entry point and verify the final observable result across the relevant real execution chain.

Examples:
- **API/Module:** `HTTP Request → Route → Handler → Service → Repository → Persistence → Response`
- **Standalone Library:** `Public API → Internal Implementation → Required Integration Boundary → Observable Result`
- **CLI Application:** `CLI Command → Application/Service Layer → Infrastructure → Exit Code/Output/State`
- **Admin/Web UI:** `Browser/User Action → Frontend → HTTP → Backend → Persistence/State → Final User-Visible Result`

Browser automation is REQUIRED only when the browser or UI itself is part of the behavior being protected.

### 3.4. Consumer Verification Harness

Every standalone reusable Package, and every Base Module intended to be extractable as a Package, MUST have a reproducible Consumer Verification Harness.

The Harness is external-consumer evidence, not merely another test suite run with the Package repository as the root project. It MUST:

- use a Composer root separate from the Package root or Base Module Artifact Root, and resolve and use the artifact as a Composer dependency
- exercise the artifact through its production PSR-4 autoload and documented public API or public contracts
- complete a realistic consumer workflow from input through the public API and applicable integration boundary to an observable result
- run successfully at least twice from clean consumer states; each run MUST be repeatable without relying on prior `vendor/`, generated state, database state, or other leftover environment state

A Harness MAY be a fixed consumer project, a fixture/template, or a deterministic script that creates a clean consumer project. Regardless of its form, it MUST NOT bypass Composer with direct `require`/`include` of `src/` files, depend on the artifact's `autoload-dev`, test namespace, test bootstrap, internal test fixtures, Host namespace, or Host autoload configuration, or access internal implementation details instead of documented public contracts. For a Base Module, the Harness MUST consume the Module's Artifact Root itself as the dependency; the Host root is not a substitute.

The proof MUST include Composer installation/resolution, production autoload, public API usage, the realistic workflow, its observable result, and the absence of hidden Host dependencies. Observable results are domain-specific and MAY include a returned public result/DTO, persisted state, a public effect, or a documented exception/failure contract; no single result type is required for every Package.

Persistence, schema/install assets, transactions, Clock, external services, cleanup, and other integration boundaries MUST be covered when they apply to the artifact's behavior, and MUST NOT be imposed on artifacts that do not need them. When the artifact owns persistence, the Harness MUST prove the relevant real persistence boundary; unit mocks alone are insufficient. When the domain has race-prone invariants and concurrent access is realistic, suitable concurrency verification MUST be included; unit mocks alone are insufficient for that proof.

A Consumer Verification Harness MUST NOT be forced to call an externally controlled provider merely because the artifact owns provider-specific behavior. Its applicable deterministic workflow proof remains required; actual live-provider verification is assessed separately under `std-external-provider-verification` (`standards/integrations/EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md`).

The Harness is an additional external-consumer proof layer. It MUST NOT replace applicable Unit, Integration, or System/E2E coverage. System/E2E boundaries remain behavior-based as defined in Section 3.3, so browser automation is required only when browser/UI behavior is part of the contract. A Consumer Verification Harness also does not replace Real Host Validation or define release eligibility.

---

### 3.5. External Provider Verification Boundary

Unit, Integration, System/E2E, and regression protection remain governed by this Standard. Applicable deterministic provider-contract regression protection MUST remain automated and MUST exercise the meaningful public/behavioral boundary required by this Standard. Controlled live-provider verification MUST NOT replace that testing evidence.

Current-truth verification against an externally controlled provider and its evidence/fixture lifecycle are owned by `std-external-provider-verification` (`standards/integrations/EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md`). Those verification concerns are not additional Testing Layers. Requirements for real repository-controlled/provisionable infrastructure remain applicable; they MUST NOT be interpreted as requiring live external-provider calls in deterministic testing or a Consumer Verification Harness. Required Host behavioral CI evidence under Section 4.7 remains binding for applicable deterministic tests; it does not turn separately governed live-provider verification into baseline Host CI.

---

### 3.6. Final Classes and Test Doubles

This subsection records the Owner-approved final-class testing policy of central `DEC-008` of `Maatify/php-engineering-standards` (identifying record: `INITIAL_STANDARDS_GOVERNANCE_DECISIONS_AR.md` §8). It is not a consumer-local decision, and this reference does not require the record to exist in a consumer repository.

- Test doubles MUST target contracts or interfaces that are meaningful boundaries by default, not concrete `final` classes.
- `final` MUST NOT be removed from a class solely to facilitate testing.
- When a class depends on a concrete `final` class, tests SHOULD first use a real instance when it is deterministic and suitable, or a Fake or test double at the collaborator or external boundary it depends on.
- An interface MUST NOT be introduced solely to satisfy a test; it is justified only by a real runtime replaceability need.
- `dg/bypass-finals` and legacy PHPStan suppressions for mocking `final` classes are not centrally approved default standards. This does not prohibit a repository from using a tool permitted by the Standard that governs its artifact type; any such use MUST NOT weaken production design or justify PHPStan suppressions or baselines.

---

## 4. Regression Protection Rules

Future development MUST NOT rely only on implementation-level tests.

### 4.1. Existing Behavior Protection
A refactor or new feature MUST NOT silently break already-supported observable behavior. Existing system/E2E tests SHOULD act as regression contracts.

### 4.2. New Observable Behavior
New externally observable behavior MUST receive appropriate system/E2E protection before the work is considered complete.

### 4.3. Critical Workflows
Critical workflows MUST have system-level coverage even when their internal components already have unit tests.

### 4.4. Integration Boundaries
Where correctness depends on multiple components working together, an appropriate integration test MAY complement the coverage, but it MUST NOT replace required system/E2E protection where the Testing Standard requires it.

### 4.5. Resolved Bugs/Regressions
A fixed regression MUST be accompanied by a test capable of detecting recurrence. Prefer the narrowest useful recurrence-detecting test, selected according to the actual defect boundary; it MAY be Unit, Integration, or System/E2E. Such a test MAY be sufficient on its own only when the defect did not affect any behavioral boundary that independently requires System/E2E protection under this Standard. If such a boundary was affected, including externally observable behavior, a critical workflow, or an integration boundary covered by the Core Principle, the relevant System/E2E protection MUST also remain present. Describing a Repository/DB, persistence, or database-interaction regression as internal MUST NOT remove the System/E2E obligation for an affected integration boundary covered by the Core Principle; an Integration recurrence test alone does not replace that protection.

**RED/GREEN evidence for proven defects.** When remediation corrects a **proven existing defect** whose deficient behavior can be safely and reliably reproduced, verification MUST capture a relevant failing execution **before the fix** (RED) and a successful execution **after the fix** (GREEN), using the narrowest test that actually detects the defect. An existing pre-fix failure is sufficient; a redundant test MUST NOT be created solely to produce a RED. If safe and reliable pre-fix reproduction is impracticable, the constraint MUST be stated explicitly, the recurrence-detecting test required above MUST still be retained, and proportionate alternative detection evidence MUST be provided; a RED result MUST NOT be fabricated. A new feature, a refactor, a documentation-only task, absent coverage for correct behavior, or a speculative or unproved defect does not by itself trigger this requirement. It does not replace separately applicable Unit, Integration, System/E2E, Host CI, or other obligations under this Standard, and it does not universally require Mutation Testing.

### 4.6. Internal-Only Refactors
If an internal refactor does not create or change externally observable behavior, and existing E2E/system coverage already protects the behavior, that existing coverage MAY be sufficient. The rule is about behavioral protection, not test-count inflation. You are NOT required to write a new E2E test for every trivial internal change.

### 4.7. Host CI Enforcement of Required Behavioral Evidence

For a deployable Project Host, testing evidence required by this Standard for Host-owned observable behavior, critical workflows, integration boundaries, and resolved regressions MUST be enforced through fail-closed Host CI when that evidence is required for the current change or applicable integration boundary. Required evidence MUST NOT remain manual-only; the existence of tests or a successful manual run does not satisfy this CI enforcement obligation.

When behavioral evidence is required, Host CI MUST NOT treat failure, cancelled required verification, an unexpected skip, missing required setup, a missing required test runner, or a missing required dependency or service as successful verification. Patterns such as `continue-on-error`, `allow-failure`, `|| true`, or a silent skip MUST NOT make required behavioral evidence non-binding.

Host-owned Project-Aware behavior covered by this Standard follows the same enforcement contract. For example, an `HTTP → Project-Aware handler → cross-module orchestration → Host-owned persistence / joins → response / state` path requires CI-enforced System/E2E or regression evidence when required by its observable behavior, critical workflow, integration boundary, or resolved regression. This does not create a separate Project-Aware testing contract or make a Host-specific Project-Aware artifact a standalone Package CI artifact through Profile inheritance.

Evidence selection remains behavior-based under Section 4.5. Host CI MUST enforce the required recurrence-detecting evidence and any independently required System/E2E protection for the affected behavioral boundary when required for the current change or applicable integration boundary. If narrow recurrence evidence is sufficient on its own under Section 4.5, that required evidence MUST be enforced fail-closed; if the affected boundary independently requires System/E2E protection, both required forms of evidence MUST be enforced fail-closed. No fixed suite name or `tests/Regression/` directory is required.

Required evidence tied to changed behavior MUST NOT be omitted from CI verification. This subsection does not require every Host CI run or every micro-change to execute the entire System/E2E suite or full Host test matrix. Selection of affected verification versus broader/full applicable verification, and broader/full verification requirements at applicable integration boundaries, remain governed by the applicable repository execution/integration governance. This Standard does not redefine that governance's integration-boundary algorithm or weaken its requirements.

Host architecture verification and behavioral Regression/System/E2E verification are separate obligations. When both apply, both MUST pass; neither substitutes for the other. `PROJECT_APPLICATION_ARCHITECTURE_STANDARD.md` retains architecture-verification ownership, while this Standard owns required behavioral testing evidence and its Host CI enforcement. Exact Host workflow YAML, jobs, triggers, tools, and orchestration remain repository-specific. `CI_WORKFLOW_STANDARD.md` retains its reusable Package/Base Artifact CI applicability and is not generalized to Host-only CI mechanics. Package/Base testing and CI contracts remain unchanged; Consumer Verification Harness applicability remains limited to the artifacts defined in Section 3.4 and is not extended to a Project Host by this subsection.

---

## 5. Completion and Readiness Semantics

Implementation completion REQUIRES the appropriate combination of testing layers.

A task, part, or phase MUST NOT be considered technically complete merely because:
- Unit tests pass.
- Static analysis passes.
- Individual classes are tested.
- Mocks reproduce the expected calls.

Where the feature depends on a real workflow or integration chain, appropriate system-level verification MUST also pass.

For artifacts covered by Section 3.4, completion and readiness also require the Consumer Verification Harness evidence defined there.
