# DEC-011 — Responsibility placement, savepoint helper ownership and retained names

- **Decision ID:** `DEC-011`
- **Title:** Responsibility placement, savepoint helper ownership and retained names
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Technical Lead` — proposed as decisions I-1, I-2 and I-3 in [Issue #1, Addendum 2/2](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370817113) and accepted there only as "design hypotheses" ([Lead acceptance](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6371517815)). Activated by the Lead's decision in the [WU-3 Fresh Full Independent Review](https://github.com/Maatify/php-persistence/pull/7#pullrequestreview-5666993605): the design is accepted and may be `ACTIVE` in the correction commit, with the WU-6 tests remaining mandatory (item 3 and item 4).
- **Scope / Concern:** Which responsibility folder holds the classes that issue SQL or transaction-control statements, who owns the savepoint helper, and whether optional renames beyond those already decided are made.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** The responsibility vocabulary and the SQL-placement rules are owned by the pinned [Package Building Standard](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md). [Architecture §4](../architecture/ARCHITECTURE.md#4-source-topology) documents the current source topology. The [Package Reference §3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#3-public-runtime-api-inventory) is the canonical public type inventory.

## Context

The pinned standard says SQL lives in Repositories or package-local SQL support builders and that Services never contain raw SQL, and it defines no responsibility for stateless PDO executors. The transaction runners execute control statements such as `SAVEPOINT`, `RELEASE`, `ROLLBACK TO` and `DO 0`. The first study proposal put the runners in `Service/`; the addendum withdrew that because it risks a literal conflict with the Services rule ([Issue #1, Addendum 2/2 §4.3](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370817113)). Legacy code has three similar copies of savepoint control and cleanup logic: `OperationSavepoint`, `PdoSavepointTransactionRunner` and `PdoDeadlockRetrySavepointTransactionRunner`.

## Decision

1. **Placement.** Every class that issues SQL or transaction-control statements is placed under `Repository/` of its capability. The package has no `Service/` folder.
2. **Helper ownership.** One `@internal` savepoint helper is owned by the `Transaction` capability (working name `Transaction\Repository\SavepointScope`) and consumed by `Ordering`. A PHP class marked `@internal` is still loadable, so the Package Reference states that it is outside the public contract and SemVer.
3. **Characterization first.** The legacy tests that describe savepoint and cleanup behavior are carried without changing any assertion. They must pass against the unmerged logic first and against the merged helper afterwards. Any difference is a behavior change and needs its own decision record.
4. **Freeze gate.** The public API and the Package Reference are not frozen before the tests in item 3 pass against the merged helper (WU-6).
5. **Retained names.** Beyond the changes in [DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md) and [DEC-007](./DEC-007-legacy-source-baseline-and-deprecated-retirement.md), no optional rename is made: `PageRequest` and the `Pdo` prefix of `PdoPaginator` and `PdoPaginationQueryDescriptor` are kept.
6. **Savepoint name format.** Keep `maatify_persistence_sp_<32 hex>` because the legacy code documents and tests it and its random suffix avoids collisions between coexisting packages.

## Alternatives considered

- All runners in `Service/` with a documented interpretation of the Services rule: simpler, but it reopens a literal conflict with the pinned rule. It would need a decision that control statements are not raw SQL.
- Three packages: rejected in [DEC-010](./DEC-010-package-topology-multi-capability.md).

## Consequences

- The [Contract Matrix §3](../architecture/CONTRACT_MATRIX.md#3-type-matrix) records the original per-type placements and their implementation.
- The [historical WU-6 evidence](../architecture/BEHAVIOR_CONTRACT.md#transaction-wu-6) records the Transaction verification. Items 3 and 4 required the characterization tests to pass first against the unmerged logic and again against the shared savepoint helper before the original public API and Package Reference freeze.
- Changing item 1, 2 or 5 later needs a new record that supersedes this one; the namespace root, type short names and capability split stay as decided in [DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md) and [DEC-010](./DEC-010-package-topology-multi-capability.md).
