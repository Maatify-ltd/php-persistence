# Usage Guide

Consumer-facing integration guidance for `maatify/php-persistence`. This guide explains how to use the three capabilities; it does not define a technical contract of its own — every normative rule here is a presentation of what [PHP_PERSISTENCE_PACKAGE_REFERENCE.md](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) already defines. Where the two disagree, the Package Reference governs.

See the [README](../../README.md#installation) for installation of the published `1.0.0-rc.2` package. The optional C1 and C2 sections describe the current repository source.

## Before you start

- Every capability operates on a `PDO` connection **you** create, configure, and own. The package never opens a connection, never changes a PDO attribute, and never changes a session/server setting.
- Every capability is independent to use: you may use only Transaction, only Pagination, or only Ordering. Internally, `Ordering → Transaction` (Ordering reuses the Transaction capability's runner and savepoint helper); `Pagination` depends on neither.
- **Non-goals:** no connection factory, no schema/migration tool, no ORM, no HTTP/host logic, no operational reporting API (see [Package Reference §1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#1-purpose-and-boundaries)). Do not look in `tests/` or `src/` for usage guidance — this guide and [examples/](../../examples/) are the supported consumer path.
- Every database version claim in this guide is the same narrow claim the README and Package Reference make: "version tested" on named MySQL/MariaDB versions, not a declared supported range.

## Capability map

| Capability | Primary call | Walkthrough | Example |
|---|---|---|---|
| Transaction | `PdoTransactionRunner::run()` / `PdoSavepointTransactionRunner::run()` | [§1](#1-transaction) | [examples/transaction_basic_usage.php](../../examples/transaction_basic_usage.php) |
| Transaction · Optional verified checkout (current source) | `PdoVerifiedTransactionCheckout::attachExclusive()` then `runOwned()` or `beginHost()` and `runInHost()` | [Verified checkout](#optional-verified-checkout-c2) | [examples/verified_transaction_usage.php](../../examples/verified_transaction_usage.php) |
| Pagination | `PdoPaginator::paginate()` | [§2](#2-pagination) | [examples/pagination_basic_usage.php](../../examples/pagination_basic_usage.php) |
| Pagination · Optional static composite sort (current source) | `PdoPaginator::paginateWithStaticSort()` | [Static composite sorting](#optional-static-composite-sorting) | [examples/pagination_static_sort_usage.php](../../examples/pagination_static_sort_usage.php) |
| Pagination · Optional dynamic sort (unreleased source) | `PdoPaginator::paginateWithDynamicSort()` | [Dynamic sorting](#optional-dynamic-sorting) | [examples/pagination_dynamic_sort_usage.php](../../examples/pagination_dynamic_sort_usage.php) |
| Ordering | `ScopedOrderingManager::moveWithinScope()` (and the next-position reads) | [§3](#3-ordering) | [examples/ordering_basic_usage.php](../../examples/ordering_basic_usage.php) |

## Run the examples

With Composer dependencies installed and Docker running, `composer examples:smoke` provisions a disposable database and runs the examples against it.

For a standalone run, use a disposable database with InnoDB available. The account must have `CREATE`, `INSERT`, `SELECT`, `UPDATE`, and `DROP` privileges in that database. Set `PHP_PERSISTENCE_IT_HOST`, `PHP_PERSISTENCE_IT_PORT`, `PHP_PERSISTENCE_IT_DATABASE`, `PHP_PERSISTENCE_IT_USER`, and `PHP_PERSISTENCE_IT_PASSWORD` in the environment, then run the example from the repository root:

```bash
php examples/transaction_basic_usage.php
php examples/pagination_basic_usage.php
php examples/pagination_snapshot_usage.php
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

The callback owns its PDO statements. Check failure return values from those statements and throw from the callback so an owned transaction follows the rollback path.

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
        $statement = $pdo->prepare('INSERT INTO invoices (total_cents) VALUES (:total)');
        if ($statement === false || !$statement->execute(['total' => 1999])) {
            throw new RuntimeException('Could not insert the invoice.');
        }

        $id = $pdo->lastInsertId();
        if ($id === false) {
            throw new RuntimeException('Could not read the new invoice ID.');
        }

        return (int) $id;
    });
    // Result: the callback value. If run() began the transaction, it committed
    // before returning. Otherwise, the caller still decides whether to commit.
} catch (TransactionExecutionException $e) {
    // Boundary: BEGIN/COMMIT returned false instead of throwing, or a transaction-
    // control statement failed. A BEGIN failure prevents the callback from
    // running. An owned COMMIT failure triggers a best-effort rollback.
    throw $e;
} catch (\Throwable $e) {
    // Boundary: the callback's own failure, rethrown unchanged after a best-effort
    // rollback (only when this call owned the transaction).
    throw $e;
}
```

Use `PdoSavepointTransactionRunner` instead of `PdoTransactionRunner` when the same code is also called from inside a larger caller-owned transaction and you need operation-local, best-effort rollback while the caller’s transaction remains active. See [Package Reference §5.1–§5.2 and §7](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#5-behavior-contracts) for the full ownership and deadlock-replay rules, including why replay only ever applies to a package-owned transaction.

### Optional verified checkout (C2)

Use Verified Checkout when your callback needs to choose whether to keep or undo provisional InnoDB writes and you need confirmation of the required transaction or savepoint control before receiving its payload. This API is available only in the current repository source. The published `v1.0.0-rc.2` predates it.

The Host supplies an idle, non-persistent `PDO` or `Pdo\Mysql` connection from `pdo_mysql` with native prepares, exception mode, the default statement class and autocommit enabled. Disable multi-statements at construction and clear any pending transaction settings or resources before attachment. Reserve the connection for this checkout throughout the delegation, including when it comes from a pool. Prevent raw alias use, reconnect and multiplexing. Supply a trusted command catalog whose SQL, including triggers and routines, preserves the transaction.

#### Walkthrough — Input → Public Call → Result → Boundary

1. **Input** — Register each transaction-preserving command in a `ProtectedTransactionCommandCatalog` and pass the catalog and PDO to `PdoVerifiedTransactionCheckout::attachExclusive()`. The callback receives a `VerifiedTransactionCommandsInterface` grant. Use its `runRegisteredCommand()` with an ID and matching named bindings, then return `VerifiedTransactionDecisionDTO::apply($payload)` or `VerifiedTransactionDecisionDTO::discard($payload)`. Both decisions accept `false` or `null` as payload.
2. **Public call** — In **Owned** mode, call `runOwned($operation)`. The checkout starts and ends the transaction. In **Host** mode, call `beginHost()`, optionally run registered Host commands, and call `runInHost($operation)` for one savepoint-scoped operation. The Host can continue with registered commands after a confirmed operation and chooses the outer `commit()` or `rollBack()`.
3. **Result** — In Owned mode, the payload follows a confirmed commit or rollback and an idle-session check. In Host mode, Apply records `PENDING_HOST` after savepoint release and confirmed continuity. Discard records `LOCAL_UNDONE` once rollback to the savepoint is acknowledged. Returning the Discard payload also requires acknowledged release and confirmed continuity. Read `settlement()` after return or exception for the operation outcome, transaction outcome and PDO disposition. A later Host end preserves the operation outcome.
4. **Boundary** — An exception escaping the callback keeps its identity. An uncertain control result suppresses the payload. A lost end reply leaves the transaction outcome `UNKNOWN` and the connection `DISCARD_REQUIRED`. A failed final connection check can require discard even when the database outcome is known. Return the original PDO once with `returnForHost()` after `REUSABLE` or an untouched `UNCHANGED` cancellation. For `DISCARD_REQUIRED`, retire it with `discardForHost()` and reconcile any uncertain database effect before deciding whether to repeat work. The checkout performs no replay. Its guarantees cover eligible transactional effects under exclusive delegation.

Run `php examples/verified_transaction_usage.php` from the root of the current source checkout with the disposable database settings above. The example covers Owned Apply and Discard, Host Discard followed by an outer commit, and settlement after a callback throws. See the [runnable example](../../examples/verified_transaction_usage.php) and the [complete Verified Checkout contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#522-optional-verified-apply-and-discard).

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

### Optional static composite sorting

Use this path when the Host wants a named order such as status ascending, priority descending, then unique row ID ascending. It is available in the unreleased repository source.

- **Input** — build `StaticSortPlan` once with trusted identifier paths and a profile for each key this query should offer. Put the primary column in the whitelist and specify the trailing terms in order. Omit a term direction to inherit the requested primary direction. Use an explicit `null` profile for the standard primary/tie-breaker order.
- **Public call** — pass the descriptor, ordinary `PageRequest`, plan, and mapper to `PdoPaginator::paginateWithStaticSort()`.
- **Result** — the existing `PageResultDTO` reports the effective primary key and direction. The [runnable example](../../examples/pagination_static_sort_usage.php) reuses one plan for three pages and asserts IDs `5,2,1,3,4` in order.
- **Boundary** — the profile keys are the allowed set for this path. A whitelisted key omitted from the plan fails before query preparation. The Host defines a unique order on the result and controls PDO, SQL, and the transaction. See the [static sort contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#534-optional-static-composite-offset-pagination).

### Optional dynamic sorting

Use this Offset path when a grid or other caller selects up to three sort keys in priority order and chooses a direction for each. The API is in the unreleased source. The published RC.2 uses the standard pagination path.

- **Input** — translate the grid's request into a list such as `[['key' => 'status', 'direction' => 'asc'], ['key' => 'priority', 'direction' => 'desc']]`. Configure a whitelist of public keys and a unique tie column for this query. Supply only page and page size in `PageRequest`.
- **Public call** — pass the descriptor, request, configuration, sort list and mapper to `PdoPaginator::paginateWithDynamicSort()`. An empty list uses the configured default order.
- **Result** — `MultiSortPageResultDTO::page` holds the existing page. The two sort lists expose the normalized selection and the effective order. An automatically appended tie appears with `key: null` and its actual direction. The [runnable example](../../examples/pagination_dynamic_sort_usage.php) checks the resulting order across three pages.
- **Boundary** — the Host owns the SQL, identifiers, connection and unique ordering on the actual result rows. `allowTieBreakerSelection` is a Host policy for the configured public tie key. To reserve the physical column from selection also omit its public aliases. Sort order alone provides no shared snapshot across requests. See the [dynamic sort contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#535-optional-dynamic-offset-sorting).

### Optional C1 snapshot pagination

C1 is available in the current repository source. Use the existing caller-controlled paginator with the published RC.2 artifact.

Use C1 when all reads for one page can run through the same eligible InnoDB connection and the Host can grant that connection exclusively. Start with a new non-persistent PDO reader with server autocommit enabled, effective REPEATABLE READ and PDO exception mode. The Host proves the connection has no active transaction or pending next-transaction policy.

1. Build the same descriptor, request and configuration used for standard pagination.
2. Create the one-shot operation with `PdoSnapshotPaginator::forExclusiveRead($pdo)` and call `paginate()`.
3. On return or exception, inspect `connectionOutcome()` on that operation. The Host can use `REUSABLE` after confirmed cleanup, retains its policy for `UNCHANGED`, and retires a `DISCARD` checkout. Hold the connection out of circulation if an attempted call finishes without an outcome. Preserve the page failure if Host settlement also fails.

The [snapshot example](../../examples/pagination_snapshot_usage.php) runs successful and failing reads against a disposable database. To run it from an independent Composer project using the candidate package, copy the example and `examples/_bootstrap.php` into that project's `examples/` directory. It loads that project's production autoloader. The package's [C1 contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#531-optional-c1-snapshot-pagination) defines the eligibility, read scope and settlement rules.

---

## 3. Ordering

**Fit:** you have a table with an integer display-order column (optionally scoped by another column, e.g. one ordering sequence per `category_id`) and need to allocate the next position for an insert, or move an existing row to a new position safely under concurrency.

**Requirements:** the table's identifiers (table, id column, order column, and optionally a scope column, a soft-delete column, and an updated-at column) as **trusted application constants** — never raw user input, since they are quoted, not bound as parameters.

Use an InnoDB table for the locking and rollback behavior shown here. A failed move on a nontransactional table can leave rows shifted. Before choosing `moveWithinScope()`, check whether a key enforces unique positions globally or within each scope. The range shift can temporarily collide with the target row's original position. If your schema requires that key, use a move strategy that maintains uniqueness during each update. See the [Ordering contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager).

**Non-goals:** the manager does not create or alter the table, does not maintain a cache of positions, and does not fix a transaction the server has already rolled back (the MariaDB hazard in §7.3 of the Package Reference).

**Inputs:** a `PDO` connection, a `ScopedOrderingConfig`, a scope value (or `null` for global/nullable-scope ordering), and — for a move — a row id and target order.
**Outputs:** `int` (next position) or `bool` (whether the move succeeded, including a no-op).
**Boundary:** `moveWithinScope()` always locks the whole scope and re-reads the current order from the database before deciding anything; it never trusts a caller-supplied "current order".

### Append the first item in an empty scope

In this example the host owns two InnoDB tables. `shipping_methods.id` is a primary key. `shipping_rates` has an auto-generated `id`, a `method_id`, and a `display_order`. The method row already exists even when it has no rates. All writers that can change the order in that scope must lock the same method row before reading or writing positions. The host chooses that row and keeps the lock until the transaction ends.

Use the same PDO connection to lock the parent row, read the next position, and insert. This works for an empty scope and for later appends.

```php
<?php

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

// $pdo is the host's PDO connection. Shipping method 7 already exists.
$methodId = 7;
$config = new ScopedOrderingConfig(table: 'shipping_rates', scopeColumn: 'method_id', deletedAtColumn: null);
$manager = new ScopedOrderingManager();

$nextPosition = (new PdoTransactionRunner($pdo))->run(
    static function () use ($pdo, $manager, $config, $methodId): int {
        $parent = $pdo->prepare('SELECT id FROM shipping_methods WHERE id = :id FOR UPDATE');
        if ($parent === false || !$parent->execute(['id' => $methodId])) {
            throw new RuntimeException('Could not lock the shipping method.');
        }
        if ($parent->fetchColumn() === false || !$parent->closeCursor()) {
            throw new RuntimeException('Could not complete the lock read for an existing shipping method.');
        }

        $position = $manager->getNextPositionForUpdate($pdo, $config, scopeValue: $methodId);
        $statement = $pdo->prepare('INSERT INTO shipping_rates (method_id, display_order) VALUES (:method, :position)');
        if ($statement === false || !$statement->execute(['method' => $methodId, 'position' => $position])) {
            throw new RuntimeException('Could not insert the shipping rate.');
        }

        return $position;
    },
);
```

If the runner starts the transaction, it commits the insert before returning the position. If the host already owns a transaction on this PDO, the host decides when to commit or roll back. A missing parent or a failed statement stops the operation. PDO exceptions propagate to the transaction owner.

Run `composer examples:smoke` to see the [standalone example](../../examples/ordering_basic_usage.php) create disposable tables, assert that the first two inserts in an initially empty scope use positions 1 and 2, and demonstrate a move in a populated scope.

### What the lock and the reads guarantee

- The parent row gives writers the same lock target even when there are no child rows. `MAX(display_order) + 1` computes a candidate position. Taking the parent row lock with `FOR UPDATE` and inserting in the same transaction serialize competing writers. `getNextPositionForUpdate()` alone cannot reserve the first position in an empty scope.
- `getNextPosition()` is a plain read for previews. Under `REPEATABLE READ`, an older snapshot stays in effect after the parent lock is acquired. Taking the lock before the transaction establishes its consistent-read snapshot lets the first plain read see the previous writer's commit. Use `getNextPositionForUpdate()` inside the write transaction to allocate a position.
- With MariaDB's `innodb_snapshot_isolation=ON`, a locking read that encounters a row changed after an earlier `REPEATABLE READ` snapshot can fail with error `1020`. The server may already have rolled back the transaction while PDO still reports it active. Stop the operation and let the host handle the error. See the [MariaDB snapshot-isolation behavior](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#73-mariadb-innodb_snapshot_isolation-hazard) and the [protected write checkout decision](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md).
