# DEC-006 — Successor intent and the boundary with central standards ownership

- **Decision ID:** `DEC-006`
- **Title:** Successor intent and the boundary with central standards ownership
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — M-1 in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447) and the confirmation in [Issue #5, comment 6441236923](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6441236923).
- **Scope / Concern:** The intended future relation to `maatify/persistence`, and what this repository may and may not state about shared-capability ownership.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** The pinned [Package Building Standard §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities) owns the shared Ordering and Pagination dependency policy. [DEC-029](./DEC-029-selective-pinned-standards-upgrade.md) records the current pin.

## Current governing references

The pin recorded by [DEC-029](./DEC-029-selective-pinned-standards-upgrade.md) includes [Package Building 6.0.0 §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities). It designates `maatify/php-persistence` as the normative owner of shared PDO Ordering and Pagination mechanics. Stable consumption is limited to published versions from `1.0.0` inclusive to `2.0.0` exclusive and requires verification of the release, required capabilities, and compatibility. This pin update supplies the current standards reference. [DEC-005](./DEC-005-coexistence-with-legacy-package.md) governs coexistence. Package release, consumer migration, and legacy deprecation or archival require separate authorization and evidence.

## Original context (2026-10-27)

The original decision below records the successor intent and the publication and ownership facts as they stood under the `ebcf55b0591db61d18a1407bc3effef0197b388f` pin. The current governing references above describe the later central policy and local pin.

The Owner intends `maatify/php-persistence` to replace `maatify/persistence` in the future. The pinned standard still names the legacy package as the published provider. The central issue that proposed a handover ([Maatify/php-engineering-standards#180](https://github.com/Maatify/php-engineering-standards/issues/180)) was closed as not planned, and this repository must not reopen it. The Owner expects a central change only after the new version exists, through a separate governed and approved change.

## Original decision

1. **Intent (ACTIVE):** `maatify/php-persistence` is being built as the future successor of `maatify/persistence`. It absorbs the latest viable non-deprecated capabilities of the legacy code ([DEC-007](./DEC-007-legacy-source-baseline-and-deprecated-retirement.md)).
2. **Current fact (unchanged):** under the pinned standards, `maatify/persistence` is the canonical published provider of shared Ordering and Pagination. This repository does not claim that ownership has transferred.
3. **Sequence (future, each separately authorized):** release of this package; a central standards change; consumer migration; deprecation and archival of the legacy package. None of these is performed or authorized by this record.
4. Documents in this repository describe the successor relation as intent. They must not describe the legacy package as replaced, deprecated or archived, and must not state that the central standards were changed.
5. Central issue #180 is not reopened. The pinned standards snapshot (`ebcf55b0591db61d18a1407bc3effef0197b388f`, [DEC-003](./DEC-003-pinned-standards-adoption.md)) is not changed or upgraded here.

## Original rationale

Recording the intent keeps later work units aligned while avoiding a false normative claim. The Owner controls the timing of the central change.

## Original consequences

- Until the central change, a consumer that follows the pinned standards literally still uses `maatify/persistence` for shared Ordering and Pagination. Migrating a consumer is an explicit exception path that the consumer's own governance must authorize.
- The Package Reference, README and release notes of this package must not advertise it as the canonical provider before the central change.
- Any later change to the pinned standards is a separate reviewed Upgrade under [DEC-003](./DEC-003-pinned-standards-adoption.md).
