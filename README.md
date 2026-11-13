<div align="center">

# PHP Persistence

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

[![Status](https://img.shields.io/badge/Status-Release%20Candidate-blue)](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1)
[![Version](https://img.shields.io/badge/Version-1.0.0--rc.1-blue)](https://packagist.org/packages/maatify/php-persistence)
[![PHP](https://img.shields.io/badge/PHP-%5E8.4-8892BF)](composer.json)
[![License](https://img.shields.io/badge/License-Proprietary-green)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](README.md#quality-status)

[![Registry](https://img.shields.io/badge/Registry-Packagist-F28D1A)](https://packagist.org/packages/maatify/php-persistence)
[![Monthly downloads](https://img.shields.io/packagist/dm/maatify/php-persistence)](https://packagist.org/packages/maatify/php-persistence/stats)
[![Total downloads](https://img.shields.io/packagist/dt/maatify/php-persistence)](https://packagist.org/packages/maatify/php-persistence/stats)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Docs-Usage_Guide-blue)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Docs-Examples-blue)](examples/)
[![Package Reference](https://img.shields.io/badge/Docs-Package_Reference-blue)](PHP_PERSISTENCE_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Docs-Changelog-blue)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Docs-Security_Policy-blue)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Docs-Contributing_Guide-blue)](CONTRIBUTING.md)

Direct-PDO Transaction, Pagination, and Ordering utilities for MySQL and MariaDB.

</div>

---

## Overview

`maatify/php-persistence` is a small, direct-PDO toolkit with three independent capabilities over a **caller-supplied** `PDO` connection: running callbacks inside a transaction or a caller's savepoint (with optional bounded deadlock replay), parameterized whitelist-sorted bounded pagination, and locking next-position/move operations for integer display-order columns. It is developed as a separate package from the earlier, published `maatify/persistence` ([coexistence rules](docs/decisions/DEC-005-coexistence-with-legacy-package.md)), not a drop-in replacement.

**Release Candidate `1.0.0-rc.1`** is published on [Packagist](https://packagist.org/packages/maatify/php-persistence) and available as a [GitHub pre-release](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1). The package has no Stable release.

## Key Features

- **Transaction** — `PdoTransactionRunner` / `PdoSavepointTransactionRunner`: participate in a caller's active transaction (using a savepoint when one is needed) or own a new transaction; fail-closed on a `BEGIN`/`COMMIT` that returns `false` instead of throwing; optional bounded (0–3) deadlock replay for a package-owned transaction, off by default.
- **Pagination** — `PdoPaginator::paginate()`: normalizes untrusted `page`/`perPage`/sort input, runs two independent count queries plus a bounded, sorted data query, and returns a validated `PageResultDTO` (array/JSON-serializable).
- **Ordering** — `ScopedOrderingManager`: global, scoped, or nullable-scoped integer ordering with a plain next-position read, a locking next-position read for inserts, and a locked, clamped, savepoint-protected `moveWithinScope()`.
- Host-agnostic: no connection factory, no schema ownership, no ORM, no HTTP/host logic. The host owns the `PDO` connection, the tables, and their meaning.

## Requirements

Declared in [`composer.json`](composer.json), which is the governing reference:

- PHP `^8.4`
- `ext-pdo`, `ext-pdo_mysql`
- `maatify/exceptions ^1.0`

These are declared runtime requirements, not a statement that any specific database version is supported or verified — see [Database support](#database-support) below.

## Installation

Install the exact release candidate from Packagist:

```bash
composer require maatify/php-persistence:1.0.0-rc.1
```

See the [changelog](CHANGELOG.md) and [security policy](SECURITY.md) for release and support details.

## Usage

Every capability takes a `PDO` connection the host already created and owns. A minimal Transaction example:

```php
<?php

use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

$runner = new PdoTransactionRunner($pdo); // $maxDeadlockRetries defaults to 0 (no replay)

$orderId = $runner->run(function () use ($pdo): int {
    $pdo->prepare('INSERT INTO orders (customer_id) VALUES (:customer_id)')
        ->execute(['customer_id' => 42]);

    return (int) $pdo->lastInsertId();
});
```

See [docs/guides/USAGE_GUIDE.md](docs/guides/USAGE_GUIDE.md) for a capability map covering Transaction, Pagination, and Ordering, each with its own `Input → Public Call → Result → Boundary` walkthrough, and [examples/](examples/) for complete, runnable scripts.

## Public Runtime API

The public surface is three independent capability namespaces under `Maatify\PhpPersistence\`:

- `Transaction\Repository\{TransactionRunnerInterface, SavepointTransactionRunnerInterface, PdoTransactionRunner, PdoSavepointTransactionRunner}` and `Transaction\Exception\{InvalidTransactionConfigurationException, TransactionExecutionException}`.
- `Pagination\{Criteria\PageRequest, Config\PaginationConfig, ValueObject\SortWhitelist, Criteria\PdoPaginationQueryDescriptor, Repository\PdoPaginator, DTO\PageResultDTO, Enum\SortDirectionEnum}` and `Pagination\Exception\{InvalidPaginationConfigurationException, InvalidPaginationQueryException, PaginationExecutionException}`.
- `Ordering\{Config\ScopedOrderingConfig, Repository\ScopedOrderingManager}` and `Ordering\Exception\{InvalidOrderingConfigurationException, InvalidOrderingOperationException}`.
- One package-wide exception marker: `Exception\PhpPersistenceExceptionInterface`.

Two internal helper classes (`Transaction\Repository\{OwnedTransactionExecutor, SavepointScope}`) are reachable but are explicitly **not** part of the public contract or SemVer.

The complete, verified inventory — every constructor, named parameter, default, return type, thrown exception, and behavioral guarantee — is the canonical [PHP_PERSISTENCE_PACKAGE_REFERENCE.md](PHP_PERSISTENCE_PACKAGE_REFERENCE.md). This section is an overview only and is not repeated there in full.

## Critical Runtime Behavior

- **Caller-owned vs. package-owned transactions:** every runner and `moveWithinScope()` participates in an already-active transaction without ever beginning, committing, or finally rolling it back; it owns a transaction only when none was already active. The two transaction runners can opt into replay on that path. Ordering does not enable replay. See [Package Reference §4 and §7](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#4-ownership-vocabulary).
- **Pagination runs the counts and optional data query separately.** The caller controls snapshot consistency through its transaction, isolation level, and SQL read semantics. A `READ COMMITTED` transaction can still observe concurrent commits between statements. See [Package Reference §5.3](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#53-pdopaginatorpaginate).
- **MariaDB `innodb_snapshot_isolation=ON`** can make a locking Ordering read fail with driver error `1020` after the server has already rolled back the caller's whole transaction. This is a documented hazard, not a handled case: no retry, no savepoint recovery, and no setting change is attempted. See [Package Reference §7.3](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#73-mariadb-innodb_snapshot_isolation-hazard).
- Deadlock replay is opt-in, bounded to 3 retries after the initial attempt, and applies only to a package-owned transaction under the exact SQLSTATE `40001`/driver-code `1213` signature.

## Exception and Error Propagation

Every exception the package defines implements `Exception\PhpPersistenceExceptionInterface`, so a consumer can catch all package-defined failures with one type. A PDO or callback failure that propagates retains the original exception. Cleanup can suppress secondary failures and an eligible owned deadlock may be recovered by replay. See [Package Reference §6](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#6-exceptions-reference) for the full exception table and the inherited `maatify/exceptions` constructor shape.

## Security and Trust Boundaries

Table and column identifiers passed to `ScopedOrderingConfig` and sort-key identifier paths passed to `SortWhitelist` are validated and quoted, but they are **not** bound as PDO parameters (SQL does not allow binding identifiers) — they must be trusted application constants, never raw user input. Ordering values and pagination filter parameters are bound. Pagination sort keys select validated identifiers from a whitelist and sort directions resolve to enum values. The caller supplies trusted pagination SQL.

## Examples

Runnable, Public-API-only examples live in [examples/](examples/): one each for Transaction, Pagination, and Ordering. Every example requires a real MySQL/MariaDB connection and is validated in CI using this repository's existing Integration infrastructure (no example invents a second database stack).

## Documentation

- [PHP_PERSISTENCE_PACKAGE_REFERENCE.md](PHP_PERSISTENCE_PACKAGE_REFERENCE.md) — canonical Public Runtime API, behavior, and exception contract.
- [docs/guides/USAGE_GUIDE.md](docs/guides/USAGE_GUIDE.md) — capability map and walkthroughs.
- [examples/](examples/) — runnable examples.
- [CHANGELOG.md](CHANGELOG.md) · [SECURITY.md](SECURITY.md) · [CONTRIBUTING.md](CONTRIBUTING.md)
- [llms.txt](llms.txt) — AI consumer navigation map.
- [docs/architecture/ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md) — design and topology (dated design record).

## Database support

Neither MySQL nor MariaDB has a declared "supported range" today; both are design targets. The Transaction, Pagination, and Ordering capabilities' own tests (not just infrastructure checks) pass against specific named versions — MySQL `8.0.16`, `8.4.13`, `9.7.4`, and MariaDB `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3` — which is "version tested" evidence, narrower than a declared "range verified" claim. See [docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md](docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md) §1 for what each term means and [PHP_PERSISTENCE_PACKAGE_REFERENCE.md §8](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#8-database-engine-support) for the current, verified claim.

## Quality Status

CI requires PHPStan at level `max` with zero errors, PER Coding Style 3.1 checks, Unit and Regression suites on PHP 8.4 and 8.5 with latest and lowest compatible dependencies, and real-database Integration and example smoke checks. Integration exercises genuine deadlocks, a deterministic concurrent-writer snapshot test, and stale-snapshot concurrency. See [GitHub Actions](https://github.com/Maatify-ltd/php-persistence/actions/workflows/ci.yml) for results by commit and [CI and Integration](docs/architecture/CI_AND_INTEGRATION.md) for the matrix.

## Development and Testing

```bash
composer analyse        # PHPStan level max
composer format:check   # PER-CS 3.1, non-mutating
composer test:unit      # no Docker needed
composer test:regression
composer test:integration   # needs Docker; starts a disposable MySQL/MariaDB service
composer examples:smoke     # needs Docker; smoke-executes examples/ against a real engine
```

See [docs/architecture/QUALITY_TOOLING.md](docs/architecture/QUALITY_TOOLING.md), [docs/architecture/CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md), and [CONTRIBUTING.md](CONTRIBUTING.md) for the full local-verification contract.

## License

This package is proprietary. For use under the terms on this branch, request
a free written license from [support@maatify.dev](mailto:support@maatify.dev).
Include your organization name, if applicable, and an email address for
security advisories and critical update notices. Maatify uses these details
to administer licenses and send those notices. See [LICENSE](LICENSE) for
the terms. Published versions carry their own license file.

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
