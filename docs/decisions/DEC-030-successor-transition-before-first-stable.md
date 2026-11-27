# DEC-030 — Successor transition before first Stable

- **Decision ID:** `DEC-030`
- **Title:** Successor transition before first Stable
- **Status:** `ACTIVE`
- **Date:** `2026-11-27`
- **Decision Authority:** Project Owner. The [local authorization in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7207751440) selects the exact pin before first-Stable release work. The [central readiness correction in Issue #208](https://github.com/Maatify-ltd/php-engineering-standards/issues/208#issuecomment-7203703236) separately authorizes availability of the central standards for adoption ahead of Stable publication, with publication evidence still required for consumption.
- **Scope / Concern:** The timing of standards adoption, shared Ordering and Pagination ownership, and the transition from `maatify/persistence` to `maatify/php-persistence`.
- **Supersedes:** [DEC-006](./DEC-006-successor-intent-and-standards-ownership-boundary.md)
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Building Standard §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities) governs normative ownership and eligible consumption. [DEC-029](./DEC-029-selective-pinned-standards-upgrade.md) records the local pin.

## Context

The original [M-1](https://github.com/Maatify-ltd/php-persistence/issues/5#issuecomment-6384957447) established `maatify/php-persistence` as the intended successor to `maatify/persistence` and placed the central standards change after the new release. At that time the local pin named the legacy package as the published provider. The Owner later approved the Stable Major 1 line under [central DEC-058](https://github.com/Maatify-ltd/php-engineering-standards/pull/214#issuecomment-7132126319). The subsequent central readiness decision permits standards adoption before publication, and the Owner separately authorized this repository's exact pin ahead of the first-Stable work. This updates the sequence recorded in DEC-006 while retaining the successor intent.

## Decision

1. Adopt the central standards pin in DEC-029 before the first Stable release. The pinned Package Building Standard §11 designates `maatify/php-persistence` as the normative owner of shared PDO Ordering and Pagination mechanics. This designation precedes Stable publication. Availability of an eligible runtime API is established separately for an exact published release.
2. Publish the intended first Stable `1.0.0` only after exact-target qualification and separate Owner release authorization. For consumption, verify that the exact Stable package and version are published and installable through an approved Composer source, that the release and Composer metadata identify the same source revision, and that the published contract provides the required capabilities with verified compatibility. The eligible line is Stable Major 1 from `1.0.0` inclusive to `2.0.0` exclusive.
3. Stable consumer migration may proceed only after a qualified Stable release is published and the consumer's required capabilities and compatibility are verified. Each migration needs separate authorization. This local upgrade imposes no migration obligation on consumers. Until first-Stable publication, any previously approved RC trial remains subject to its original authorization and Package Building Standard §11. This decision authorizes no new RC trial or extension.
4. Preserve the [DEC-005 coexistence contract](./DEC-005-coexistence-with-legacy-package.md) through the transition. Legacy deprecation, ending active development, and archival remain separate actions subject to completed transition and explicit Owner authorization. [Central issue #180](https://github.com/Maatify/php-engineering-standards/issues/180) remains closed.

## Rationale

Making the standards available first lets this repository apply the current dependency and first-Stable rules during preparation. Requiring evidence for the exact published version keeps normative ownership separate from an API's availability and a consumer's eligibility to adopt it.

## Consequences

The local pin governs preparation and review. The first Stable release, migration of any consumer, and legacy lifecycle changes each retain their own authorization and evidence.
