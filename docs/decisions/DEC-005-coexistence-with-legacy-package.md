# DEC-005 — Coexistence with the legacy package, without compatibility shortcuts

- **Decision ID:** `DEC-005`
- **Title:** Coexistence with the legacy package, without compatibility shortcuts
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — M-2 in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447), restated in [Issue #6](https://github.com/Maatify/php-persistence/issues/6).
- **Scope / Concern:** How `maatify/php-persistence` and the published `maatify/persistence` relate during the transition.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** This record. The Composer rule that `replace` needs explicit architectural approval is owned by the pinned [Composer Package Standard](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md).

## Context

The original successor intent for `maatify/persistence` is recorded in the historical [DEC-006](./DEC-006-successor-intent-and-standards-ownership-boundary.md). [DEC-030](./DEC-030-successor-transition-before-first-stable.md) governs the current successor transition. During a gradual migration, one application may require both packages.

## Decision

1. Both packages can be installed together. Their namespaces (`Maatify\Persistence\` and `Maatify\PhpPersistence\`) do not overlap ([DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md)).
2. `composer.json` of this package must not declare `replace`, `provide` or `conflict` against `maatify/persistence`.
3. The package must not ship `class_alias` calls, bridge classes or adapters that make a legacy type satisfy a new type or the reverse.
4. The two packages must not share a mutable global resource. At the time of this decision, the legacy savepoint name format `maatify_persistence_sp_<32 hex>` was a candidate for reuse because its 128-bit random suffix avoids collisions between coexisting packages. The active [DEC-011 decision](./DEC-011-responsibility-placement-and-savepoint-helper.md#decision) retained that format in item 6.
5. Rollback for a consumer is restoring its previous `require` line and imports; the legacy package stays published and unchanged during development.

## Rationale

`replace`, `provide`, `conflict` and aliases would hide the fact that the types differ and would break the ability to install both. Explicit migration of imports is verifiable; implicit aliasing is not.

## Consequences

- Consumers migrate by changing imports and type hints. The mapping is [CONTRACT_MATRIX.md](../architecture/CONTRACT_MATRIX.md).
- A coexistence job that installs both packages in one clean Composer root is a required later verification gate (planned in WU-10; see [ARCHITECTURE.md](../architecture/ARCHITECTURE.md) §9).
- Consumer migration, legacy deprecation and legacy archival are not part of this record and need separate authorization.
