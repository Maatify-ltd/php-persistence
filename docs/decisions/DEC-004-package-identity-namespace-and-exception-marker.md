# DEC-004 — Package identity, root namespace and exception marker

- **Decision ID:** `DEC-004`
- **Title:** Package identity, root namespace and exception marker
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — Composer name and repository per Issue #1 study; namespace and marker ratified as M-2 in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447); renaming of `PageResult` to `PageResultDTO` directed by the WU-3 scope in [Issue #6](https://github.com/Maatify/php-persistence/issues/6).
- **Scope / Concern:** Public identity of the package: Composer name, PSR-4 root namespace, package-wide exception marker, and the DTO naming of the pagination result.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** This record for the identity values. The naming rules they follow are owned by the pinned [Package Building Standard](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md) and [Composer Package Standard](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md). The resulting public type inventory is [CONTRACT_MATRIX.md](../architecture/CONTRACT_MATRIX.md).

## Context

The earlier package `maatify/persistence` uses the root namespace `Maatify\Persistence\`. Deriving the new root literally from the pinned naming rule would reuse that root and make coexistence unsafe; the pinned standards do not define `{PackageName}` for `php-*` repositories ([Issue #1, study 2/6 §2.6 S-2](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370375892)). The Owner ratified a distinct root. The legacy marker `PersistenceException` and the legacy DTO `PageResult` do not follow the pinned naming rules, and the pinned legacy-marker exception applies to a package's own previously published marker, not to a new package.

## Decision

| Item | Value |
|---|---|
| Composer package name | `maatify/php-persistence` |
| Repository | `Maatify-ltd/php-persistence` |
| PSR-4 root namespace | `Maatify\PhpPersistence\` → `src/` |
| Test namespace (WU-4 and later) | `Maatify\PhpPersistence\Tests\` → `tests/` |
| Package-wide exception marker | `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface` |
| Pagination result type | `PageResultDTO` (replaces the legacy name `PageResult`) |
| License | Maatify Proprietary License ([DEC-002](./DEC-002-proprietary-licensing.md)) |

1. Every exception the package defines implements `PhpPersistenceExceptionInterface`, directly or indirectly. `PDOException` and other external `Throwable`s raised by the host or driver do not implement it.
2. `PageResultDTO` keeps the array and JSON shape of the legacy `PageResult` ([BEHAVIOR_CONTRACT.md](../architecture/BEHAVIOR_CONTRACT.md) B-09). Only the type name and namespace change.
3. No pinned legacy-marker exception is claimed for this package.

## Rationale

A distinct root allows both packages in one Composer root without a PSR-4 prefix conflict. The marker and DTO names satisfy the pinned naming rules from the first release instead of carrying a legacy exception.

## Consequences

- Every public type has a new fully-qualified name. Nothing keeps the legacy FQCN, and no import compatibility is promised ([DEC-005](./DEC-005-coexistence-with-legacy-package.md)).
- The `{PackageName}` derivation (`php-persistence` → `PhpPersistence`) is an interpretation of an undefined term in the pinned standards, recorded here. Clarifying it centrally is outside this repository's authority.
- The exact placement of each type under the new root is governed by [DEC-010](./DEC-010-package-topology-multi-capability.md) and, for responsibility folders, [DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md).
- The values above took effect when WU-4 added `composer.json`; the regression suite pins them.
