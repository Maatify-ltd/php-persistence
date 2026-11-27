# Architecture

`maatify/php-persistence` — direct-PDO transaction, pagination and ordering utilities, designed for MySQL and MariaDB.

This document records the architecture decided in WU-3. WU-4 and WU-5 established Composer packaging, quality tooling, and real-database CI. WU-6 through WU-8 implemented Transaction, Pagination, and Ordering. Ordering reuses the Transaction capability's Gen2 runner and its `@internal` savepoint helper under [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). WU-9 delivered the canonical [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md), usage guidance, and runnable examples.

WU-10 added [consumer verification and coexistence](../guides/CONSUMER_VERIFICATION.md). WU-11 prepared the `1.0.0-rc.1` artifact and recorded its [historical archive checks](https://github.com/Maatify-ltd/php-persistence/blob/34c3b874525d36ae064c162cdd553bc75d18772b/docs/guides/RELEASE_ARTIFACT_VERIFICATION.md). The [README](../../README.md#release-artifact) identifies the artifact target and the [repository's Release Artifact Verification guide](../guides/RELEASE_ARTIFACT_VERIFICATION.md) describes the current checks. The [database policy](DATABASE_SUPPORT_AND_TEST_POLICY.md) records test evidence for named MySQL and MariaDB versions, including deadlocks, pagination snapshots, and ordering concurrency. That evidence is limited to the tested versions.

## 1. Document map

| Document | Content |
|---|---|
| This file | Scope, topology, ownership boundaries, requirements on later work units |
| [../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) | **Canonical, current** Public Runtime API, behavior, and exception contract (WU-9) |
| [CONTRACT_MATRIX.md](./CONTRACT_MATRIX.md) | Type-by-type mapping: legacy `v1.4.0`, legacy `main`, new package (dated design/legacy-mapping record) |
| [BEHAVIOR_CONTRACT.md](./BEHAVIOR_CONTRACT.md) | Behaviors B-01 to B-16 and their test evidence (dated design/evidence record) |
| [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md) | Engines, versions, matrix and test environment |
| [CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md) | CI workflow, Compose lifecycle, example smoke checks, test matrix, image evidence |
| [QUALITY_TOOLING.md](./QUALITY_TOOLING.md) | WU-4 toolchain, scripts, the PER Coding Style 3.1 verification gap and the planning inputs for WU-5 |
| [../decisions/DECISIONS_INDEX.md](../decisions/DECISIONS_INDEX.md) | Durable decisions that this document applies |

WU-9 delivered the Package Reference required by the pinned standards. It is now the single canonical owner of the current, consumer-facing Public Runtime API, behavior, and exception contract — it does not compete with this document or with [CONTRACT_MATRIX.md](./CONTRACT_MATRIX.md)/[BEHAVIOR_CONTRACT.md](./BEHAVIOR_CONTRACT.md). This file remains the design/topology record and those two remain dated legacy-mapping/evidence records; where any of them describes current public-contract facts, the Package Reference governs.

## 2. Governing sources

| Source | Role |
|---|---|
| [STANDARDS_MANIFEST.md](../php-engineering-standards/STANDARDS_MANIFEST.md) | Adoption record; pinned upstream commit `c1dbb2da062a35c45638abf2bcb2ae210140eb5d` ([DEC-029](../decisions/DEC-029-selective-pinned-standards-upgrade.md)) |
| Pinned standards under `docs/php-engineering-standards/standards/` | Normative rules; this document links to them rather than copying them |
| Decisions `ACTIVE` in the index | Closed choices; `PROPOSED` records are not implementation authority |
| Issue [#1](https://github.com/Maatify/php-persistence/issues/1) | Architecture study (historical; superseded parts are not decisions) |
| Issue [#5](https://github.com/Maatify/php-persistence/issues/5), [#6](https://github.com/Maatify/php-persistence/issues/6) | Owner decisions and WU-3 scope |

Newer central standards are not applied. Rules added upstream after the pinned commit are not part of this repository's contract.

## 3. Purpose and boundaries

The package offers three capabilities over a caller-supplied `PDO` connection:

- **Transaction:** run a callback in a transaction, or in a savepoint when the caller already has one; optional bounded deadlock retry for package-owned transactions.
- **Pagination:** parameterized, whitelist-sorted, bounded pagination returning a result DTO.
  Optional static composite sort plans belong to Offset pagination and reuse its page executor. The Host supplies the SQL and decides the total order on its result. See [DEC-024](../decisions/DEC-024-optional-static-composite-offset-pagination.md).
  Optional dynamic Offset sorting shares that executor and uses one validated internal order for SQL and the dynamic result metadata. The Host owns the unique tie and the read view. See [DEC-025](../decisions/DEC-025-optional-dynamic-offset-sort-contract.md).
- **Ordering:** next-position and move operations for ordered rows inside a scope (for example a column value), with locking reads and savepoint protection.

The Host owns its tables, migrations, connections, PDO attributes, and server and session defaults. Operational reporting read models, factories, facades, and consumer-specific logic are outside this package's scope. The optional C1 paginator holds one grant and its terminal outcome on a single-use object. See [DEC-018](../decisions/DEC-018-optional-delegated-snapshot-pagination.md).

## 4. Source topology

Decided in [DEC-010](../decisions/DEC-010-package-topology-multi-capability.md); responsibility folders are decided in [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md).

```text
Source Topology: Multi Capability

Capabilities:
- Ordering
- Pagination
- Transaction
```

Representative paths are shown below. See the [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#2-source-topology) for the complete source inventory.

```text
src/
├── Exception/PhpPersistenceExceptionInterface.php
├── Ordering/
│   ├── Config/ScopedOrderingConfig.php
│   ├── Exception/{InvalidOrderingConfigurationException,
│   │              InvalidOrderingOperationException}.php
│   └── Repository/ScopedOrderingManager.php
├── Pagination/
│   ├── Config/PaginationConfig.php
│   ├── Criteria/{PageRequest,PdoPaginationQueryDescriptor}.php
│   ├── DTO/PageResultDTO.php
│   ├── Enum/SortDirectionEnum.php
│   ├── Exception/{InvalidPaginationConfigurationException,
│   │              InvalidPaginationQueryException,
│   │              PaginationExecutionException}.php
│   ├── Repository/{HostSnapshotRetirement,PdoPaginator}.php
│   └── ValueObject/SortWhitelist.php
└── Transaction/
    ├── Command/VerifiedTransactionDecisionCommand.php
    ├── Exception/{InvalidTransactionConfigurationException,
    │              TransactionExecutionException}.php
    └── Repository/{TransactionRunnerInterface,
                    SavepointTransactionRunnerInterface,
                    PdoTransactionRunner,
                    PdoSavepointTransactionRunner}.php
                    + @internal executor and savepoint helper
```

Rules from [DEC-010](../decisions/DEC-010-package-topology-multi-capability.md):

- Dependency direction: `Ordering → Transaction`; `Pagination` and `Transaction` are independent.
- B1 runs its metadata check, anchor lock and dense ordering commands inside one private Protected checkout reservation. Moves and compaction use the existing Transaction savepoint and settlement path. The Host supplies the reviewed schema, trusted catalog, business authorization and transaction completion. See [DEC-026](../decisions/DEC-026-protected-unique-ordering-b1.md) and the [B1 contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#541-protected-unique-safe-ordering-b1).
- No `src/Pdo/`, no `Internal/`, `Shared/`, `Helpers/`, `Utils/`; no empty folders.
- Public FQCN root: `Maatify\PhpPersistence\`; marker: `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface` ([DEC-004](../decisions/DEC-004-package-identity-namespace-and-exception-marker.md)).

## 5. Ownership and failure boundaries

The table describes the raw Transaction runners and raw Ordering paths where they manage or join a transaction. The [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#7-concurrency-transaction-and-isolation-limits) records ownership and failure handling for the qualified Protected, Verified, C1, and B paths, including B's Host-directed next-transaction settings.

| Situation | Package behavior |
|---|---|
| No transaction active | Begin, run, commit; on failure roll back (best effort) and rethrow the original. A `false` from begin or commit fails closed with `TransactionExecutionException`. |
| Caller's transaction active | Participate. Never begin, commit or finally roll back it. Use a savepoint only to limit the failed operation (savepoint runner, `moveWithinScope`). |
| Callback throws | The original `Throwable` is rethrown unchanged. Cleanup failures never replace it. |
| Deadlock (`40001`/`1213`) in a package-owned transaction | Replay only under the exact conditions of B-12, default off. |
| Deadlock or any error in a caller-owned transaction | Never replayed; propagates. The caller decides. |
| Engine ends the transaction (for example MariaDB `1020`) | The error propagates unchanged. The package does not retry the caller's work and does not alter settings ([DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md), [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md)). |

Exceptions: the package defines named exceptions for configuration, execution-control and operation-input errors, all implementing the marker and extending the `maatify/exceptions` hierarchy. `PDOException` and other external `Throwable`s are not wrapped and do not implement the marker.

## 6. Naming and API disposition

- Decided: root namespace, marker, `PageResultDTO`, reuse of `PdoTransactionRunner` and `PdoSavepointTransactionRunner` with Gen2 behavior, omission of `OrderingTransactionException` ([DEC-004](../decisions/DEC-004-package-identity-namespace-and-exception-marker.md), [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md)).
- Decided: all other names stay as in the legacy package ([DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md)).
- The full mapping is [CONTRACT_MATRIX.md](./CONTRACT_MATRIX.md).

## 7. Successor intent and the standards boundary

The package is intended to replace `maatify/persistence` in the future ([DEC-030](../decisions/DEC-030-successor-transition-before-first-stable.md)). The pinned [Package Building Standard §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities) names `maatify/php-persistence` as the normative owner of shared PDO Ordering and Pagination mechanics. Stable consumption requires a verified, published Major 1 release at or above `1.0.0`. Release, consumer migration and the legacy package lifecycle require separate authorization.

Coexistence rules are in [DEC-005](../decisions/DEC-005-coexistence-with-legacy-package.md): both packages can be installed; no `replace`, `provide`, `conflict`, `class_alias` or bridge.

## 8. Provenance and licensing

The repository is under the Maatify Proprietary License ([DEC-014](../decisions/DEC-014-free-license-registration.md)). The legacy repository is MIT-licensed. Before any legacy source or test is copied, the gate in [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md) item 6 must be completed and recorded in the copying pull request, with the legacy commit SHA. WU-3 copied nothing.

Legacy documents (studies, ADRs, harness scripts) are referenced by permanent links and are not copied or re-labelled as decisions of this repository.

## 9. Historical requirements for the original work units

This table records the original WU acceptance criteria. The current PHP constraint is in [`composer.json`](../../composer.json). Section 10 records first-Stable preparation under the current pin.

| WU | Original acceptance criteria |
|---|---|
| WU-4 Composer and quality tooling | `php ^8.4`; PSR-4 per DEC-004; no `replace`/`provide`/`conflict`; no `version`, no `composer.lock`; `ext-pdo` and `ext-pdo_mysql` as mandatory `require` entries ([DEC-012](../decisions/DEC-012-mandatory-pdo-mysql-driver.md)); the package marker interface; PHPStan at max level without baseline; code style per the pinned PHP Coding Style Standard. |
| WU-5 CI and Integration infrastructure | Matrix and environment rules of [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md); matrix cells per [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md); one parameterized Compose; pinned images; lowest/latest dependency jobs; a single required gate job. |
| WU-6 Transaction | Apply [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md); characterization tests of the savepoint logic pass before the helpers are merged and before the API freeze; named tests for B-10 to B-13; Integration on PHP 8.4 and latest with real deadlocks; provenance gate. |
| WU-7 Pagination | B-08, B-09 golden fixtures from legacy; provenance gate. |
| WU-8 Ordering | Depends on WU-6; B-01 to B-07; stale-snapshot, lazy-savepoint, rollback, soft-delete and nullable-scope cases; MariaDB cases per DEC-009. |
| WU-9 Documentation | Package Reference with the literal topology declaration, API lists, exceptions, database support limited to what is verified, classifications; README without unverified claims; remaining documents required by the pinned Library Presentation Standard. |
| WU-10 Harness and coexistence | External consumer harness run twice from a clean state; a job installing both packages in one clean Composer root; differential tests against legacy behavior where B-rows claim equivalence. |
| WU-11 Release Candidate preparation | Release artifact checks on the exact SemVer RC target (for example `1.0.0-rc.1`); release-facing files prepared without claiming publication; strict lead acceptance. Publishing is not part of this unit. |
| WU-12 Release Candidate publication | The exact RC is published and externally resolvable and installable through Composer, after explicit Owner authorization. |
| WU-13 Release Candidate verification | The Consumer Verification Harness runs against the exact published RC; then Real Host Validation of the same RC in at least two independent real projects (reusing one harness twice or several environments of one Host does not count); verifiable evidence retained, identifying the RC version and each Host. |
| WU-14 Stable release | Stable preparation and publication only after the evidence of WU-13 is complete ([Library Presentation §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md)), with strict lead acceptance and explicit Owner authorization. |

The original work units also required verification of the legacy source commit ([DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md) item 1) and the pinned standards commit.

## 10. First-Stable preparation

The standards upgrade in [DEC-029](../decisions/DEC-029-selective-pinned-standards-upgrade.md) is complete. [DEC-030](../decisions/DEC-030-successor-transition-before-first-stable.md) records the ownership boundary before Stable publication and retains separate authorization for consumer migration and the legacy package lifecycle.

[DEC-031](../decisions/DEC-031-first-stable-real-host-exception.md) covers the Real Host Validation requirement only for the first Stable `1.0.0` of `maatify/php-persistence` at Artifact Scope `/`. The published RC and its same-version Consumer Verification Harness remain required. The decision records the published RC.1 and Harness evidence. Qualification on the actual `main` candidate SHA requires Full Applicable CI, the candidate Consumer Verification Harness, Release Artifact Verification, and Strict Lead Acceptance under [Library Presentation Standard §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md#141-first-stable-release-gate). See the [release evidence handoff](../guides/RELEASE_ARTIFACT_VERIFICATION.md#release-evidence-handoff).

Qualification is tracked in [#88](https://github.com/Maatify-ltd/php-persistence/issues/88) and requires separate Owner authorization to start. Publication has its own authorization.
