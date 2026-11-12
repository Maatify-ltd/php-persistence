# Usage Guide

Consumer-facing integration guidance for `maatify/php-persistence`. This guide explains how to use the three capabilities; it does not define a technical contract of its own — every normative rule here is a presentation of what [PHP_PERSISTENCE_PACKAGE_REFERENCE.md](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) already defines. Where the two disagree, the Package Reference governs.

**Artifact target:** Release Candidate `1.0.0-rc.1`. See the [README](../../README.md#installation) for the exact installation target and when it resolves.

## Before you start

- Every capability operates on a `PDO` connection **you** create, configure, and own. The package never opens a connection, never changes a PDO attribute, and never changes a session/server setting.
- Every capability is independent to use: you may use only Transaction, only Pagination, or only Ordering. Internally, `Ordering → Transaction` (Ordering reuses the Transaction capability's runner and savepoint helper); `Pagination` depends on neither.
- **Non-goals:** no connection factory, no schema/migration tool, no ORM, no HTTP/host logic, no operational reporting API (see [Package Reference §1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#1-purpose-and-boundaries)). Do not look in `tests/` or `src/` for usage guidance — this guide and [examples/](../../examples/) are the supported consumer path.
- Every database version claim in this guide is the same narrow claim the README and Package Reference make: "version tested" on named MySQL/MariaDB versions, not a declared supported range.

## Capability map

| Capability | Primary call | Walkthrough | Example |
|---|---|---|---|
| Transaction | `PdoTransactionRunner::run()` / `PdoSavepointTransactionRunner::run()` | [§1](#1-transaction) | [examples/transaction_basic_usage.php](../../examples/transaction_basic_usage.php) |
| Pagination | `PdoPaginator::paginate()` | [§2](#2-pagination) | [examples/pagination_basic_usage.php](../../examples/pagination_basic_usage.php) |
| Ordering | `ScopedOrderingManager::moveWithinScope()` (and the next-position reads) | [§3](#3-ordering) | [examples/ordering_basic_usage.php](../../examples/ordering_basic_usage.php) |

## Run the examples

With Composer dependencies installed and Docker running, `composer examples:smoke` provisions a disposable database and runs all three examples.

For a standalone run, use a disposable database with InnoDB available. The account must have `CREATE`, `INSERT`, `SELECT`, `UPDATE`, and `DROP` privileges in that database. Set `PHP_PERSISTENCE_IT_HOST`, `PHP_PERSISTENCE_IT_PORT`, `PHP_PERSISTENCE_IT_DATABASE`, `PHP_PERSISTENCE_IT_USER`, and `PHP_PERSISTENCE_IT_PASSWORD` in the environment, then run the example from the repository root:

```bash
php examples/transaction_basic_usage.php
php examples/pagination_basic_usage.php
php examples/ordering_basic_usage.php
```

---

## 1. Transaction

**Fit:** you have a block of PDO statements that must either all succeed or all fail together, and you may or may not already be inside a transaction (for example, this code is called both standalone and from inside a larger unit of work).

**Requirements:** a `PDO` connection with `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION` recommended (so statement failures surface as exceptions your callback can let propagate).

**Non-goals:** the runner does not retry a caller-owned transaction, does not retry anything other than the exact deadlock signature it documents, and does not inspect or change isolation level/autocommit.

**Inputs:** a `PDO` connection, an optional deadlock-retry limit (`0`–`3`, default `0`), and a `callable(): TResult` callback.
**Outputs:** the callback's return value, or the callback's original `Throwable` rethrown unchanged.
**Boundary:** the runner never begins/commits/rolls back a transaction it did not itself begin.

### Walkthrough: `Input → Public Call → Result → Boundary`

```php
<?php

use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;

// Input: a PDO connection the host already owns, and a unit of work.
$runner = new PdoTransactionRunner($pdo, maxDeadlockRetries: 1);

try {
    // Public Call: run() decides ownership for you.
    $invoiceId = $runner->run(function () use ($pdo): int {
        $pdo->prepare('INSERT INTO invoices (total_cents) VALUES (:total)')
            ->execute(['total' => 1999]);

        return (int) $pdo->lastInsertId();
    });
    // Result: the callback value. If run() began the transaction, it committed
    // before returning. Otherwise, the caller still decides whether to commit.
} catch (TransactionExecutionException $e) {
    // Boundary: BEGIN/COMMIT returned false instead of throwing, or a transaction-
    // control statement failed. A BEGIN failure prevents the callback from
    // running. An owned COMMIT failure triggers a best-effort rollback.
} catch (\Throwable $e) {
    // Boundary: the callback's own failure, rethrown unchanged after a best-effort
    // rollback (only when this call owned the transaction).
}
```

Use `PdoSavepointTransactionRunner` instead of `PdoTransactionRunner` when the same code is also called from inside a larger caller-owned transaction and you need operation-local, best-effort rollback while the caller’s transaction remains active. See [Package Reference §5.1–§5.2 and §7](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#5-behavior-contracts) for the full ownership and deadlock-replay rules, including why replay only ever applies to a package-owned transaction.

---

## 2. Pagination

**Fit:** you already have (or can write) SQL for a total count, a filtered count, and an unsorted/unlimited data query, and you want safe, bounded, whitelist-sorted pagination over untrusted `page`/`perPage`/sort input.

**Requirements:** your own SQL text and parameters for the three queries; a sort whitelist naming every column a caller may sort by.

**Non-goals:** `PdoPaginator` does not build SQL from a model, does not open a transaction, and leaves snapshot consistency to the caller’s transaction, isolation level, and SQL read semantics.

**Inputs:** a `PDO` connection, a `PdoPaginationQueryDescriptor` (your SQL + params), a `PageRequest` (raw, untrusted `page`/`perPage`/`sortBy`/`sortDirection`), a `PaginationConfig` (defaults, bounds, sort whitelist), and a row-mapper callable.
**Outputs:** a `PageResultDTO<TItem>`.
**Boundary:** every input number and sort key is normalized or falls back to a safe default; nothing from `PageRequest` reaches SQL unvalidated.

### Walkthrough: `Input → Public Call → Result → Boundary`

```php
<?php

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\{PageRequest, PdoPaginationQueryDescriptor};
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

// Input: accept scalar strings from the query string and discard array values.
$queryString = static fn(string $key): ?string =>
    is_string($_GET[$key] ?? null) ? $_GET[$key] : null;
$request = new PageRequest(
    page: $queryString('page'),
    perPage: $queryString('per_page'),
    sortBy: $queryString('sort'),
    sortDirection: $queryString('direction'),
);

$config = new PaginationConfig(
    sortWhitelist: new SortWhitelist(['created_at' => 'orders.created_at', 'id' => 'orders.id']),
    defaultSortBy: 'created_at',
    defaultSortDirection: SortDirectionEnum::DESC,
    tieBreakerSortBy: 'id',
    tieBreakerDirection: SortDirectionEnum::ASC,
);

$query = new PdoPaginationQueryDescriptor(
    totalSql: 'SELECT COUNT(*) FROM orders',
    totalParams: [],
    filteredCountSql: 'SELECT COUNT(*) FROM orders WHERE status = :status',
    filteredCountParams: ['status' => 'paid'],
    dataSql: 'SELECT orders.id, orders.created_at FROM orders WHERE status = :status',
    dataParams: ['status' => 'paid'],
);

// Public Call: paginate() normalizes $request against $config, then executes
// total/filtered/data against $pdo and maps each row with the callback.
$page = (new PdoPaginator())->paginate($pdo, $query, $request, $config, static fn(array $row): array => $row);

// Result: a validated PageResultDTO.
$payload = $page->toArray(); // ['data' => [...], 'pagination' => ['page' => ..., 'per_page' => ..., ...]]

// Boundary: concurrent commits can make counts and data disagree, even inside
// READ COMMITTED. For one snapshot use consistent, non-locking reads on
// transactional tables under compatible isolation, such as InnoDB REPEATABLE READ.
```

See [Package Reference §3.3 and §5.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#33-pagination-capability--maatifyphppersistencepagination) for every normalization rule and the exact `PaginationExecutionException`/`InvalidPaginationQueryException`/`InvalidPaginationConfigurationException` conditions.

---

## 3. Ordering

**Fit:** you have a table with an integer display-order column (optionally scoped by another column, e.g. one ordering sequence per `category_id`) and need to allocate the next position for an insert, or move an existing row to a new position safely under concurrency.

**Requirements:** the table's identifiers (table, id column, order column, and optionally a scope column, a soft-delete column, and an updated-at column) as **trusted application constants** — never raw user input, since they are quoted, not bound as parameters.

**Non-goals:** the manager does not create or alter the table, does not maintain a cache of positions, and does not fix a transaction the server has already rolled back (the MariaDB hazard in §7.3 of the Package Reference).

**Inputs:** a `PDO` connection, a `ScopedOrderingConfig`, a scope value (or `null` for global/nullable-scope ordering), and — for a move — a row id and target order.
**Outputs:** `int` (next position) or `bool` (whether the move succeeded, including a no-op).
**Boundary:** `moveWithinScope()` always locks the whole scope and re-reads the current order from the database before deciding anything; it never trusts a caller-supplied "current order".

### Walkthrough: `Input → Public Call → Result → Boundary`

```php
<?php

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

// Input: an InnoDB table with id, method_id and display_order columns.
$config = new ScopedOrderingConfig(table: 'shipping_rates', scopeColumn: 'method_id', deletedAtColumn: null);
$manager = new ScopedOrderingManager();

$nextPosition = (new PdoTransactionRunner($pdo))->run(
    function () use ($pdo, $manager, $config): int {
        // Public Call: read the next position and insert before releasing the lock.
        $position = $manager->getNextPositionForUpdate($pdo, $config, scopeValue: 7);
        $pdo->prepare('INSERT INTO shipping_rates (method_id, display_order) VALUES (:method, :position)')
            ->execute(['method' => 7, 'position' => $position]);

        return $position;
    },
);

// Public Call: if no caller transaction is active, the manager owns this move.
$moved = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 42, newOrder: 1);
// Result: true on success (including a no-op); false if the target is absent
// or its update affects zero rows after shifting.
// Boundary: the target is limited to the maximum observed under the scope lock.
```

Use `getNextPosition()` (a plain, non-locking read) only for previews or when no older `REPEATABLE READ` snapshot can exist yet (outside a transaction, under `READ COMMITTED`, or after your own serialization lock); use `getNextPositionForUpdate()` when the position will actually be used for an insert inside the same transaction. Serialize first inserts into an empty scope with a caller-owned lock shared by all competing writers. See [Package Reference §5.4 and §7.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager) for the full locking, clamping, savepoint, and MariaDB-hazard rules.
