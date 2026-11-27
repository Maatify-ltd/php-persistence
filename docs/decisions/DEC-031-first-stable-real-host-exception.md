# DEC-031 — First-Stable Real Host Validation exception

- **Decision ID:** `DEC-031`
- **Title:** First-Stable Real Host Validation exception
- **Status:** `PROPOSED`
- **Date:** `2026-11-27`
- **Decision Authority:** Project Owner. The [Owner direction recorded in Issue #29 on 2026-11-27](https://github.com/Maatify-ltd/php-persistence/issues/29#issuecomment-7206408956) selected `v1.0.0` without another RC and closed the two-project validation work. Approval of this proposal's exact exception scope and residual risk remains pending.
- **Scope / Concern:** Standalone Composer package `maatify/php-persistence`, Artifact Scope `/`, first Stable target `1.0.0` only.
- **Supersedes:** —
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Library Presentation Standard §§14.1.1–4](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md#141-first-stable-release-gate) governs eligibility. [DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md) supplies the package identity, [DEC-029](./DEC-029-selective-pinned-standards-upgrade.md) records the local pin, and [DEC-030](./DEC-030-successor-transition-before-first-stable.md) governs the successor transition.

## Context and evidence

[Issue #29](https://github.com/Maatify-ltd/php-persistence/issues/29) was closed as `NOT_PLANNED` on the Owner's direction. Validation on the same published RC in two independent real projects remains unverified. The [published RC Consumer Verification Harness result](https://github.com/Maatify-ltd/php-persistence/issues/29#issuecomment-6839437553) and [CI job](https://github.com/Maatify-ltd/php-persistence/actions/runs/43332269142/job/131579629474) show a pass for `maatify/php-persistence:1.0.0-rc.1` installed through Packagist as `dist` at `34c3b874525d36ae064c162cdd553bc75d18772b`. The CI run completed two clean MySQL 8.4.13 cycles, each with separate Composer fixtures for coexistence and differential verification. The fixture installations supply Harness evidence.

## Proposed decision

Request an Owner-approved exception for the entire Real Host Validation requirement on that published RC, including both independent projects, for first Stable `1.0.0` of this artifact. Keep the published-RC and same-RC Harness requirements. Use the verified RC.1 evidence above for those steps.

## Rationale and residual risk

The Owner chose to prepare first Stable without holding the release on the additional Real Host Validation step. A Host-only exception would implement that direction while preserving the publication, compatibility and release-verification requirements.

The remaining risk is unverified behavior in real applications with their own PDO settings, transaction and lock lifecycles, schema, scope definitions, and operational workloads. The [Package Reference concurrency, transaction, and isolation limits](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#7-concurrency-transaction-and-isolation-limits) govern those integration boundaries. Controlled fixtures and the five isolated compatibility probes required by [Issue #43](https://github.com/Maatify-ltd/php-persistence/issues/43#issuecomment-7125543712) can provide targeted evidence for those checks. They leave independent real-project operation unverified.

## Consequences and verification plan

1. Before qualification, obtain express Owner approval of the exact exception scope and risk assessment, record the approval date and reference, and make the decision and index entry `ACTIVE` together. After activation, bind an immutable reference to the approved decision to the exact candidate SHA in the qualification evidence. Reassess changes to the target, candidate content, exception scope or material risk, repeat affected verification, and renew Owner approval when the approved basis changes.
2. On the exact final `main` candidate SHA for `1.0.0`, use Composer to install `maatify/php-persistence` and verify the installed content against that SHA. Retain the package, target, SHA and installed-content evidence with the [Consumer Verification Harness](../guides/CONSUMER_VERIFICATION.md) result. Link separate passing results for Full Applicable CI and Release Artifact Verification on that same candidate and target. Use the [release evidence handoff](../guides/RELEASE_ARTIFACT_VERIFICATION.md#release-evidence-handoff).
3. Resolve the published-contract comparison and five isolated consumer compatibility probes in [Issue #43](https://github.com/Maatify-ltd/php-persistence/issues/43#issuecomment-7125543712), and the applicable correctness findings under [Issue #44](https://github.com/Maatify-ltd/php-persistence/issues/44#issuecomment-7125545539). Stop on any material `FAIL` or `UNRESOLVED`, including open P0 or P1 findings.
4. Obtain Strict Lead Acceptance and separate Owner publication authorization. Run Published Artifact Verification after external publication of the qualified target.

Release qualification evidence is tracked in [Issue #88](https://github.com/Maatify-ltd/php-persistence/issues/88).
