# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [1.0.0]

### Added

- Optional consistent pagination for one page on a qualified Host-delegated read connection (C1). The Host receives a terminal connection disposition independently of the page or exception.
- Optional pagination within a qualified Host-owned transaction (B). Registered pages share the confirmed read view, can see earlier writes in that transaction, and report per-attempt settlements.
- Forward-only Keyset pagination for a qualified unique integer key, with a signed continuation bound to the query and Host scope.
- Static composite and dynamic Offset sorting through separate entry points. Static plans define fixed trailing terms. Dynamic sorting accepts up to three allowlisted request sort keys with independent directions.
- Protected Host write checkouts with registered commands and guarded Ordering. The Host directs transaction completion and uses the settlement to choose handoff or discard.
- Verified Apply/Discard (C2) for package-owned and Host-owned transactions. It returns the decision payload after confirming the required control and reports the database outcome and connection disposition separately.
- JSON projections for C1, B, Protected and Verified settlement DTOs. Enum fields use case names and absent fields remain null. B's live PDO handoff uses `HostSnapshotRetirement`, and Verified callbacks return `VerifiedTransactionDecisionCommand`.
- Protected UNIQUE-safe Ordering (B1) for eligible dense InnoDB lists. Hosts can allocate an append position, move rows, or run a registered delete with compaction under one scope anchor.

### Changed

- Raised the Composer PHP patch floors from RC.2's `^8.4`. PHP 8.4.0–8.4.26 and 8.5.0–8.5.11 can no longer resolve this version. The admitted ranges start at 8.4.27 for PHP 8.4 and 8.5.12 for PHP 8.5, with forward eligibility within PHP 8.
- Raw Ordering allocation through `ScopedOrderingManager::getNextPositionForUpdate()` stops after 64 locking tail reads by default. The optional `maxTailReads` argument accepts 2–512. A tail that would have stabilized later under RC.2 can now raise the existing `OrderingExecutionException` with `TAIL_READ_LIMIT_EXCEEDED` metadata. Existing positional and named calls remain valid. The Host retains control of transaction completion and acquired locks.
- Raw and protected `moveWithinScope()` validate `updatedAtValue` before issuing SQL. When `updatedAtColumn` is configured, provide a valid `YYYY-MM-DD HH:MM:SS` value with an optional 1–6 digit fraction. This narrows RC.2's driver-dependent acceptance. Convert other formats in the Host before calling the manager.

### Fixed

- Preserve the original transaction or savepoint failure when the cleanup state check also fails.
- Attempt to release count and data cursors after pagination read, validation, or mapper failures while preserving the original error.
- Reject terminal newlines in trusted SQL identifiers, named binding keys, decimal COUNT values and direct result sort keys.

## [1.0.0-rc.2] - 2026-11-18

Second release candidate, published on [Packagist](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2).

### Changed

- Documented free written licenses and registration for security advisories and critical update notices.

- Recorded Maatify Ltd. as the copyright holder and licensor.

### Fixed

- Ordering reports PDO statement failures as exceptions across error modes. Failed reads cannot report an empty scope or a missing row, and a failed range shift or target update enters the transaction rollback path.
- Ordering rejects unrepresentable stored positions and next-position overflow with `OrderingExecutionException`.

## [1.0.0-rc.1] - 2026-11-12

First release candidate, published on [Packagist](https://packagist.org/packages/maatify/php-persistence).

### Added

- Package-wide exception marker `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`.
- Transaction capability: `PdoTransactionRunner` and `PdoSavepointTransactionRunner`, their exceptions, and optional bounded (0–3) deadlock replay for a package-owned transaction.
- Pagination capability: `PdoPaginator`, returning a validated `PageResultDTO`, with whitelist-sorted, bounded, parameterized pagination and a documented non-single-snapshot caveat for its count queries.
- Ordering capability: `ScopedOrderingManager` and `ScopedOrderingConfig`, with global/scoped/nullable-scoped integer ordering, a plain next-position read, a locking next-position read, and a locked, clamped, savepoint-protected move.
- `PHP_PERSISTENCE_PACKAGE_REFERENCE.md` as the canonical Public Runtime API, behavior, and exception reference; `docs/guides/USAGE_GUIDE.md`; runnable, Public-API-only `examples/`; and `llms.txt`.
- External Composer consumer verification and legacy coexistence checks on real MySQL, including snapshot, savepoint, and deadlock replay evidence.
- Release Artifact Verification for the exact Git and Composer archives of this target.
- Composer manifest, PHPStan at level `max`, PER-CS 3.1 code-style verification, and a real MySQL/MariaDB Integration test infrastructure (`scripts/integration.php`, `compose.integration.yaml`, `docker/integration-matrix.json`).

No database version range is declared as supported; see [PHP_PERSISTENCE_PACKAGE_REFERENCE.md §8](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#8-database-engine-support). Durable decisions governing the changes above are indexed in [docs/decisions/DECISIONS_INDEX.md](docs/decisions/DECISIONS_INDEX.md).

[1.0.0-rc.2]: https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1
