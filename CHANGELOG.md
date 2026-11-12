# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

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

[1.0.0-rc.1]: https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1
