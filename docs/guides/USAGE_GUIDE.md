# Usage Guide

Consumer-facing integration guidance for `maatify/php-persistence`. This guide explains how to use the three capabilities; it does not define a technical contract of its own — every normative rule here is a presentation of what [PHP_PERSISTENCE_PACKAGE_REFERENCE.md](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) already defines. Where the two disagree, the Package Reference governs.

This guide covers the Stable `1.0.0` target. Follow the [installation instructions](../../README.md#installation) after publication or [verify an exact source checkout](#verify-an-exact-source-checkout-in-a-clean-consumer). The [RC.2 quickstart](#run-the-published-rc2-examples-from-a-clean-install) applies to that earlier version.

## Before you start

- You create and own the `PDO` connection and control its attributes and session and server defaults. Optional B applies an isolation setting for the next transaction when you explicitly start it through the checkout.
- Every capability is independent to use: you may use only Transaction, only Pagination, or only Ordering. Internally, `Ordering → Transaction` (Ordering reuses the Transaction capability's runner and savepoint helper); `Pagination` depends on neither.
- **Non-goals:** no connection factory, no schema/migration tool, no ORM, no HTTP/host logic, no operational reporting API (see [Package Reference §1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#1-purpose-and-boundaries)). Do not look in `tests/` or `src/` for usage guidance — this guide and [examples/](../../examples/) are the supported consumer path.
- Every database version claim in this guide is the same narrow claim the README and Package Reference make: "version tested" on named MySQL/MariaDB versions, not a declared supported range.

## Capability map

| Capability | Primary call | Walkthrough | Example |
|---|---|---|---|
| Transaction | `PdoTransactionRunner::run()` / `PdoSavepointTransactionRunner::run()` | [§1](#1-transaction) | [examples/transaction_basic_usage.php](../../examples/transaction_basic_usage.php) |
| Transaction · Optional protected Host write checkout | `PdoProtectedTransactionCheckout::attachExclusive()` then `begin()` and `runRegisteredCommand()` | [Protected Host write checkout](#optional-protected-host-write-checkout) | [Protected checkout in the B1 example](../../examples/ordering_unique_usage.php) |
| Transaction · Optional verified checkout | `PdoVerifiedTransactionCheckout::attachExclusive()` then `runOwned()` or `beginHost()` and `runInHost()` | [Verified checkout](#optional-verified-checkout-c2) | [examples/verified_transaction_usage.php](../../examples/verified_transaction_usage.php) |
| Pagination | `PdoPaginator::paginate()` | [§2](#2-pagination) | [examples/pagination_basic_usage.php](../../examples/pagination_basic_usage.php) |
| Pagination · Optional static composite sort | `PdoPaginator::paginateWithStaticSort()` | [Static composite sorting](#optional-static-composite-sorting) | [examples/pagination_static_sort_usage.php](../../examples/pagination_static_sort_usage.php) |
| Pagination · Optional dynamic sort | `PdoPaginator::paginateWithDynamicSort()` | [Dynamic sorting](#optional-dynamic-sorting) | [examples/pagination_dynamic_sort_usage.php](../../examples/pagination_dynamic_sort_usage.php) |
| Pagination · Optional delegated snapshot (C1) | `PdoSnapshotPaginator::forExclusiveRead()` then `paginate()` | [C1 snapshot pagination](#optional-c1-snapshot-pagination) | [examples/pagination_snapshot_usage.php](../../examples/pagination_snapshot_usage.php) |
| Pagination · Optional Host transaction snapshot (B) | `PdoHostSnapshotSource::attachExclusive()` then `PdoHostSnapshotPaginator::paginate()` with an authorized page attempt | [B pagination in a Host transaction](#optional-b-pagination-in-a-host-transaction) | [Host integration example](../../examples/pagination_host_snapshot_usage.php) |
| Pagination · Optional forward-only Keyset | `PdoKeysetPaginator::paginate()` | [Forward-only Keyset pagination](#optional-forward-only-keyset-pagination) | [examples/pagination_keyset_usage.php](../../examples/pagination_keyset_usage.php) |
| Ordering | `ScopedOrderingManager::moveWithinScope()` (and the next-position reads) | [§3](#3-ordering) | [examples/ordering_basic_usage.php](../../examples/ordering_basic_usage.php) |
| Ordering · Optional protected UNIQUE-safe lists (B1) | `ProtectedScopedOrderingManager::getNextUniquePositionForAppend()`, `moveUniqueWithinScope()`, or `deleteUniqueWithinScope()` | [Protected UNIQUE-safe Ordering B1](#protected-unique-safe-ordering-b1) | [examples/ordering_unique_usage.php](../../examples/ordering_unique_usage.php) |

## Run the examples from a 1.0.0 install

After [installing the exact version](../../README.md#installation), choose a standalone example from the capability map. Create an `examples/` directory in your Composer project and copy that file and `_bootstrap.php` from `vendor/maatify/php-persistence/examples/`. Run the copied file with PHP from your project root so it loads your Composer autoloader. Use a disposable InnoDB database with the connection variables and privileges in the example setup below. The B1 example also requires `DELETE`.

Load the [Host Snapshot B example](../../examples/pagination_host_snapshot_usage.php) within an application that has loaded its production Composer autoloader. Use the [Host B walkthrough](#optional-b-pagination-in-a-host-transaction) to attach an eligible idle `pdo_mysql` connection exclusively to a source with a registered InnoDB page plan. Call `Maatify\PhpPersistence\Examples\readHostSnapshotPage()` with that source, the registered plan ID, its bindings, and a page request. The example depends on those application objects and is outside standalone smoke execution. The Host accesses the delegated connection only through its source and checkout. The example discards the source when a normal commit leaves reuse unconfirmed. On failure it attempts to discard the source and rethrows the original failure. After discard or a thrown failure, drop all retained references to the original PDO and keep it out of circulation even if discard could not complete. A returned page does not authorize direct reuse of that PDO.

## Run the published RC.2 examples from a clean install

Use PHP `^8.4` with `pdo_mysql`, Composer, and an empty disposable MySQL or MariaDB database with InnoDB. Give the example account `CREATE`, `DROP`, `SELECT`, `INSERT`, and `UPDATE` on that database. The scripts create and remove their own tables.

```bash
mkdir persistence-rc2-quickstart
cd persistence-rc2-quickstart
cat > composer.json <<'JSON'
{
  "require": {
    "php": "^8.4",
    "ext-pdo_mysql": "*",
    "maatify/php-persistence": "1.0.0-rc.2"
  }
}
JSON
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
mkdir examples
cp vendor/maatify/php-persistence/examples/_bootstrap.php examples/
for example in transaction_basic_usage pagination_basic_usage ordering_basic_usage; do
    cp "vendor/maatify/php-persistence/examples/$example.php" examples/
done
```

Set `PHP_PERSISTENCE_IT_HOST`, `PHP_PERSISTENCE_IT_PORT`, `PHP_PERSISTENCE_IT_DATABASE`, `PHP_PERSISTENCE_IT_USER`, and `PHP_PERSISTENCE_IT_PASSWORD` in the shell's environment to the disposable database credentials. The copied examples load this project's `vendor/autoload.php`.

```bash
php examples/transaction_basic_usage.php
php examples/pagination_basic_usage.php
php examples/ordering_basic_usage.php
```

Each command ends with `OK`. Transaction commits invoice 1 and rolls back the second insert. Pagination shows page 2 with 15 of 25 rows matching and a maximum of 10 rows per page. Ordering appends at position 4 in the seeded scope, moves row 3 to the first position, and verifies an outer rollback. Discard the disposable database when finished.

RC.2 contains the three Basic examples. See the archived [RC.2 Package Reference](https://github.com/Maatify-ltd/php-persistence/blob/v1.0.0-rc.2/PHP_PERSISTENCE_PACKAGE_REFERENCE.md) for that version's public contract.

## Verify an exact source checkout in a clean consumer

From a repository checkout at the exact committed source to verify, compare the printed commit and tree against the intended GitHub `main` or PR HEAD. The candidate is installed by the repository harness in separate Composer roots with production dependencies and autoloading.

```bash
git rev-parse HEAD 'HEAD^{tree}'
PP_DB_CELL=mysql-8.4.13 composer test:consumer
PP_DB_CELL=mariadb-11.8.9 composer test:consumer
```

Each command provisions two disposable services for that engine. Look for `CONSUMER-ARCHIVE` with the commit, tree and archive SHA-256, `CONSUMER-INSTALL` for both scenarios and `CONSUMER VERIFICATION OK (two clean lifecycles)`. See [consumer verification](CONSUMER_VERIFICATION.md) for prerequisites and what the checks establish.

### Run examples from the repository checkout

With the checkout's Composer dependencies installed and Docker running, `composer examples:smoke` provisions a disposable database and runs the standalone examples. To run a standalone example manually, use the disposable database variables above and run the script from the repository root. For Host Snapshot B, follow the [application integration instructions](#run-the-examples-from-a-100-install) above.

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
$runner = new PdoTransactionRunner($pdo);

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

### Optional protected Host write checkout

Use a protected checkout when the Host needs to keep transaction control while routing access to one delegated write connection through registered commands. For example, an invoice service can register an `invoice.updateStatus` command with parameters `id` and `status`.

1. Build a `ProtectedTransactionCommandCatalog` from trusted `ProtectedTransactionCommandPlan` entries. Delegate an eligible idle PDO through `PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog)` and keep raw aliases out of use.
2. Call `$checkout->begin()`. Execute the registered operation through `$checkout->runRegisteredCommand('invoice.updateStatus', ['id' => $invoiceId, 'status' => $status])`. Check the affected-row result against the Host's business rule. For guarded Ordering within the same transaction, use the [protected Ordering path](#protected-ordering-and-domain-constraints).
3. Inspect `settlement()` after an error. A `null` settlement means the checkout has yet to record a terminal outcome. The Host ends a confirmed active transaction with `commit()` or `rollBack()` before any handoff. Read the terminal settlement after attempting to end it. On `Reusable`, retrieve the same connection once with `returnForHost()`. On `DiscardRequired`, stop issuing SQL, call `discardForHost()`, and reconcile any uncertain database effect before deciding whether to repeat the work.

See the [protected Host write contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#521-optional-protected-host-write-transaction) for PDO eligibility, failure reasons and handoff.

### Optional verified checkout (C2)

Use Verified Checkout when your callback needs to choose whether to keep or undo provisional InnoDB writes and you need confirmation of the required transaction or savepoint control before receiving its payload.

The Host supplies an idle, non-persistent `PDO` or `Pdo\Mysql` connection from `pdo_mysql` with native prepares, exception mode, the default statement class and autocommit enabled. Disable multi-statements at construction and clear any pending transaction settings or resources before attachment. Reserve the connection for this checkout throughout the delegation, including when it comes from a pool. Prevent raw alias use, reconnect and multiplexing. Supply a trusted command catalog whose SQL, including triggers and routines, preserves the transaction.

#### Walkthrough — Input → Public Call → Result → Boundary

1. **Input** — Register each transaction-preserving command in a `ProtectedTransactionCommandCatalog` and pass the catalog and PDO to `PdoVerifiedTransactionCheckout::attachExclusive()`. The callback receives a `VerifiedTransactionCommandsInterface` grant. Use its `runRegisteredCommand()` with an ID and matching named bindings, then return `VerifiedTransactionDecisionCommand::apply($payload)` or `VerifiedTransactionDecisionCommand::discard($payload)`. Both decisions accept `false` or `null` as payload.
2. **Public call** — In **Owned** mode, call `runOwned($operation)`. The checkout starts and ends the transaction. In **Host** mode, call `beginHost()`, optionally run registered Host commands, and call `runInHost($operation)` for one savepoint-scoped operation. The Host can continue with registered commands after a confirmed operation and chooses the outer `commit()` or `rollBack()`.
3. **Result** — In Owned mode, the payload follows a confirmed commit or rollback and an idle-session check. In Host mode, Apply records `PendingHost` after savepoint release and confirmed continuity. Discard records `LocalUndone` once rollback to the savepoint is acknowledged. Returning the Discard payload also requires acknowledged release and confirmed continuity. Read `settlement()` after return or exception for the operation outcome, transaction outcome and PDO disposition. A later Host end preserves the operation outcome. The settlement's JSON projection preserves null fields and uses [stable uppercase codes](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#36-settlement-enum-json-codes).
4. **Boundary** — An exception escaping the callback keeps its identity. An uncertain control result suppresses the payload. A lost end reply leaves the transaction outcome `Unknown` and the connection `DiscardRequired`. A failed final connection check can require discard even when the database outcome is known. Return the original PDO once with `returnForHost()` after `Reusable` or an untouched `Unchanged` cancellation. For `DiscardRequired`, retire it with `discardForHost()` and reconcile any uncertain database effect before deciding whether to repeat work. The checkout performs no replay. Its guarantees cover eligible transactional effects under exclusive delegation.

Run `php examples/verified_transaction_usage.php` from the project root with the disposable database settings above. The example covers Owned Apply and Discard, Host Discard followed by an outer commit, and settlement after a callback throws. See the [runnable example](../../examples/verified_transaction_usage.php) and the [complete Verified Checkout contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#522-optional-verified-apply-and-discard).

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
    defaultSortDirection: SortDirectionEnum::Desc,
    tieBreakerSortBy: 'id',
    tieBreakerDirection: SortDirectionEnum::Asc,
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

### Choose a pagination path

| You need | Entry point | Read view and cost |
|---|---|---|
| Numbered pages and counts | `PdoPaginator::paginate()` | Runs total and filtered counts, then the bounded data read when rows match. The Host owns the read view. |
| A fixed tuple for a named sort | `paginateWithStaticSort()` with `StaticSortPlan` | Same Offset reads. The Host supplies the full stable order and its tie. |
| Request-selected sort priority | `paginateWithDynamicSort()` | Same Offset reads. The Host allowlists keys, configures a unique tie, and keeps input adaptation. |
| One consistent page on a delegated idle reader | `PdoSnapshotPaginator::forExclusiveRead()` then `paginate()` | C1 owns one read-only transaction. The Host handles the checkout outcome before reuse. |
| Pages that include earlier writes in the same Host transaction | [B pagination](#optional-b-pagination-in-a-host-transaction) on an idle, exclusively delegated PDO | The Host starts the transaction through the checkout. Registered writes and page reads share the confirmed transaction. |
| Forward continuation without counts | [Keyset pagination](#optional-forward-only-keyset-pagination) | Keyset v1 seeks on one immutable, unique positive integer ID. The authenticated cursor binds the query and Host scope. The Host owns the connection and signing keys. |

Separate Offset requests can drift across writes even with a unique tie. Counts can dominate query cost depending on filters, joins and indexes. Keyset continuation has a separate result contract with no numbered-page or total-count metadata.

### Optional static composite sorting

Use this path when the Host wants a named order such as status ascending, priority descending, then unique row ID ascending.

- **Input** — build `StaticSortPlan` once with trusted identifier paths and a profile for each key this query should offer. Put the primary column in the whitelist and specify the trailing terms in order. Omit a term direction to inherit the requested primary direction. Use an explicit `null` profile for the standard primary/tie-breaker order.
- **Public call** — pass the descriptor, ordinary `PageRequest`, plan, and mapper to `PdoPaginator::paginateWithStaticSort()`.
- **Result** — the existing `PageResultDTO` reports the effective primary key and direction. The [runnable example](../../examples/pagination_static_sort_usage.php) reuses one plan for three pages and asserts IDs `5,2,1,3,4` in order.
- **Boundary** — the profile keys are the allowed set for this path. A whitelisted key omitted from the plan fails before query preparation. The Host defines a unique order on the result and controls PDO, SQL, and the transaction. See the [static sort contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#534-optional-static-composite-offset-pagination).

### Optional dynamic sorting

Use this Offset path when a grid or other caller selects up to three sort keys in priority order and chooses a direction for each.

- **Input** — translate the grid's request into a list such as `[['key' => 'status', 'direction' => 'asc'], ['key' => 'priority', 'direction' => 'desc']]`. Configure a whitelist of public keys and a unique tie column for this query. Supply only page and page size in `PageRequest`.
- **Public call** — pass the descriptor, request, configuration, sort list and mapper to `PdoPaginator::paginateWithDynamicSort()`. An empty list uses the configured default order.
- **Result** — `MultiSortPageResultDTO::page` holds the existing page. The two sort lists expose the normalized selection and the effective order. An automatically appended tie appears with `key: null` and its actual direction. The [runnable example](../../examples/pagination_dynamic_sort_usage.php) checks the resulting order across three pages.
- **Boundary** — the Host owns the SQL, identifiers, connection and unique ordering on the actual result rows. `allowTieBreakerSelection` is a Host policy for the configured public tie key. To reserve the physical column from selection also omit its public aliases. Sort order alone provides no shared snapshot across requests. See the [dynamic sort contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#535-optional-dynamic-offset-sorting).

### Optional C1 snapshot pagination

Use C1 when all reads for one page can run through the same eligible InnoDB connection and the Host can grant that connection exclusively. Start with a new non-persistent PDO reader with server autocommit enabled, effective REPEATABLE READ and PDO exception mode. The Host proves the connection has no active transaction or pending next-transaction policy.

1. Build the same descriptor, request and configuration used for standard pagination.
2. Create the one-shot operation with `PdoSnapshotPaginator::forExclusiveRead($pdo)` and call `paginate()`.
3. On return or exception, inspect `connectionOutcome()` on that operation. The Host can use `Reusable` after confirmed cleanup, retains its policy for `Unchanged`, and retires a `Discard` checkout. Hold the connection out of circulation if an attempted call finishes without an outcome. Preserve the page failure if Host settlement also fails.

The [snapshot example](../../examples/pagination_snapshot_usage.php) runs successful and failing reads against a disposable database. To run it from an independent Composer project using the installed package, copy the example and `examples/_bootstrap.php` into that project's `examples/` directory. It loads that project's production autoloader. The package's [C1 contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#531-optional-c1-snapshot-pagination) defines the eligibility, read scope and settlement rules.

### Optional B pagination in a Host transaction

Use B when the Host needs eligible page reads, including its earlier writes, within one transaction it starts through the checkout.

1. **Input** — Start with an idle, exclusively delegated, non-persistent `pdo_mysql` PDO with exception mode, the default statement class and autocommit enabled. Register non-locking, side-effect-free InnoDB page plans and trusted commands in a `HostSnapshotCatalog`. Route all use of the connection through the checkout during delegation.
2. **Start** — Create the source with `PdoHostSnapshotSource::attachExclusive($pdo, $catalog)`, obtain a lease with `$source->checkout()`, then call `$checkout->beginRepeatableRead()`. Checkout rejects an already active transaction. At the Host's request, B issues `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` for the next transaction, starts it, and confirms the same session and active transaction. The Host's session and server defaults stay in effect for later transactions. For writes before a page, use `runRegisteredCommand()` on that checkout in read-write mode with commands registered to preserve the transaction.
3. **Read** — Call `$checkout->authorizePage($queryId, $bindings, $request)`, pass the grant to `$checkout->newAttempt()`, and use `(new PdoHostSnapshotPaginator())->paginate($attempt)` to receive a `PageResultDTO`.
4. **Boundary** — Eligible page reads share the InnoDB read view within the confirmed transaction and can see the Host's own writes in that transaction. A later transaction has its own read view. Inspect `$attempt->settlement()` after return or exception. The Host directs `commit()` or `rollBack()` on the checkout and handles handoff or discard. The page settlement has a JSON projection of disposition and reason. If the Host calls `retireForHost()` on the source, the returned `HostSnapshotRetirement` transfers the original PDO as a PHP object. If B cannot confirm that startup consumed the isolation setting, the Host must reconcile the connection before reuse.

See the [B contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#533-optional-b-pagination-in-a-host-owned-transaction) for confirmation and handoff rules. The [Host integration example](../../examples/pagination_host_snapshot_usage.php) shows a read-only page transaction and a conservative discard policy. The [installed consumer verification](CONSUMER_VERIFICATION.md) exercises a registered write followed by a page in the same transaction.

### Optional forward-only Keyset pagination

Build a trusted `KeysetQueryDescriptor` for the eligible InnoDB table and supply a deployment-managed signing keyring to `PdoKeysetPaginator`. On the first `paginate()` call, pass the Host's PDO, query, authorized scope, matching typed bindings and mapper. When `nextCursor` is present, pass it to the next call with the same registration, scope, bindings and effective limit.

The cursor binds the continuation to that context. The Host authorizes each request and controls the connection, read view and signing keys. See the [Keyset contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#532-optional-forward-only-keyset-pagination) and the [runnable example](../../examples/pagination_keyset_usage.php).

---

## 3. Ordering

**Fit:** you have a table with an integer display-order column (optionally scoped by another column, e.g. one ordering sequence per `category_id`) and need to allocate the next position for an insert, or move an existing row to a new position safely under concurrency.

**Requirements:** the table's identifiers (table, id column, order column, and optionally a scope column, a soft-delete column, and an updated-at column) as **trusted application constants** — never raw user input, since they are quoted, not bound as parameters.

Use an InnoDB table for the locking and rollback behavior shown here. A failed move on a nontransactional table can leave rows shifted. Before choosing `moveWithinScope()`, check whether a key enforces unique positions globally or within each scope. The range shift can temporarily collide with the target row's original position. If your schema requires that key, use the eligible protected B1 path below or verify a domain-specific strategy. See the [Ordering contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager).

**Non-goals:** the manager does not create or alter the table, does not maintain a cache of positions, and does not fix a transaction the server has already rolled back (the MariaDB hazard in §7.3 of the Package Reference).

**Inputs:** a `PDO` connection, a `ScopedOrderingConfig`, a scope value (or `null` for global/nullable-scope ordering), and — for a move — a row id and target order.
**Outputs:** `int` (next position) or `bool` (whether the move succeeded, including a no-op).
When you configure `updatedAtColumn`, supply `updatedAtValue` as a valid `YYYY-MM-DD HH:MM:SS` timestamp with optional 1–6 fractional digits. For example, format a date-time object with `Y-m-d H:i:s` in the intended Host timezone. Omit the value when the column is absent. Both raw and protected moves apply this check before Ordering SQL and bind the accepted string unchanged. [Migration from RC.2](../decisions/DEC-033-validate-ordering-mutation-timestamps.md)

For a raw move, `false` means the target was missing or the zero-row update was undone, with any required settlement confirmed. If PDO fails to confirm that settlement, handle `TransactionExecutionException` or the original driver throwable, then resolve the transaction and connection state before reuse. The Host retains final control of a transaction it supplied. [Migration from RC.2](../decisions/DEC-034-raw-ordering-settlement-failures.md)

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

### Protected Ordering and domain constraints

For a protected Host write transaction, delegate an eligible PDO with `PdoProtectedTransactionCheckout::attachExclusive()`, call `begin()`, then pass it to `ProtectedScopedOrderingManager::getNextPositionForUpdate()` or `moveWithinScope()`. Keep the Host's scope-lock protocol and issue any insert through a registered command on the same checkout. The Host chooses completion, inspects settlement and returns or discards the connection under the [protected checkout contract](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#521-optional-protected-host-write-transaction). After transaction loss, a fenced checkout prevents continuation and handoff.

The **raw** `ScopedOrderingManager::getNextPositionForUpdate()` defaults to 64 locking tail reads. Its optional `maxTailReads` parameter accepts 2–512 and counts the initial read. If no two consecutive values match within the budget, the call reports `TAIL_READ_LIMIT_EXCEEDED` in `OrderingExecutionException` metadata. The Host retains transaction control, and engine lock-wait limits apply separately. [DEC-021](../decisions/DEC-021-bound-ordering-tail-reads.md)

For dense positions backed by a UNIQUE key, use the protected B1 lifecycle below on an eligible schema.

### Protected UNIQUE-safe Ordering B1

B1 is opt-in. See the [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#541-protected-unique-safe-ordering-b1) for schema, receipt and connection requirements.

#### Basic — append to a global banner list

A migration creates the eligible tables and seeds an anchor. The reviewed deployment provides `$receipt`, and separately provides `$activeRevision`. Build these objects once from trusted configuration.

```sql
CREATE TABLE app.banner_anchor (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO app.banner_anchor (id) VALUES (1);
CREATE TABLE app.banners (
    id INT NOT NULL PRIMARY KEY,
    pos INT NOT NULL,
    title VARCHAR(140) NOT NULL,
    UNIQUE KEY banner_position (pos)
) ENGINE=InnoDB;
```

```php
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;

$config = new UniqueOrderingConfig(
    ordering: new ScopedOrderingConfig(table: 'app.banners', orderColumn: 'pos', deletedAtColumn: null),
    anchorTable: 'app.banner_anchor',
    anchorIdColumn: 'id',
    globalAnchorId: 1,
    maxPosition: 101,
    maxScopeRows: 100,
    schemaAttestation: $receipt,
    activeSchemaRevision: $activeRevision,
);
$catalog = new ProtectedTransactionCommandCatalog([
    'banner.insert' => new ProtectedTransactionCommandPlan(
        'INSERT INTO `app`.`banners` (id, pos, title) VALUES (:id, :position, :title)',
        ['id', 'position', 'title'],
    ),
]);
$checkout = PdoProtectedTransactionCheckout::attachExclusive($hostPdo, $catalog);
unset($hostPdo);
$manager = new ProtectedScopedOrderingManager();
try {
    $checkout->begin();
    $position = $manager->getNextUniquePositionForAppend($checkout, $config, scopeId: null);
    $count = $checkout->runRegisteredCommand('banner.insert', [
        'id' => $newBannerId,
        'position' => $position,
        'title' => $title,
    ]);
    if ($count !== 1) {
        throw new RuntimeException('Expected one new banner.');
    }
    $checkout->commit();
} catch (Throwable $failure) {
    if ($checkout->settlement() === null) {
        try {
            $checkout->rollBack();
        } catch (Throwable $rollbackFailure) {
            // Record both failures in the Host; retain the initiating exception.
        }
    }
    throw $failure;
} finally {
    if ($checkout->settlement()?->disposition === ProtectedTransactionDispositionEnum::DiscardRequired) {
        $checkout->discardForHost();
    } else {
        $hostPdo = $checkout->returnForHost();
    }
}
```

Run the [complete example](../../examples/ordering_unique_usage.php) with `composer examples:smoke` to verify append, move and delete on a disposable database.

A full list rejects allocation with `SCOPE_ROW_LIMIT` or `NO_STAGING_POSITION`. The Host can expose this as a capacity error after settling the transaction.

#### Professional — move and remove items within a playlist

Qualify the `app.playlists` parent with an `INT UNSIGNED` primary key and `app.playlist_items` with matching non-null `scope_id`, `UNIQUE(scope_id, pos)`, and a restricted foreign key to its parent. Use `globalAnchorId: null` and a positive playlist ID. The Host provisions each parent when it creates the scope and reviews both definitions and their dependencies before issuing the deployment receipt.

The following fragment runs inside a Host write transaction like the Basic example. Register a locking parent authorization query and the exact affected-row DELETE in the trusted catalog. In this example, authorization depends on an owner stored in the locked parent.

```php
$catalog = new ProtectedTransactionCommandCatalog([
    'playlist.authorize' => new ProtectedTransactionCommandPlan(
        'SELECT owner_id FROM `app`.`playlists` WHERE id = :scope_id FOR UPDATE',
        ['scope_id'],
        maxRows: 1,
    ),
    'playlist.delete' => new ProtectedTransactionCommandPlan(
        'DELETE FROM `app`.`playlist_items` WHERE `id` = :id AND `scope_id` = :scope_id AND `pos` = :position',
        ['id', 'scope_id', 'position'],
    ),
]);

// After attaching this catalog and beginning the protected transaction:
$rows = $checkout->runRegisteredCommand('playlist.authorize', ['scope_id' => $playlistId]);
if (!is_array($rows) || count($rows) !== 1 || (string) $rows[0]['owner_id'] !== (string) $actorId) {
    throw new RuntimeException('Playlist unavailable to this actor.');
}

$removed = $manager->deleteUniqueWithinScope($checkout, $config, $playlistId, $itemId, 'playlist.delete');
if ($removed && $nextItemId !== null) {
    $manager->moveUniqueWithinScope($checkout, $config, $playlistId, $nextItemId, newOrder: 1);
}
// Both changes become durable only after Host commit.
```

Review any authorization that depends on other rows under a compatible lock order. A policy requiring predicates at the DELETE itself is outside the B1 profile. For multiple scopes, acquire anchors in a stable order. Scope transfers, anchor retirement and changes to dependencies require a separate maintenance protocol. Quiesce ordering writers during migrations, requalify both tables and dependencies, then activate a new revision and receipt together. The guarantee depends on every writer following the same anchor protocol.
