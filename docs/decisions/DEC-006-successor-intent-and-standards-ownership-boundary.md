# DEC-006 — Successor intent and the boundary with central standards ownership

- **Decision ID:** `DEC-006`
- **Title:** Successor intent and the boundary with central standards ownership
- **Status:** `SUPERSEDED`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — M-1 in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447) and the confirmation in [Issue #5, comment 6441236923](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6441236923).
- **Scope / Concern:** The intended future relation to `maatify/persistence`, and what this repository may and may not state about shared-capability ownership.
- **Supersedes:** `—`
- **Superseded By:** [DEC-030](./DEC-030-successor-transition-before-first-stable.md)
- **Canonical Contract / Current Owner:** The current normative owner of shared Ordering and Pagination remains the pinned [Package Building Standard](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md), section "Shared Ordering and Pagination Utilities", which names `maatify/persistence`. This record does not replace it.

> **Historical record.** The present-tense statements and the Canonical Contract / Current Owner field refer to the [Package Building Standard §11 at the original pin](https://github.com/Maatify-ltd/php-engineering-standards/blob/ebcf55b0591db61d18a1407bc3effef0197b388f/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities). [DEC-030](./DEC-030-successor-transition-before-first-stable.md) records the current decision and its governing contract.

## Context

The Owner intends `maatify/php-persistence` to replace `maatify/persistence` in the future. The pinned standard still names the legacy package as the published provider. The central issue that proposed a handover ([Maatify/php-engineering-standards#180](https://github.com/Maatify/php-engineering-standards/issues/180)) was closed as not planned, and this repository must not reopen it. The Owner expects a central change only after the new version exists, through a separate governed and approved change.

## Decision

1. **Intent (ACTIVE):** `maatify/php-persistence` is being built as the future successor of `maatify/persistence`. It absorbs the latest viable non-deprecated capabilities of the legacy code ([DEC-007](./DEC-007-legacy-source-baseline-and-deprecated-retirement.md)).
2. **Current fact (unchanged):** under the pinned standards, `maatify/persistence` is the canonical published provider of shared Ordering and Pagination. This repository does not claim that ownership has transferred.
3. **Sequence (future, each separately authorized):** release of this package; a central standards change; consumer migration; deprecation and archival of the legacy package. None of these is performed or authorized by this record.
4. Documents in this repository describe the successor relation as intent. They must not describe the legacy package as replaced, deprecated or archived, and must not state that the central standards were changed.
5. Central issue #180 is not reopened. The pinned standards snapshot (`ebcf55b0591db61d18a1407bc3effef0197b388f`, [DEC-003](./DEC-003-pinned-standards-adoption.md)) is not changed or upgraded here.

## Rationale

Recording the intent keeps later work units aligned while avoiding a false normative claim. The Owner controls the timing of the central change.

## Consequences

- Until the central change, a consumer that follows the pinned standards literally still uses `maatify/persistence` for shared Ordering and Pagination. Migrating a consumer is an explicit exception path that the consumer's own governance must authorize.
- The Package Reference, README and release notes of this package must not advertise it as the canonical provider before the central change.
- Any later change to the pinned standards is a separate reviewed Upgrade under [DEC-003](./DEC-003-pinned-standards-adoption.md).
