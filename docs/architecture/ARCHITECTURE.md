# Architecture

`maatify/php-persistence` — direct-PDO transaction, pagination and ordering utilities, designed for MySQL and MariaDB.

This document records the architecture decided in WU-3. WU-4 and WU-5 established Composer packaging, quality tooling, and real-database CI. WU-6 through WU-8 implemented Transaction, Pagination, and Ordering. Ordering reuses the Transaction capability's Gen2 runner and its `@internal` savepoint helper under [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). WU-9 delivered the canonical [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md), usage guidance, and runnable examples.

WU-10 added [consumer verification and coexistence](../guides/CONSUMER_VERIFICATION.md). WU-11 prepares the `1.0.0-rc.1` artifact and its [archive verification](../guides/RELEASE_ARTIFACT_VERIFICATION.md). See the [README](../../README.md#installation) for installation and the publication state recorded for this artifact. The [database policy](DATABASE_SUPPORT_AND_TEST_POLICY.md) records test evidence for named MySQL and MariaDB versions, including deadlocks, pagination snapshots, and ordering concurrency. That evidence is limited to the tested versions.

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
| [STANDARDS_MANIFEST.md](../php-engineering-standards/STANDARDS_MANIFEST.md) | Adoption record; pinned upstream commit `ebcf55b0591db61d18a1407bc3effef0197b388f` ([DEC-003](../decisions/DEC-003-pinned-standards-adoption.md)) |
| Pinned standards under `docs/php-engineering-standards/standards/` | Normative rules; this document links to them rather than copying them |
| Decisions `ACTIVE` in the index | Closed choices; `PROPOSED` records are not implementation authority |
| Issue [#1](https://github.com/Maatify/php-persistence/issues/1) | Architecture study (historical; superseded parts are not decisions) |
| Issue [#5](https://github.com/Maatify/php-persistence/issues/5), [#6](https://github.com/Maatify/php-persistence/issues/6) | Owner decisions and WU-3 scope |

Newer central standards are not applied. Rules added upstream after the pinned commit are not part of this repository's contract.

## 3. Purpose and boundaries

The package offers three capabilities over a caller-supplied `PDO` connection:

- **Transaction:** run a callback in a transaction, or in a savepoint when the caller already has one; optional bounded deadlock retry for package-owned transactions.
- **Pagination:** parameterized, whitelist-sorted, bounded pagination returning a result DTO.
- **Ordering:** next-position and move operations for ordered rows inside a scope (for example a column value), with locking reads and savepoint protection.

Not in scope: owning tables or migrations, opening connections, changing PDO attributes or server settings, operational reporting read models, factories or facades, any consumer-specific logic. The package keeps no state between calls.

## 4. Source topology

Decided in [DEC-010](../decisions/DEC-010-package-topology-multi-capability.md); responsibility folders are decided in [DEC-011](../decisions/DEC-011-responsibility-placement-and-savepoint-helper.md).

```text
Source Topology: Multi Capability

Capabilities:
- Ordering
- Pagination
- Transaction
```

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
│   ├── Repository/PdoPaginator.php
│   └── ValueObject/SortWhitelist.php
└── Transaction/
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
- No `src/Pdo/`, no `Internal/`, `Shared/`, `Helpers/`, `Utils/`; no empty folders.
- Public FQCN root: `Maatify\PhpPersistence\`; marker: `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface` ([DEC-004](../decisions/DEC-004-package-identity-namespace-and-exception-marker.md)).

## 5. Ownership and failure boundaries

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

The package is intended to replace `maatify/persistence` in the future ([DEC-006](../decisions/DEC-006-successor-intent-and-standards-ownership-boundary.md)). Today the pinned Package Building Standard still names `maatify/persistence` as the canonical published provider of shared Ordering and Pagination. This repository does not claim a transfer, does not reopen central issue #180, and does not change the pinned standards. A central change is a separate, later, Owner-authorized step after the first release.

Coexistence rules are in [DEC-005](../decisions/DEC-005-coexistence-with-legacy-package.md): both packages can be installed; no `replace`, `provide`, `conflict`, `class_alias` or bridge.

## 8. Provenance and licensing

The repository is under the Maatify Proprietary License ([DEC-002](../decisions/DEC-002-proprietary-licensing.md)). The legacy repository is MIT-licensed. Before any legacy source or test is copied, the gate in [DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md) item 6 must be completed and recorded in the copying pull request, with the legacy commit SHA. WU-3 copied nothing.

Legacy documents (studies, ADRs, harness scripts) are referenced by permanent links and are not copied or re-labelled as decisions of this repository.

## 9. Requirements on later work units

| WU | Must satisfy before acceptance |
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

Each work unit also re-verifies the legacy source commit ([DEC-007](../decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md) item 1) and the pinned standards commit.

## 10. Unresolved items and what they block

| Item | Record | Blocks |
|---|---|---|
| Safe MariaDB contract for `1020` in caller-owned transactions | [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) `PROPOSED` | WU-8 acceptance for MariaDB; any MariaDB claim |
| Which two independent Hosts validate the release candidate, and when | none yet | WU-13 |
| Central standards change, consumer migration, legacy archive | none (separate authorizations) | Any statement that ownership transferred |

Not open: the first Stable release gate is mandatory and is sequenced across WU-11 to WU-14 above (RC prepared, then published, then verified, then Stable); no condition that can only be met after the RC is published blocks WU-11 or WU-12 under the pinned [Library Presentation Standard §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md): a published, externally installable SemVer RC; the Consumer Verification Harness run against that exact published RC; then Real Host Validation of the same RC in at least two independent real projects. CI success and the harness alone do not authorize Stable. Publishing, tagging and owner approval remain separate authorizations that this work does not give.
