# PHP Persistence Package Reference

`maatify/php-persistence` — canonical stable public/runtime/behavioral contract.

The latest published release is [`1.0.0-rc.2`](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2). C1, Keyset, B, static composite and dynamic Offset sorting, protected Host write transactions and Ordering, protected UNIQUE-safe Ordering B1, Verified Apply/Discard, and the raw Ordering tail-read limit describe unreleased repository source. For the published API, use the [RC.2 reference](https://github.com/Maatify-ltd/php-persistence/blob/v1.0.0-rc.2/PHP_PERSISTENCE_PACKAGE_REFERENCE.md).

This document is the single canonical Package Reference required by the pinned [Package Building Standard](docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md) §3. It owns the stable public Runtime API inventory, behavior and exception guarantees, and package boundaries. It supersedes [docs/architecture/CONTRACT_MATRIX.md](docs/architecture/CONTRACT_MATRIX.md) and [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md) as the *current* public-contract surface; those documents remain as dated design/evidence records (legacy mapping and the test evidence recorded per behavior) and are not a second competing contract.

Composer package identity, PHP/extension requirements, dependencies, autoloading, configuration and distribution are owned exclusively by [`composer.json`](composer.json) under the pinned [Composer Package Standard](docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md). This document explains runtime implications of that manifest; it does not restate or override it.

Every claim below is verified directly against the current `src/` tree and the named test files under `tests/Unit/`, `tests/Regression/` and `tests/Integration/`, re-read for this document. It does not copy the WU-3 design tables unchecked.

---

## 1. Purpose and boundaries

The package offers three independent capabilities over a **caller-supplied `PDO` connection** to MySQL or MariaDB:

- **Transaction** — run a callback inside a transaction, or inside a savepoint when the caller already owns one; optional bounded deadlock replay for a package-owned transaction.
- **Pagination** — parameterized, whitelist-sorted, bounded pagination that returns a validated result DTO.
- **Ordering** — next-position allocation and move operations for integer display-order columns, with locking reads and savepoint-protected moves.

### 1.1 What the package does not do (non-goals)

- It does not open, pool, or configure PDO connections. The host creates and owns the `PDO` instance and its attributes (error mode, charset, etc.).
- The Host controls PDO attributes and server or session defaults, including isolation, `autocommit` and `innodb_snapshot_isolation` (see §7.3). B issues `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` for the next transaction when the Host explicitly requests a start. See [§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction).
- It is not an ORM, query builder, schema manager, or migration tool. `Pagination\Repository\PdoPaginator` executes SQL text supplied by the caller through `PdoPaginationQueryDescriptor`; it does not generate SQL from a model.
- It owns no table, schema, or domain-owned persisted state of its own. It operates entirely on tables the host application owns and names.
- It contains no HTTP controllers, routes, middleware, permission checks, UI, or export logic.
- Operation state is scoped to each call or operation object. The optional B source and the protected write checkout maintain their own reservation state across calls. The one-shot C1 operation retains its grant and terminal outcome on its own object, as specified by [DEC-018](docs/decisions/DEC-018-optional-delegated-snapshot-pagination.md).
- B is a stateful, opt-in exception. The source maintains a shared reservation, checkout and transaction generation across calls. A process-local `WeakMap` prevents rebinding the same PDO while that object still exists. Each page attempt retains its own terminal settlement. See [DEC-020](docs/decisions/DEC-020-optional-host-transaction-snapshot-pagination.md) and [§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction).
- A protected write checkout keeps one exclusive reservation, one Host-directed transaction, and its final settlement. Its registration is separate from Pagination checkout APIs. See [§5.2.1](#521-optional-protected-host-write-transaction).
- It is not a connection factory and does not perform dependency injection wiring; a host wires it directly or through its own container.

### 1.2 Operational Read / Reporting classification

Per the pinned [Package Building Standard §15](docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md), a package is only In Scope for Operational Read / Reporting contracts when it owns persisted domain or operational state of its own (orders, balances, jobs, statuses, and the like). This package owns none: it is a direct-PDO infrastructure mechanism that reads and writes rows in tables the *host* owns, names, and is responsible for interpreting. It allocates ordering positions and paginates host-supplied queries, but it does not own the meaning of those rows.

**Classification: Out of Scope.** No listing, search, status-summary, count, aggregate, or monitoring-read API is provided or required, and none is added merely for template compliance.

### 1.3 Coexistence with `maatify/persistence`

This package is a separate Composer package from the published `maatify/persistence` (root namespace `Maatify\Persistence\`), not a drop-in replacement. Per [DEC-005](docs/decisions/DEC-005-coexistence-with-legacy-package.md):

- Both packages can be installed together in one Composer root; their namespaces do not collide.
- `composer.json` declares no `replace`, `provide`, or `conflict` against `maatify/persistence`, and the package ships no `class_alias`, bridge, or adapter between the two type systems.
- Published legacy `v1.4.0` and the unpublished legacy `main` used as source provenance are distinct baselines ([DEC-007](docs/decisions/DEC-007-legacy-source-baseline-and-deprecated-retirement.md)). This package has its own namespace and public API.
- Consumer migration from `maatify/persistence` to this package is explicitly **not** addressed by this document and needs its own separate authorization ([DEC-030](docs/decisions/DEC-030-successor-transition-before-first-stable.md)).

### 1.4 Release Candidate target

The [README](README.md#installation) records the exact installation target and the condition for external Composer resolution. [`composer.json`](composer.json) governs the current package identity, PHP and extension requirements, and Composer dependencies.

---

## 2. Source topology

```text
Source Topology: Multi Capability

Capabilities:
- Ordering
- Pagination
- Transaction
```

One Composer package, three independent capability folders under `src/`, per [DEC-010](docs/decisions/DEC-010-package-topology-multi-capability.md) and [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). Dependency direction: `Ordering → Transaction`; `Pagination` and `Transaction` do not depend on each other or on `Ordering`.

```text
src/
├── Exception/PhpPersistenceExceptionInterface.php
├── Ordering/
│   ├── Config/ScopedOrderingConfig.php
│   ├── Config/UniqueOrderingConfig.php
│   ├── Exception/InvalidOrderingConfigurationException.php
│   ├── Exception/InvalidOrderingOperationException.php
│   ├── Exception/OrderingExecutionException.php
│   ├── Repository/OrderingOperations.php   (@internal)
│   ├── Repository/ProtectedScopedOrderingManager.php
│   ├── Repository/ScopedOrderingManager.php
│   ├── Repository/UniqueOrderingOperations.php   (@internal)
│   ├── Repository/UniqueOrderingSchema.php   (@internal)
│   └── Repository/UniqueOrderingSql.php   (@internal)
├── Pagination/
│   ├── Config/PaginationConfig.php
│   ├── Config/StaticSortPlan.php
│   ├── Criteria/HostSnapshotAttemptRecord.php   (@internal)
│   ├── Criteria/HostSnapshotBindings.php   (@internal)
│   ├── Criteria/HostSnapshotCatalog.php
│   ├── Criteria/HostSnapshotCommandPlan.php
│   ├── Criteria/HostSnapshotGrantRegistration.php   (@internal)
│   ├── Criteria/HostSnapshotPageAttempt.php
│   ├── Criteria/HostSnapshotPageGrant.php
│   ├── Criteria/HostSnapshotPagePlan.php
│   ├── Criteria/KeysetQueryDescriptor.php
│   ├── Criteria/PageRequest.php
│   ├── Criteria/PdoPaginationQueryDescriptor.php
│   ├── DTO/HostSnapshotPageSettlementDTO.php
│   ├── DTO/HostSnapshotRetirementDTO.php
│   ├── DTO/KeysetPageResultDTO.php
│   ├── DTO/MultiSortPageResultDTO.php
│   ├── DTO/PageResultDTO.php
│   ├── DTO/SnapshotConnectionOutcomeDTO.php
│   ├── Enum/HostSnapshotAccessModeEnum.php
│   ├── Enum/HostSnapshotCheckoutStateEnum.php
│   ├── Enum/HostSnapshotCommandEffectEnum.php
│   ├── Enum/HostSnapshotCommandResultModeEnum.php
│   ├── Enum/HostSnapshotPageAttemptStateEnum.php
│   ├── Enum/HostSnapshotPageDispositionEnum.php
│   ├── Enum/HostSnapshotPageSettlementReasonEnum.php
│   ├── Enum/HostSnapshotRetirementDispositionEnum.php
│   ├── Enum/SnapshotConnectionDispositionEnum.php
│   ├── Enum/SortDirectionEnum.php
│   ├── Exception/HostSnapshotPaginationException.php
│   ├── Exception/HostSnapshotRowLimitExceededException.php   (@internal)
│   ├── Exception/InvalidKeysetCursorException.php
│   ├── Exception/InvalidPaginationConfigurationException.php
│   ├── Exception/InvalidPaginationQueryException.php
│   ├── Exception/PaginationExecutionException.php
│   ├── Exception/SnapshotPaginationException.php
│   ├── Repository/HostSnapshotConnection.php   (@internal)
│   ├── Repository/HostSnapshotPageReader.php   (@internal)
│   ├── Repository/KeysetCursor.php   (@internal)
│   ├── Repository/PdoHostSnapshotCheckout.php
│   ├── Repository/PdoHostSnapshotPaginator.php
│   ├── Repository/PdoHostSnapshotSource.php
│   ├── Repository/PdoKeysetPaginator.php
│   ├── Repository/PdoPaginationExecution.php   (@internal)
│   ├── Repository/PdoPaginator.php
│   ├── Repository/PdoSnapshotPaginator.php
│   ├── Repository/SnapshotReadResources.php   (@internal)
│   ├── ValueObject/ResolvedDynamicSort.php   (@internal)
│   ├── ValueObject/SortIdentifier.php   (@internal)
│   └── ValueObject/SortWhitelist.php
└── Transaction/
    ├── Criteria/ProtectedTransactionCommandCatalog.php
    ├── Criteria/ProtectedTransactionCommandPlan.php
    ├── DTO/ProtectedTransactionSettlementDTO.php
    ├── DTO/VerifiedTransactionDecisionDTO.php
    ├── DTO/VerifiedTransactionSettlementDTO.php
    ├── Enum/ProtectedTransactionDispositionEnum.php
    ├── Enum/ProtectedTransactionReasonEnum.php
    ├── Enum/VerifiedTransactionOutcomeEnum.php
    ├── Enum/VerifiedTransactionReasonEnum.php
    ├── Exception/InvalidTransactionConfigurationException.php
    ├── Exception/ProtectedTransactionException.php
    ├── Exception/TransactionExecutionException.php
    ├── Repository/OwnedTransactionExecutor.php   (@internal)
    ├── Repository/PdoProtectedTransactionCheckout.php
    ├── Repository/PdoSavepointTransactionRunner.php
    ├── Repository/PdoTransactionRunner.php
    ├── Repository/PdoVerifiedTransactionCheckout.php
    ├── Repository/ProtectedTransactionCommandExecutor.php   (@internal)
    ├── Repository/ProtectedTransactionConnection.php   (@internal)
    ├── Repository/ProtectedTransactionDriver.php   (@internal)
    ├── Repository/ProtectedTransactionStatement.php   (@internal)
    ├── Repository/SavepointScope.php   (@internal)
    ├── Repository/SavepointTransactionRunnerInterface.php
    ├── Repository/TransactionConnectionClaim.php   (@internal)
    ├── Repository/TransactionRunnerInterface.php
    ├── Repository/VerifiedTransactionCommandScope.php   (@internal)
    └── Repository/VerifiedTransactionCommandsInterface.php
```

Root PSR-4 namespace: `Maatify\PhpPersistence\` → `src/` ([DEC-004](docs/decisions/DEC-004-package-identity-namespace-and-exception-marker.md)). `@internal` classes are loadable (PHP enforces no visibility on a class itself) but are **not** part of the public contract or SemVer; see §3.5.

---

## 3. Public Runtime API inventory

Conventions used below: every listed class is `final`; `readonly` is noted when declared on the class. `$x = v` marks a constructor default. Promoted constructor parameters that are also public properties are written as `public <type> $name`. Every exception class listed implements `Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`, directly or through its base class, unless stated otherwise.

### 3.1 Package-wide exception marker

**`Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface`** — `interface … extends \Throwable {}`

Package-defined exceptions implement this interface, including the internal types in §3.5. Catch `PhpPersistenceExceptionInterface` to handle any public package exception listed in §6.

```php
catch (PhpPersistenceExceptionInterface $e) { ... }
```

External throwables such as `\PDOException` are **not** wrapped and do **not** implement this interface (§6).

### 3.2 Transaction capability — `Maatify\PhpPersistence\Transaction\`

#### `Repository\TransactionRunnerInterface`

```php
interface TransactionRunnerInterface
{
    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
```

#### `Repository\SavepointTransactionRunnerInterface`

```php
interface SavepointTransactionRunnerInterface extends TransactionRunnerInterface {}
```

Adds no members; it exists so a consumer can type-hint "a runner that uses a savepoint when participating in a caller-owned transaction" distinctly from a plain runner.

#### `Repository\PdoTransactionRunner` (`final readonly class`, implements `TransactionRunnerInterface`)

```php
public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
```

- `$pdo` is not a public property (the PDO connection is not readable back from the runner).
- `$maxDeadlockRetries` must be in `[0, 3]`; otherwise the constructor throws `InvalidTransactionConfigurationException` (raised while constructing the internal `OwnedTransactionExecutor`). `0` (the default) means no deadlock replay.

```php
/**
 * @template TResult
 * @param callable(): TResult $callback
 * @return TResult
 */
public function run(callable $callback): mixed
```

Behavior: see §5.1.

#### `Repository\PdoSavepointTransactionRunner` (`final readonly class`, implements `SavepointTransactionRunnerInterface`)

```php
public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
```

Same constructor contract as `PdoTransactionRunner` (range `[0, 3]`, same exception).

```php
/**
 * @template TResult
 * @param callable(): TResult $callback
 * @return TResult
 */
public function run(callable $callback): mixed
```

Behavior: see §5.2.

#### Optional protected write checkout

`Repository\PdoProtectedTransactionCheckout` represents one exclusive delegation and one Host-directed write transaction.

```php
public static function attachExclusive(PDO $pdo, ProtectedTransactionCommandCatalog $catalog): self
public function begin(): void
public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
public function commit(): void
public function rollBack(): void
public function settlement(): ?ProtectedTransactionSettlementDTO
public function returnForHost(): PDO
public function discardForHost(): void
```

`Criteria\ProtectedTransactionCommandCatalog::__construct(array $commands)` accepts a map of identifiers to `ProtectedTransactionCommandPlan` objects. A plan is constructed with `string $sql, array $parameterNames, ?int $maxRows = null`. A null bound requests affected-row count. A positive bound requests a list of associative result rows, with PDO column-key semantics, and rejects a result exceeding that bound. Bindings match the registered unique names exactly and carry strings, integers, booleans, or null. Names omit the colon. Plans accept statements beginning with SELECT, INSERT, UPDATE, DELETE, REPLACE or WITH, without semicolons. The Host warrants single-statement InnoDB behavior that preserves the session and transaction, including the behavior of any routines or triggers.

`DTO\ProtectedTransactionSettlementDTO` is readonly and exposes `public ProtectedTransactionDispositionEnum $disposition` and `public ProtectedTransactionReasonEnum $reason`. See §5.2.1 for the dispositions and reasons.

#### Optional verified write checkout

`Repository\PdoVerifiedTransactionCheckout` is final. It rejects cloning, serialization and restoration.

~~~php
public static function attachExclusive(#[SensitiveParameter] PDO $pdo, ProtectedTransactionCommandCatalog $catalog): self
/** @param Closure(VerifiedTransactionCommandsInterface): VerifiedTransactionDecisionDTO $operation */
public function runOwned(Closure $operation): mixed
public function beginHost(): void
public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
/** @param Closure(VerifiedTransactionCommandsInterface): VerifiedTransactionDecisionDTO $operation */
public function runInHost(Closure $operation): mixed
public function commit(): void
public function rollBack(): void
public function settlement(): VerifiedTransactionSettlementDTO
public function returnForHost(): PDO
public function discardForHost(): void
~~~

`Repository\VerifiedTransactionCommandsInterface` exposes only `runRegisteredCommand(string $commandId, array $bindings = []): int|array`. `DTO\VerifiedTransactionDecisionDTO` is final readonly with `bool $isApply` and `mixed $payload` and the factories `apply(mixed $payload = null): self` and `discard(mixed $payload = null): self`. The payload may be false or null and its identity is preserved.

`DTO\VerifiedTransactionSettlementDTO` is final readonly. Its constructor takes the public properties in order, without defaults.

| Property | Type |
| --- | --- |
| operationOutcome | ?VerifiedTransactionOutcomeEnum |
| operationReason | ?VerifiedTransactionReasonEnum |
| transactionOutcome | ?VerifiedTransactionOutcomeEnum |
| checkoutReason | ?VerifiedTransactionReasonEnum |
| disposition | ?ProtectedTransactionDispositionEnum |

The unbacked `Enum\VerifiedTransactionOutcomeEnum` has NOT_STARTED, PENDING_HOST, LOCAL_UNDONE, COMMITTED, ROLLED_BACK and UNKNOWN. The unbacked `Enum\VerifiedTransactionReasonEnum` has QUALIFICATION_FAILED, START_UNCONFIRMED, CONTINUITY_UNCONFIRMED, SAVEPOINT_UNCONFIRMED, CALLBACK_FAILED, COMMAND_FAILED, CURSOR_UNCONFIRMED, ROLLBACK_TO_UNCONFIRMED, RELEASE_UNCONFIRMED, COMMIT_OUTCOME_UNKNOWN, ROLLBACK_UNCONFIRMED, HANDOFF_UNCONFIRMED, TRANSACTION_LOST and HOST_DISCARDED. The settlement is a PHP value, without a wire-format or authority to use PDO. See [§5.2.2](#522-optional-verified-apply-and-discard).

#### `Exception\InvalidTransactionConfigurationException` / `Exception\TransactionExecutionException` / `Exception\ProtectedTransactionException`

See §6.1.

### 3.3 Pagination capability — `Maatify\PhpPersistence\Pagination\`

#### `Criteria\PageRequest` (`final readonly class`)

```php
public function __construct(
    public int|string|null $page = null,
    public int|string|null $perPage = null,
    public ?string $sortBy = null,
    public ?string $sortDirection = null,
)
```

No validation is performed here; this is the raw, untrusted request shape. `PdoPaginator::paginate()` performs all normalization (§5.3). Pass only values matching these parameter types. At a request boundary, discard or reject arrays and other values outside the accepted types before constructing `PageRequest`.

#### `Config\PaginationConfig` (`final readonly class`)

```php
public function __construct(
    public SortWhitelist $sortWhitelist,
    public string $defaultSortBy,
    public SortDirectionEnum $defaultSortDirection,
    public string $tieBreakerSortBy,
    public SortDirectionEnum $tieBreakerDirection,
    public int $defaultPerPage = 20,
    public int $minPerPage = 1,
    public int $maxPerPage = 200,
)
```

Throws `InvalidPaginationConfigurationException` when constructed with:

- `$minPerPage < 1`, or `$maxPerPage < $minPerPage`, or `$defaultPerPage` outside `[$minPerPage, $maxPerPage]`;
- `$defaultSortBy` or `$tieBreakerSortBy` missing from `$sortWhitelist`. Both must be exact matches for keys that satisfy the full-string grammar enforced by `SortWhitelist`.

#### `Config\StaticSortPlan` (`final readonly class`)

```php
public function __construct(PaginationConfig $config, array $profiles)
```

Each entry uses a whitelist key and an explicit `null` or an ordered list of trailing terms. The constructor validates every entry. See [§5.3.4](#534-optional-static-composite-offset-pagination) for the profile format, fallback, and error contract.

#### `ValueObject\SortWhitelist` (`final readonly class`)

```php
/** @param non-empty-array<non-empty-string, non-empty-string> $sorts */
public function __construct(array $sorts)

public function contains(string $key): bool

/** @return non-empty-string */
public function quotedIdentifierFor(string $key): string
```

- Each key and each path segment must match `\A[A-Za-z_][A-Za-z0-9_]*\z` as supplied. An ASCII line feed (LF) anywhere in the name, including at the end, is invalid.
- Each identifier path has one to three dot-separated segments. The constructor quotes each segment with backticks (e.g. `orders.created_at` → `` `orders`.`created_at` ``).
- The constructor throws `InvalidPaginationConfigurationException` for an empty map or any key or identifier value that violates these rules, including non-string keys and values.
- `quotedIdentifierFor()` throws `InvalidPaginationConfigurationException` for a key not in the whitelist.

#### `Criteria\PdoPaginationQueryDescriptor` (`final readonly class`)

```php
/**
 * @param array<string, string|int|bool|null> $totalParams
 * @param array<string, string|int|bool|null> $filteredCountParams
 * @param array<string, string|int|bool|null> $dataParams
 */
public function __construct(
    public string $totalSql,
    public array $totalParams,
    public string $filteredCountSql,
    public array $filteredCountParams,
    public string $dataSql,
    public array $dataParams,
)
```

Constructor validation throws `InvalidPaginationQueryException` when:

- any of the three SQL strings is blank, contains a literal `;`, or contains the reserved placeholder prefix `:__pagination_`;
- any parameter key is not a string, starts with `:`, fails the full-string pattern `\A[A-Za-z_][A-Za-z0-9_]*\z` as supplied, or starts with the reserved prefix `__pagination_`;
- any parameter value is not `string|int|bool|null`.

A parameter key containing LF anywhere, including at the end, fails the full-string pattern.

The three SQL statements are the caller's own text: a count of the whole dataset (`$totalSql`), a count after filters (`$filteredCountSql`), and the unsorted/unlimited data query (`$dataSql`, to which `PdoPaginator` appends `ORDER BY`/`LIMIT`/`OFFSET`). The reserved placeholder names `__pagination_limit` and `__pagination_offset` are owned by `PdoPaginator` (§3.3, `Repository\PdoPaginator`) and must not be used by caller SQL.

#### `Enum\SortDirectionEnum` (`enum … : string`)

```php
enum SortDirectionEnum: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
```

PHP provides the enum’s read-only `name` and `value` properties and the standard backed-enum methods `cases(): array`, `from(int|string $value): static`, and `tryFrom(int|string $value): ?static`.

#### `DTO\PageResultDTO` (`final readonly class`, implements `JsonSerializable`, `@template T of array<array-key, mixed>|object`)

```php
/** @param list<T> $data */
public function __construct(
    public array $data,
    public int $page,
    public int $perPage,
    public int $total,
    public int $filtered,
    public int $totalPages,
    public bool $hasNext,
    public bool $hasPrevious,
    public string $sortBy,
    public SortDirectionEnum $sortDirection,
)

public function toArray(): array
public function jsonSerialize(): array    // returns toArray()
```

The constructor throws `PaginationExecutionException` unless all of these invariants hold:

- `data` is a list of arrays or objects, with at most `perPage` items.
- `page` and `perPage` are positive, and the counts and `totalPages` are non-negative.
- The supplied `sortBy` must match `\A[A-Za-z_][A-Za-z0-9_]*\z` as is. An LF anywhere in the key, including at the end, causes `PaginationExecutionException` (`Invalid pagination result state.`).
- `totalPages` is the ceiling of `filtered / perPage`. When `filtered` is zero, `data` is empty, `page` is 1, and both navigation flags are false. Otherwise `page` must be within `1..totalPages`.
- `hasNext` equals `page < totalPages`, and `hasPrevious` equals `page > 1 && totalPages > 0`.

When `PdoPaginator` builds the DTO, `sortBy` is the whitelist key resolved after request normalization (§5.3). The DTO accepts `filtered > total` and can contain empty `data` even when `filtered > 0`; separately executed statements can observe different database states. `toArray()` and `jsonSerialize()` return:

```text
array{
    data: list<T>,
    pagination: array{
        page: int, per_page: int, total: int, filtered: int, total_pages: int,
        has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC',
    },
}
```

`PageResultDTO` is normally produced by `PdoPaginator::paginate()`, but its constructor is public and may be used directly by a caller that already has the raw numbers (for example when composing a result from a cache).

#### `DTO\MultiSortPageResultDTO` (`final readonly class`, implements `JsonSerializable`, `@template T of array<array-key, mixed>|object`)

Produced by the [dynamic Offset entry point](#535-optional-dynamic-offset-sorting). The constructor is private. The read-only properties and result representations are:

```text
PageResultDTO<T> $page
list<array{key: string, direction: SortDirectionEnum}> $selectedSorts
non-empty-list<array{key: string|null, direction: SortDirectionEnum}> $effectiveSorts

toArray(): array
jsonSerialize(): array
```

Both methods return the page array with `selected_sorts` and `effective_sorts` appended in that order to `pagination`. List elements have `key` and `direction` in that order, with direction serialized as `ASC` or `DESC`. An automatically appended tie has a null key. The first effective term always agrees with the page's `sort_by` and `sort_direction`. The library creates the wrapper from the same validated order it uses for SQL. Native PHP restoration via `unserialize()` fails with `PaginationExecutionException`.

#### `Repository\PdoPaginator` (`final readonly class`, no declared constructor)

```php
/**
 * @template TItem of array<array-key, mixed>|object
 * @param callable(array<string, mixed>): TItem $mapper
 * @return PageResultDTO<TItem>
 */
public function paginate(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResultDTO
```

Throws `PaginationExecutionException` when PDO reports failure through a return value or error status, a count result violates §5.3, a data row has no columns or a non-string key, or a mapper returns a value other than an array or object. Exceptions thrown by PDO or the mapper propagate unchanged. Behavior: see §5.3.

The optional [static composite path](#534-optional-static-composite-offset-pagination) uses the same result and execution contract.

```php
/**
 * @template TItem of array<array-key, mixed>|object
 * @param callable(array<string, mixed>): TItem $mapper
 * @return PageResultDTO<TItem>
 */
public function paginateWithStaticSort(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    StaticSortPlan $plan,
    callable $mapper,
): PageResultDTO
```

The optional [dynamic Offset path](#535-optional-dynamic-offset-sorting) uses an ordered request list and returns the dedicated wrapper.

```php
/**
 * @template TItem of array<array-key, mixed>|object
 * @param callable(array<string, mixed>): TItem $mapper
 * @return MultiSortPageResultDTO<TItem>
 */
public function paginateWithDynamicSort(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    array $sorts,
    callable $mapper,
    bool $allowTieBreakerSelection = false,
): MultiSortPageResultDTO
```

#### `Repository\PdoSnapshotPaginator` (final, one operation per Host checkout)

C1 is an opt-in source API for a single consistent page. The Host constructs and owns an eligible PDO session and declares an exclusive grant through `forExclusiveRead()`. The factory performs no SQL. The first invocation of `paginate()` consumes the operation, including on rejection or failure. Reentry or another invocation throws `SnapshotPaginationException` before database I/O. Cloning, serialization and restoration from serialized data are rejected.

```php
public static function forExclusiveRead(PDO $pdo): self;

public function paginate(
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResultDTO;

public function connectionOutcome(): ?SnapshotConnectionOutcomeDTO;
```

- `DTO\SnapshotConnectionOutcomeDTO` is readonly. The constructor takes `SnapshotConnectionDispositionEnum $disposition` and `?string $diagnostic`, also available as public readonly properties. A `DISCARD` outcome requires a non-blank diagnostic. Use the enum for decisions and the diagnostic for safe failure-stage information.
- `Enum\SnapshotConnectionDispositionEnum` has cases `UNCHANGED`, `REUSABLE` and `DISCARD`.
- `connectionOutcome()` returns null before a terminal outcome and the same immutable DTO afterward. The accessor performs no I/O. The operation releases its own reference to the PDO and the outcome holds only the enum and diagnostic.

Behavior: see §5.3.1.

#### Optional Keyset API (forward by stable `id`)

The three types below are `final readonly`. Keyset is an opt-in reader on the Host's existing PDO connection. It has its own result and leaves the injection, counting, ordering, and navigation contracts of `PdoPaginator` and `PdoSnapshotPaginator` in effect. See §5.3.2 for registration and continuation requirements.

```php
// Criteria\KeysetQueryDescriptor
public function __construct(
    string $queryId,
    string $revision,
    string $table,
    array $columns,
    string $scopePredicate,
    array $parameterNames,
    string $filterPredicate = '1 = 1',
);

// Repository\PdoKeysetPaginator
public function __construct(
    string $activeKeyId,
    #[\SensitiveParameter] array $keys,
    int $defaultLimit = 20,
    int $maxLimit = 200,
);

public function paginate(
    PDO $pdo,
    KeysetQueryDescriptor $query,
    string $scope,
    array $bindings,
    callable $mapper,
    ?string $cursor = null,
    ?int $limit = null,
): KeysetPageResultDTO;

// DTO\KeysetPageResultDTO implements JsonSerializable
public function __construct(
    public array $data,
    public bool $hasNext,
    public ?string $nextCursor,
);

public function jsonSerialize(): array;
```

- `$keys` is a list of `array{id: string, secret: string}` records. Each ID matches `[A-Za-z0-9_-]{1,32}`, is unique, and remains a string even when all its characters are digits. The active ID must be present. Supply a cryptographically random secret of at least 32 bytes for each ID. The limit policy is `1 <= defaultLimit <= maxLimit < PHP_INT_MAX`. Invalid configuration raises `InvalidPaginationConfigurationException`.
- `$bindings` is an exact map from declared names to `string|int|bool|null`. The mapper receives one associative array with the requested columns after release of the read statement and returns an array or object. The reserved projection is removed and the extra row is never mapped.
- The result constructor accepts a list of arrays or objects in supplied order, enforces `$hasNext === ($nextCursor !== null)`, rejects an empty cursor string, and rejects continuation on an empty page. Invalid construction raises `PaginationExecutionException`. JSON has the keys `data`, `hasNext`, and `nextCursor`. The paginator verifies cursor authenticity and context when consuming it.
- `serialize()` and restoration through `unserialize()` of the paginator raise `PaginationExecutionException` with the message `Keyset paginator does not support serialization or restoration.`. Its normal `var_dump()` representation excludes key material. Library-generated messages and ordinary backtrace arguments redact signing material and sensitive request inputs. This representation is scoped to PHP's regular diagnostics. Reflection and `var_export()` have their own property-inspection semantics. The Host controls any logging of driver and mapper failures, which keep their original identity.

#### Optional B API (pagination in the Host's transaction)

B provides a source, an exclusive checkout, and a one-shot authorization per read. Its public surface in the Pagination namespace is:

```php
// Repository\PdoHostSnapshotSource
public static function attachExclusive(PDO $pdo, HostSnapshotCatalog $catalog): self;
public function checkout(): PdoHostSnapshotCheckout;
public function retireForHost(): HostSnapshotRetirementDTO;
public function discardForHost(): void;

// Repository\PdoHostSnapshotCheckout
public function beginRepeatableRead(HostSnapshotAccessModeEnum $mode = HostSnapshotAccessModeEnum::READ_WRITE): void;
public function runRegisteredCommand(string $commandId, array $bindings = []): int|array;
public function commit(): void;
public function rollBack(): void;
public function state(): HostSnapshotCheckoutStateEnum;
public function authorizePage(string $queryId, array $bindings, PageRequest $request): HostSnapshotPageGrant;
public function newAttempt(HostSnapshotPageGrant $grant): HostSnapshotPageAttempt;
public function release(): void;

// Repository\PdoHostSnapshotPaginator
public function paginate(HostSnapshotPageAttempt $attempt): PageResultDTO;

// Criteria\HostSnapshotPageAttempt
public function state(): HostSnapshotPageAttemptStateEnum;
public function settlement(): ?HostSnapshotPageSettlementDTO;

// Criteria\HostSnapshotCatalog
public function __construct(array $pagePlans, array $hostCommands = []);

// Criteria\HostSnapshotPagePlan
public function __construct(
    string $totalSql,
    string $filteredCountSql,
    string $dataSql,
    array $totalParameterNames,
    array $filteredParameterNames,
    array $dataParameterNames,
    PaginationConfig $config,
    Closure $mapper,
);

// Criteria\HostSnapshotCommandPlan
public function __construct(
    string $sql,
    array $parameterNames,
    HostSnapshotCommandResultModeEnum $resultMode,
    ?int $maxRows = null,
    HostSnapshotCommandEffectEnum $effect = HostSnapshotCommandEffectEnum::INVALIDATE_GENERATION,
);
```

- The source issues the opaque checkout, grant, and attempt. These identities and the source reject cloning, serialization, and restoration. Members marked @internal are implementation details. Explicit B contract rejections use Pagination\Exception\HostSnapshotPaginationException and the package marker.
- DTO\HostSnapshotPageSettlementDTO is readonly with public enum fields disposition and reason. DTO\HostSnapshotRetirementDTO is readonly with public HostSnapshotRetirementDispositionEnum disposition and PDO pdo.
- Checkout state is IDLE, TRANSACTION_ACTIVE, TRANSACTION_UNQUALIFIED, BUSY, QUARANTINED, RELEASED, or RETIRED. The access modes are READ_WRITE and READ_ONLY. Command result modes are AFFECTED_ROWS and BOUNDED_ROWS. Effects are INVALIDATE_GENERATION and PRESERVES_TRANSACTION.

#### Pagination exceptions

`InvalidPaginationConfigurationException`, `InvalidPaginationQueryException`, `PaginationExecutionException`, `SnapshotPaginationException`, `InvalidKeysetCursorException`, `HostSnapshotPaginationException` — see §6.2.

### 3.4 Ordering capability — `Maatify\PhpPersistence\Ordering\`

#### `Config\ScopedOrderingConfig` (`final readonly class`)

```php
public function __construct(
    public string $table,
    public ?string $scopeColumn = null,
    public string $idColumn = 'id',
    public string $orderColumn = 'display_order',
    public ?string $deletedAtColumn = 'deleted_at',
    public bool $nullableScope = false,
    public ?string $updatedAtColumn = null,
)

public function quotedTable(): string
public function quotedIdColumn(): string
public function quotedOrderColumn(): string
public function quotedScopeColumn(): ?string
public function quotedDeletedAtColumn(): ?string
public function quotedUpdatedAtColumn(): ?string
```

Throws `InvalidOrderingConfigurationException` when:

- `$table` fails the full-string pattern `\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z` for `table` or `schema.table`;
- `$idColumn`, `$orderColumn`, a non-null `$scopeColumn`, a non-null `$deletedAtColumn`, or a non-null `$updatedAtColumn` fails the full-string pattern `\A[A-Za-z_][A-Za-z0-9_]*\z` for a single column;
- `$nullableScope === true` while `$scopeColumn === null` (a nullable scope requires a configured scope column).

Table and column names are checked as supplied. An LF anywhere in either kind of name is invalid.

All identifier arguments **must be trusted application constants**, never raw user input: they are validated and quoted but are never bound as PDO parameters (table/column names cannot be). Row ids, scope values, and order numbers *are* bound as PDO parameters by `ScopedOrderingManager`. Passing `null` for `$deletedAtColumn` disables soft-delete filtering for that configuration; passing `null` for `$updatedAtColumn` (the default) means `moveWithinScope()` never sets a mutation timestamp.

#### `Repository\ScopedOrderingManager` (`final readonly class`, no declared constructor)

```php
public function getNextPosition(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null,
): int

public function getNextPositionForUpdate(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null,
    int $maxTailReads = 64,
): int

public function moveWithinScope(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue,
    int $id, int $newOrder, ?string $updatedAtValue = null,
): bool

public function rowExistsInScope(
    PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id,
): bool
```

`$scopeValue` is required (non-null) whenever `$config->scopeColumn` is set, unless `$config->nullableScope` is `true`, in which case `null` means the single NULL scope; it must be `null` when `$config->scopeColumn` is `null` (global ordering). Every method throws `InvalidOrderingOperationException` for inconsistent scope usage. Full behavior, including updated-at argument rules and the MariaDB hazard, is in §5.4.

#### `Repository\ProtectedScopedOrderingManager`

```php
public function getNextPositionForUpdate(
    PdoProtectedTransactionCheckout $checkout, ScopedOrderingConfig $config,
    int|string|null $scopeValue = null, int $maxTailReads = 64,
): int

public function moveWithinScope(
    PdoProtectedTransactionCheckout $checkout, ScopedOrderingConfig $config,
    int|string|null $scopeValue, int $id, int $newOrder, ?string $updatedAtValue = null,
): bool
```

Both methods use the active protected Host transaction and its reservation. For allocation, validate `$maxTailReads` in 2–512 inclusive, then return a position only after two consecutive matching tail reads, including stability on the last allowed read. If the tail remains unstable after the last closed result, throw `OrderingExecutionException` with metadata `reason=TAIL_READ_LIMIT_EXCEEDED` and integer `maxTailReads`. The cap bounds the number of reads. Engine lock-wait settings still apply to each statement. The Host can insert at the allocated position through a registered command and retains control of the transaction and its locks.

#### `Config\UniqueOrderingConfig` and protected B1 methods

```php
public function __construct(
    ScopedOrderingConfig $ordering,
    string $anchorTable,
    string $anchorIdColumn,
    ?int $globalAnchorId,
    int $maxPosition,
    int $maxScopeRows,
    array $schemaAttestation,
    string $activeSchemaRevision,
);

public function getNextUniquePositionForAppend(
    PdoProtectedTransactionCheckout $checkout,
    UniqueOrderingConfig $config,
    ?int $scopeId,
): int;

public function moveUniqueWithinScope(
    PdoProtectedTransactionCheckout $checkout,
    UniqueOrderingConfig $config,
    ?int $scopeId,
    int $id,
    int $newOrder,
): bool;

public function deleteUniqueWithinScope(
    PdoProtectedTransactionCheckout $checkout,
    UniqueOrderingConfig $config,
    ?int $scopeId,
    int $id,
    string $deleteCommandId,
): bool;
```

The last three methods belong to `Repository\ProtectedScopedOrderingManager`. Pass `deletedAtColumn: null`, omit `updatedAtColumn` and use `nullableScope: false` in the underlying `ScopedOrderingConfig`. See [§5.4.1](#541-protected-unique-safe-ordering-b1) for the eligibility, result and settlement contract.

#### Ordering exceptions

`InvalidOrderingConfigurationException`, `InvalidOrderingOperationException`, and `OrderingExecutionException` — see §6.3.

### 3.5 `@internal` types (excluded from the public contract)

PHP can load these classes and reach public members marked `@internal`. These implementation details carry **no SemVer guarantee** and may change in any release. Consumers use the public inventory in §§3.1–3.4.

- **`Pagination\Repository\KeysetCursor`** and **`Pagination\Criteria\KeysetQueryDescriptor::registration()`** — internal cursor protocol and the repository’s registration accessor.
- **`Pagination\Repository\PdoPaginationExecution`** — executes counts and data for ordinary, static composite and dynamic Offset pagination and C1. B reuses its pagination normalization and SQL construction.
- **`Pagination\ValueObject\SortIdentifier`** and the `@internal` members of **`Pagination\Config\StaticSortPlan`** support plan resolution and trusted identifier quoting.
- **`Pagination\ValueObject\ResolvedDynamicSort`** and the `@internal` members of **`Pagination\DTO\MultiSortPageResultDTO`** carry the validated order into Offset SQL and result construction.
- **`Pagination\Repository\SnapshotReadResources`** — tracks and confirms release of C1 statement cursors.
- **`Pagination\Repository\HostSnapshotConnection`** — tracks B's PDO and statement lifetimes, executes SQL, and verifies session state.
- **`Pagination\Repository\HostSnapshotPageReader`** — reads the registered page and releases statement resources before mapping.
- **`Pagination\Criteria\HostSnapshotAttemptRecord`** — holds one attempt's state and published settlement.
- **`Pagination\Criteria\HostSnapshotBindings`** — validates registered parameter names and freezes typed binding values.
- **`Pagination\Criteria\HostSnapshotGrantRegistration`** — binds a registered read and request to the source's checkout identity and transaction generation.
- **`Pagination\Exception\HostSnapshotRowLimitExceededException`** — signals that a result exceeded its row bound. B handles it during cleanup and reports the applicable public pagination exception.
- **`Transaction\Repository\OwnedTransactionExecutor`** — the shared begin/callback/commit-with-replay mechanism behind both Transaction runners. Exposes `public const MAX_DEADLOCK_RETRIES = 3`, which is the same bound documented publicly through the runners' `$maxDeadlockRetries` constructor parameter (§3.2); this constant is not a separate contract.
- **`Ordering\Repository\OrderingOperations`** — shared Ordering SQL and validation behind both managers.
- **`Ordering\Repository\UniqueOrderingOperations`**, **`UniqueOrderingSchema`** and **`UniqueOrderingSql`** — the B1 implementation: dense-ordering commands, the schema and anchor eligibility checks, and the SQL it issues. They are not entry points; B1 is used through `Config\UniqueOrderingConfig` and the three `ProtectedScopedOrderingManager` methods in §5.4.1. The `UniqueOrderingConfig::$anchor` property holds a `ScopedOrderingConfig` for the anchor, which validates the identifiers and provides safely quoted identifiers through its accessors. It is public in PHP visibility but is marked `@internal` and is not part of the public contract.
- **`Transaction\Repository\ProtectedTransactionConnection`** and **`ProtectedTransactionStatement`** — scoped driver receivers. Ordering reaches the private checkout reservation inside the package. The checkout exposes no callback entry point. Receivers expire at the end of the reservation and provide no raw-resource accessor.
- **Transaction Repository internals for exclusive write checkouts** — TransactionConnectionClaim, ProtectedTransactionDriver, ProtectedTransactionCommandExecutor and VerifiedTransactionCommandScope share claims, implement driver controls and restrict callback authority. These types are package internals and are not attachment or command entry points for consumers.
- **Members tagged `@internal` in public classes** — beyond those named above (for example in the Protected and Host-snapshot catalogs, plans, attempts, grants and settlement DTO constructors), the `@internal` tag in the source docblock is authoritative. A public class does not make its `@internal` members part of the contract.
- **`Transaction\Repository\SavepointScope`** — the shared `SAVEPOINT`/`RELEASE SAVEPOINT`/`ROLLBACK TO SAVEPOINT` helper used by `PdoSavepointTransactionRunner` and the movement operations of both Ordering managers (per [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md)). Savepoint names use the format `maatify_persistence_sp_<32 lowercase hex characters>`.

---

## 4. Ownership vocabulary

- **Caller-owned transaction** — a transaction under the caller's control. When a Transaction runner or Ordering operation joins an active caller transaction, the caller retains all final transaction control. A savepoint, where used, limits the effects of that individual operation. Ordinary and Keyset pagination read in the context supplied by the caller.
- **Host-owned transaction through B** — the Host keeps transaction and connection ownership while delegating exclusive use of its PDO to a source. The source issues transaction control under the shared reservation in response to explicit Host commands, including `beginRepeatableRead()`, `commit()`, and `rollBack()`. Page reads participate in that transaction. The Host decides how to resolve failures and when to end the transaction, request handoff, or discard the source. See [§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction).
- **Host-owned transaction through the protected write checkout** — the Host delegates exclusive PDO use for one write transaction. The checkout starts and ends that transaction under reservation in response to the Host's explicit `begin()`, `commit()`, or `rollBack()` calls. Registered commands and protected Ordering participate in the transaction. The Host directs handoff or discard and reconciles any unconfirmed outcome. See [§5.2.1](#521-optional-protected-host-write-transaction).
- **Package-owned transaction** — begun by the package itself because none was active when a Transaction-runner method, or `ScopedOrderingManager::moveWithinScope()`, was called, or when C1 accepted a qualified exclusive grant and confirmed the start of its read-only transaction. The package commits it on success. Transaction runners and Ordering attempt a best-effort rollback when their transaction is still active after a failure. C1 only attempts rollback after a read failure once cursor release and ownership of the active transaction on the same session are confirmed. See [§5.3.1](#531-optional-c1-snapshot-pagination).

---

## 5. Behavior contracts

### 5.1 `PdoTransactionRunner::run()`

```text
Host Input (callback) → run($callback) → caller-owned: participate
                                        → no active transaction: owned begin/callback/commit
                                 → Result: $callback's return value, or the original Throwable
```

- **Active caller-owned transaction:** the callback runs in place (`return $callback();`). No `BEGIN`, `COMMIT`, `ROLLBACK`, retry, or savepoint is issued by this call.
- **No active transaction:** the package begins one, runs the callback, and commits.
  - `beginTransaction()` returning `false` throws `TransactionExecutionException` **before** the callback runs.
  - `commit()` returning `false` triggers a best-effort rollback, then throws `TransactionExecutionException`; the result is never reported as committed.
  - A `Throwable` from `commit()` triggers a best-effort rollback and propagates unchanged. A `Throwable` from `beginTransaction()` propagates before the callback runs.
  - A `Throwable` from the callback triggers a best-effort rollback (if still active) and the **original** `Throwable` is rethrown unchanged; a rollback failure is swallowed and never replaces it.
- **Deadlock replay** (only on the owned path, only when `$maxDeadlockRetries > 0`): replay happens only when *all* of the following hold, otherwise the failure is handled exactly like a non-retrying owned transaction:
  1. the callback (not `BEGIN`/`COMMIT`) threw a `PDOException` whose `errorInfo` is SQLSTATE `40001` with driver code `1213`;
  2. a retry remains (up to 3 retries after the initial attempt, or up to 4 callback invocations);
  3. the driver still reports the transaction active right after the failure (otherwise the transaction had already ended for another reason and is not replayed);
  4. a no-op probe statement (`DO 0`) then succeeds and the driver reports **no** active transaction afterward (confirming the server rolled back the whole victim transaction).

  Neither check proves transaction identity; keeping the runner-owned transaction intact (never explicitly begun/committed/rolled back by the callback itself) is a precondition of correct replay. Delays before retries 1, 2, 3: none, a uniform 0–10 ms delay, a uniform 0–20 ms delay. Replay re-executes the **whole** callback; side effects outside this PDO connection (emails, HTTP calls, other connections, in-memory state) are **not** undone and would repeat — keep them outside the callback or make them idempotent. After the final attempt, the exact last `PDOException` is rethrown. Nothing else is ever retried: not lock-wait timeout (`1205`), not any other error, not a caller-owned transaction's failure.

### 5.2 `PdoSavepointTransactionRunner::run()`

- **No active transaction:** identical owned-transaction behavior to §5.1 (including optional deadlock replay); no savepoint SQL is issued on this path.
- **Active caller-owned transaction:** opens an operation-local savepoint through `SavepointScope::begin()`. On success, releases it (`RELEASE SAVEPOINT`). If release fails, it attempts to undo the operation and throws the release failure. On a callback `Throwable`, attempts `ROLLBACK TO SAVEPOINT` if PDO reports an active transaction, then attempts `RELEASE SAVEPOINT` only after the rollback is acknowledged. This cleanup is best effort. A state check or cleanup failure leaves the **original** callback `Throwable` unchanged. It never commits, fully rolls back, or retries the caller-owned transaction or the savepoint itself, even on a deadlock. If the driver reports the transaction no longer active immediately after `SAVEPOINT` succeeds (the server already ended the caller's transaction, for example on an earlier swallowed error), `SavepointScope::begin()` throws `TransactionExecutionException` before the callback runs.

#### 5.2.1 Optional protected Host write transaction

The Host delegates a standard non-persistent pdo_mysql connection with exception error mode and the default PDOStatement class. The Host supplies it idle, with autocommit enabled, and transfers exclusive use at the successful return of `attachExclusive()`. Local attachment issues no SQL. `begin()` qualifies the server session under reservation before `START TRANSACTION READ WRITE`, then checks the start on the same session. The session's isolation policy remains Host-controlled. Until confirmation, workload and transaction completion are unavailable. Aliases, multiplexing, automatic reconnect and replay invalidate eligibility. The registration protects this path and does not detect enrollment of the same PDO in Pagination. MySQL and MariaDB deployments must route the guarded unit through this checkout.

The reservation spans registered commands, protected Ordering, finish and disposition. Re-entry is rejected. A successfully completed lease command checks the same session, released resources and the expected transaction state before handing out its result. A result that exceeds its row bound raises `ProtectedTransactionException`. The checkout remains active if the result is released and the same active transaction is confirmed. Engine failures fence the checkout and prevent further application SQL. If guarded savepoint control fails, the first failure escapes instead of a boolean result. Cursor cleanup cannot replace that failure. Raw Ordering keeps its existing best-effort savepoint cleanup.

| Disposition | Reason and meaning for the Host |
|---|---|
| `UNCHANGED` | `CANCELLED_BEFORE_QUALIFICATION` when `returnForHost()` cancels a checkout before server work. The original connection is handed back. This conveys no server qualification. |
| `REUSABLE` | `COMMITTED` or `ROLLED_BACK` after the Host's explicit completion, verified on the same idle session with released resources. The Host may retrieve the same PDO once. |
| `DISCARD_REQUIRED` | `SERVER_QUALIFICATION_FAILED`, `START_UNCONFIRMED`, `TRANSACTION_LOST`, `OPERATION_UNCONFIRMED`, `COMMIT_OUTCOME_UNKNOWN`, `COMMIT_CONFIRMED_CONNECTION_UNSAFE`, `ROLLBACK_UNCONFIRMED`, or `HOST_DISCARDED`. Work, completion, and handoff are fenced. The Host chooses disposal and any reconciliation. |

`COMMIT_OUTCOME_UNKNOWN` includes loss of the commit response after dispatch. `COMMIT_CONFIRMED_CONNECTION_UNSAFE` preserves an acknowledged commit when connection handoff cannot be confirmed. `TRANSACTION_LOST` identifies loss of authority to use the transaction on a signalled MariaDB `HY000/1020`. The code alone cannot certify rollback, its exact source, or replay safety. No work is replayed. The checkout publishes the terminal DTO once, before releasing the reservation and after completion checks. `settlement()` is a local read and returns null before a terminal result. `discardForHost()` drops the library's references without issuing explicit SQL. Driver cleanup may roll back automatically, and diagnostic extensions may retain references independently of the library.

#### 5.2.2 Optional verified apply and discard

[DEC-022](docs/decisions/DEC-022-verified-apply-discard.md) defines one exclusive delegation for one transaction. The Host supplies an exact PDO or Pdo\Mysql object from pdo_mysql with native prepares, exception mode, the default statement class, no persistence and autocommit enabled. Supply it idle, with no pending transaction settings or resources and multi-statements disabled at construction. Keep raw aliases out of use, including through pools, reconnect, multiplexing or simultaneous enrollment in another capability. The package reserves the PDO object between Verified and Protected Transaction checkouts. Pagination registries remain separate. A trusted plan must preserve the same session and transaction, including the effects of its routines and triggers. The API does not establish safety for an external alias, a non-transactional side effect or a transaction-changing command.

Attachment performs no SQL. A refusal leaves the Host in control. The first start qualifies the session under reservation and verifies the qualified generation before work. The existing Catalog and Plan are used for both modes. Registered commands bind the exact named scalar values. The interface exposes a count or bounded rows, never a statement or PDO. The scope is valid only in the invoking Fiber or root context and closes before finalization. Reentry, stale scopes and a second operation are rejected before SQL. The checkout does not replay callbacks.

| Mode | APPLY | DISCARD |
| --- | --- | --- |
| Owned | Starts once, commits, and confirms the same idle session before returning payload. | Starts once, rolls back, and confirms the same idle session before returning payload. |
| Host | Host starts and may issue registered commands before or after one operation. The operation releases its savepoint and confirms continuity, then returns payload with PENDING_HOST. | Acknowledged ROLLBACK TO and RELEASE plus confirmed continuity permit payload and LOCAL_UNDONE. The Host decides the outer end in either case. |

An escaping callback Throwable retains its identity, including after a caught driver failure or a cleanup failure. The receiver preserves the first safety failure for fencing and settlement. A normal return from the callback after a latched failure is suppressed and that failure is thrown. A healthy generation may be rolled back or undone after a clean callback error. A failed control or uncertainty fences the checkout, suppresses payload and prevents further queries and handoff. A lost end reply records UNKNOWN. An acknowledged end remains COMMITTED or ROLLED_BACK if the later handoff check fails. ACK of ROLLBACK TO remains LOCAL_UNDONE even when release or continuity cannot be confirmed.

The immutable snapshot is a local read and publishes before release of the reservation. Operation facts survive a later Host end. Transaction outcome is null while active. Disposition null means the checkout holds the connection, UNCHANGED is an untouched cancellation without server qualification, and REUSABLE attests eligibility at confirmed completion. DISCARD_REQUIRED bars handoff even when the database effect is known. Successful handoff returns the same object once, while discard releases library references without an explicit database command. Disposal and loss of the final PDO reference do not attest transaction outcome.

See the [runnable Owned, Host and exception examples](examples/verified_transaction_usage.php).

### 5.3 `PdoPaginator::paginate()`

```text
Host Input (PageRequest, PdoPaginationQueryDescriptor, PaginationConfig, mapper)
    → paginate(...)
        → normalize page/perPage/sort → two independent count statements
        → data statement (skipped when filtered = 0) → map rows
    → Result: PageResultDTO<TItem>
```

Never begins, commits, or changes a transaction; it runs whatever statements the caller's connection is already in, or not in.

- **`page` normalization:** `null`, non-integer text, text outside the `PHP_INT_MIN..PHP_INT_MAX` range, or a parsed integer below `1` → `1`. Accepted text is an optionally-signed run of decimal digits after trimming.
- **`perPage` normalization** (independent of `page`): `null`, non-integer text, or out-of-range text → `PaginationConfig::$defaultPerPage`; an in-range integer below `$minPerPage` → `$minPerPage`; above `$maxPerPage` → `$maxPerPage`; otherwise the value itself.
- **Sort resolution:** For a non-null `sortBy` from `PageRequest`, the paginator applies `trim()` before checking the key and looking up `$config->sortWhitelist`. A null, empty, invalid, or unlisted key selects `$config->defaultSortBy`. For example, the request value `"id\n"` selects `id` when `id` is whitelisted. An LF within the trimmed key makes it invalid. The paginator trims a non-null `sortDirection` and matches `ASC` or `DESC` case-insensitively, falling back to `$config->defaultSortDirection` for a null or unmatched value.
- **Counting:** `$query->totalSql` and `$query->filteredCountSql` are each executed independently and must return exactly one row with exactly one column. The value must be a non-negative PHP integer or a string matching `\A[0-9]+\z` whose numeric value is within `0..PHP_INT_MAX`. Leading zeroes are accepted. Count strings are validated as returned by PDO, so any whitespace, including a trailing LF, is invalid. Any invalid value throws `PaginationExecutionException`.
  - **The count statements and the optional data statement execute separately.** A caller that needs one snapshot must run the whole call using consistent, non-locking reads on transactional tables under a compatible isolation level such as InnoDB `REPEATABLE READ`. Autocommit and `READ COMMITTED` can observe a concurrent commit between statements, including when `READ COMMITTED` runs inside a transaction. `PdoPaginator` leaves transaction and isolation control to the caller.
- **Empty-result shortcut:** when `filtered === 0`, the method returns immediately with `page = 1`, `data = []`, `totalPages = 0`, `hasNext = hasPrevious = false`, **without running the data query at all**.
- **Page clamp:** when the normalized `page` exceeds the computed `totalPages`, it is reset to `1` (not clamped to the last page).
- **Data query:** `$query->dataSql` is wrapped with an `ORDER BY <resolved sort column> <direction>[, <tie-breaker column> <tie-breaker direction>]` (the tie-breaker clause is omitted when it resolves to the same quoted column as the primary sort) and a `LIMIT`/`OFFSET` bound through the reserved parameter names `__pagination_limit`/`__pagination_offset` as integers. Each fetched row must be a non-empty associative array with string keys; it is passed to `$mapper`, whose return value must be an `array` or `object`.
- **Result:** a `PageResultDTO` with `hasNext = page < totalPages` and `hasPrevious = page > 1 && totalPages > 0`.

#### 5.3.1 Optional C1 snapshot pagination

The Host grants one live PDO session exclusively for one page. The session must be clean, without an explicit or implicit transaction or pending next-transaction settings. It must use `pdo_mysql`, `PDO::ERRMODE_EXCEPTION`, server `autocommit=1` and effective `REPEATABLE READ`. The Host supplies the session provenance and the ability to keep the checkout out of circulation or discard it. C1 checks observable eligibility and verifies session continuity before relying on inspected server settings, including when those settings rule out C1. The Host delegates a session without automatic reconnect or replay. C1 performs no reconnect, reset, or retry. Session defaults and `PDO::inTransaction()` alone cannot certify a checkout or detect a pending one-shot override.

C1 takes an already built descriptor and begins one owned `READ ONLY` transaction. Its guarantee is one InnoDB read view for the eligible non-locking count and data reads, including qualified subqueries. The consumer owns the predicates, count semantics, filters and ordering. Use a mapper that only maps the returned row, without further I/O or reliance on the connection after settlement. An independent earlier read required to build the descriptor is outside the guarantee. Page-to-page stability across calls is outside this scope.

C1 closes its read resources and verifies the delegated transaction and session before and after its own `COMMIT` or `ROLLBACK`. It explicitly suppresses chaining and release on these commands. It preserves session settings and PDO attributes. Page output requires confirmed completion. A PDO or mapper throwable escapes unchanged. On failure, C1 attempts rollback only for a confirmed owned scope with confirmed resource cleanup. An unconfirmed start, cleanup or finish keeps the checkout out of circulation.

| Outcome | Meaning for the Host |
| --- | --- |
| `UNCHANGED` | Known ineligibility before starting the transaction. The original Host policy retains the connection. This outcome grants no clearance for a new checkout. |
| `REUSABLE` | Confirmed completion of the owned transaction on the same session with released resources. A read failure followed by confirmed cleanup can also produce this outcome. |
| `DISCARD` | Connection or completion is unconfirmed, including a failed commit. The Host retires the checkout. |

The Host settles the exact checkout after the outer invocation completes, even on exception. An attempt without a terminal outcome keeps the checkout out of circulation. The Host must hold it out of circulation by default and make it available only after successful settlement. It retains ownership of the connection, reset and disposal and rejects a stale or foreign outcome. A failed settlement must leave the checkout unavailable and must preserve any original page failure. See [the standalone example](examples/pagination_snapshot_usage.php) for a fresh non-persistent connection.

#### 5.3.2 Optional forward-only Keyset pagination

**Host integration.** At startup, register one trusted `KeysetQueryDescriptor` per query shape and construct a paginator from the Host's deployment keyring. Use an InnoDB table with a stable, unique integer `id` and values in `1..PHP_INT_MAX`. The table can be a single bare name or a schema-qualified name. Project a nonempty list of unqualified names. Identifiers match `[A-Za-z_][A-Za-z0-9_]*` as supplied. Duplicate projection names and the reserved `__ks_id` are rejected regardless of case. The query ID and revision are nonempty, and both predicates are nonblank.

The Host supplies side-effect-free predicates registered from trusted code. The predicates use the table alias `t` and named PDO placeholders only, each name used once across both predicates. The declaration is a list of distinct names matching the ASCII identifier pattern without a leading colon. Keyset reserves the prefix `__ks_` regardless of case. It rejects the literal text `:__ks_` anywhere in either predicate, including quotes and comments, regardless of case. Registration owns the correspondence between real SQL placeholders and the declarations. The package performs this bounded text check and validates the binding map before `prepare()`. PDO can accept some ineligible shapes under emulation, including a repeated marker.

Pass an eligible `pdo_mysql` connection, in `ERRMODE_EXCEPTION` and `CASE_NATURAL`. Native and emulated prepares are eligible. The Host authorizes every request and supplies a trusted, nonempty scope identifying the actual dataset, tenant, and effective authorization boundary. If two sources use the same SQL and bindings, the Host supplies distinct scopes. The operation issues one bounded SELECT in the current read view. The Host retains the connection attributes, isolation and all transaction commands, and decides how to handle the connection after failure.

**Read and resume.** With a null cursor, the paginator reads the first page. For a continuation, give the same registration, scope, bindings, and effective limit with the returned `nextCursor`. An omitted limit uses the default and a supplied limit must be in `1..maxLimit`. The read groups the scope and filter predicates individually. The continuation adds `t.id > :__ks_after`, bound as an integer. Both reads use `id ASC` and fetch up to `limit + 1`. Projected columns must match the registered names. Every raw ID, including the extra row, must be a positive in-range integer or its canonical ASCII decimal representation and must strictly increase. After confirmed statement release the mapper receives only displayed rows in order. The extra row determines `hasNext`, and the next cursor comes from the last displayed raw ID. The empty page result is `[]/false/null`. Each request reads its own view. Changes to membership between reads are visible according to the Host's transaction and isolation.

**Cursor v1.** Treat the returned cursor as opaque when forwarding it. Its signed position is readable, and it carries no time-to-live. Verification binds it to the query ID and revision, the trusted scope, both query texts, the effective limit, and the exact typed bindings. A change in that context invalidates it before the page query. During rotation retain the old ID and secret while accepting old cursors, issue with the new active ID, and remove the old ID to retire its cursors. Keep the same keyring across workers and never reuse a key ID for a different secret. The exact signing and context format is recorded in the [approved v1 design](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7056253955).

**Failures.** Rejected cursors get a common `InvalidKeysetCursorException` message. The package distinguishes normal fetch completion from a PDO-reported failure, and preserves the primary failure if cleanup fails as well. A false return from PDO or invalid row and mapper results raise `PaginationExecutionException`. An unsuccessful close or cursor issuance prevents result delivery. A PDO or mapper throwable propagates unchanged. See the [runnable Composer example](examples/pagination_keyset_usage.php) for first-page and continuation calls.

### 5.3.3 Optional B pagination in a Host-owned transaction

**Ownership and eligibility.** The Host supplies its exclusively owned, non-persistent pdo_mysql PDO or Pdo\Mysql object with ERRMODE_EXCEPTION and the default PDOStatement class. The Host routes connection use through the source for the duration of delegation and retains it until handoff or discard. Shared references from before delegation, automatic reconnect or replay, and session multiplexing invalidate eligibility. B leaves connection attributes in the Host's control. It requires one stable session with autocommit enabled. One live source registration per PDO object is enforced in the process, including after handoff when that object still exists. The source permits one active checkout.

**Registration and transaction proof.** Register non-locking, side-effect-free InnoDB page plans and trusted Host commands. Each SQL statement declares its own unique names. The values are an exact named map of string, int, bool, or null. The Host is responsible for SQL semantics and template placeholder positions. Catalog lookups use PHP array-key semantics, including decimal string identifiers that PHP stores as integer keys. A bounded command must set a positive maxRows. If it exceeds the bound, it reports the row-limit failure after cleanup and scope confirmation. If confirmation fails, the source quarantines and still reports the row-limit failure. Its rows preserve the aliases and keys returned by PDO::FETCH_ASSOC, including numeric keys. Pagination data rows require string keys before mapping. A command declared PRESERVES_TRANSACTION must be incapable of changing the session or current transaction. The default effect invalidates the generation before the command is sent. Ambiguity quarantines the source. The Host requests beginRepeatableRead() on an idle checkout. Under the reservation, B sends SET TRANSACTION ISOLATION LEVEL REPEATABLE READ followed immediately by START TRANSACTION in the requested access mode. The confirmed same session and active transaction establish a new generation. An idle session with a pending next-transaction setting that B has not confirmed consumed is eligible for reconciliation, and becomes eligible for ordinary release after a qualified begin and confirmed end. The Host's explicit commit() and rollBack() use AND NO CHAIN NO RELEASE and confirm the same session without an active transaction. There is no retry or automatic completion on a page path.

**Page and settlement.** The Host authorizes a page on the proven generation, then creates an attempt before calling the paginator. A single reservation covers Host commands and page reads. Authorizations are consumed only on admission. A busy but otherwise eligible grant can be used in a fresh attempt. B completes and closes the count and data statements before calling the mapper. It checks the transaction again before publishing the terminal settlement and opening the reservation. Driver and mapper throwables propagate as the original objects. After a statement-level cardinality, data, or syntax/access-rule error in an eligible registered read, B still checks resource and transaction continuity. A driver failure that leaves the transaction effect uncertain quarantines the source. Ordinary exception arguments from B carry no PDO or statement, including through recoverable wrappers.

An attempt is NEW, RUNNING, or TERMINAL. settlement() returns null before it is terminal and returns the same immutable, resource-free value afterward, including after a subsequent read, release, retirement, or discard. A repeat invocation of the same attempt rejects without changing its settlement.

| Disposition | Reasons and effect |
| --- | --- |
| NOT_ACCEPTED | SOURCE_UNAVAILABLE, STALE_CONTEXT, GRANT_CONSUMED, or BUSY, in that order when several apply. The attempt has performed no database I/O. |
| RETURNED_TO_HOST | PAGE_COMPLETED or READ_FAILED after confirmation of the resource and transaction scope. The source still holds the connection. |
| HOST_ACTION_REQUIRED | RESOURCE_RELEASE_UNCONFIRMED, SCOPE_UNCONFIRMED, or SETTLEMENT_UNCONFIRMED. B invalidates the generation and quarantines the source before relinquishing the reservation. In an accepted invocation that returns or throws, a terminal fallback remains available even if ordinary settlement cannot finish. |

**Release, retirement, and discard.** release() makes the source available to another checkout only after confirmed idle status and resolution of pending settings and resources. retireForHost() permanently invalidates the source and transfers the same PDO after confirmed resource release. RETURNED_IDLE certifies the known idle state; RETURNED_FOR_RECONCILIATION asks the Host to resolve the connection before further circulation. An unresolved resource prevents transfer. The Host can call discardForHost() to permanently invalidate the source and drop the library's references. This call initiates zero explicit SQL, state probes, or transaction-end commands. PDO or its driver may automatically send ROLLBACK or perform other I/O or close the session when the last reference is released. The caller does not receive an acknowledgement of transaction outcome, timing, or server closure. A retirement or discard request while a reservation is held is rejected.

```php
$checkout = $source->checkout();
$checkout->beginRepeatableRead();
$grant = $checkout->authorizePage('items', ['tenant' => 7], new PageRequest());
$attempt = $checkout->newAttempt($grant);
try {
    $page = $paginator->paginate($attempt);
} finally {
    $settlement = $attempt->settlement();
}
// The Host uses $settlement on either path and explicitly ends its transaction.
// The Host can reconcile after a classified handoff. Unresolved resources prevent handoff;
// the Host may explicitly discard that connection.
```

See [DEC-020](docs/decisions/DEC-020-optional-host-transaction-snapshot-pagination.md).

### 5.3.4 Optional static composite Offset pagination

The Host builds one `StaticSortPlan` for a query shape and can reuse it across requests and connections. `PdoPaginator::paginateWithStaticSort()` uses the same Offset bounds, counts, mapper, PDO error handling, and `PageResultDTO` as [§5.3](#53-pdopaginatorpaginate). The existing paginator entry point and the C1, B, and Keyset paths retain their own contracts.

- **Plan:** the keys of `profiles` are the keys available to this call. Each key must already exist in the config's `SortWhitelist`. Declare the default key explicitly and include at least one composite profile. Every entry is validated when constructing the plan. Invalid profiles raise `InvalidPaginationConfigurationException`.
- **Terms:** a composite profile holds one to three additional terms in order. Each term has an `identifier` string and may have a fixed `direction` of type `SortDirectionEnum`. Omitting `direction` uses the resolved primary direction. Each identifier uses the `SortWhitelist` path grammar. Composite profiles reject the same path twice, including the primary path, using ASCII case-insensitive comparison without changing SQL spelling. Different aliases or qualification can represent the same column. The Host checks these equivalences in its query.
- **Explicit legacy profile:** a `null` profile uses the standard primary/tie-breaker clause, including its identical-identifier rule. A composite profile instead supplies the entire order by adding its terms to the primary. It supplies its own tie-breaker.
- **Request:** §5.3 resolves the key and direction independently. A key missing from the underlying whitelist falls back to the default. A key in that whitelist that is excluded from the static plan raises `InvalidPaginationQueryException` before any SQL, even if there are no matching rows.
- **Result and ownership:** `sortBy` and `sortDirection` still identify the effective primary choice. The array and JSON shape remains `PageResultDTO`. The Host supplies trusted SQL and identifier paths and verifies a total order over the actual result, including joins, NULLs, and collation. Use a qualified caller-managed transaction when the page needs one read view. Independent requests can see a different dataset. The method does not change PDO attributes or take transaction ownership.

See the [runnable example](examples/pagination_static_sort_usage.php) and [DEC-024](docs/decisions/DEC-024-optional-static-composite-offset-pagination.md). This path is in the unreleased repository source.

### 5.3.5 Optional dynamic Offset sorting

`PdoPaginator::paginateWithDynamicSort()` uses the Offset count sequence, page and size normalization, overshoot reset, mapper, integer LIMIT/OFFSET bindings, and PDO failure behavior in [§5.3](#53-pdopaginatorpaginate). The Host supplies trusted SQL, identifiers through `SortWhitelist`, the connection and any shared read view.

1. The Host's default and tie identifiers are checked first. The request must carry null `PageRequest::$sortBy` and `$sortDirection`. `$sorts` must be a PHP list of zero to three terms with exactly two string fields, `key` and `direction`. Each key must match a whitelisted public label exactly. Directions accept ASCII `ASC`/`DESC` case-insensitively, with no trimming. Malformed or unauthorized requests fail before query preparation. An empty list means use the configured default and its direction. Only an empty list selects the default automatically.
2. For identifier comparison, qualifier segments must match exactly and the final segment compares case-insensitively in ASCII. If all segments would match after folding but a qualifier differs in case, the combination fails with `InvalidPaginationConfigurationException` before SQL. This check applies to default versus tie, requested terms versus one another, and requested terms versus the tie. The emitted spelling is the Host's original mapped spelling. The Host establishes equivalence for different paths such as `id` and `o.id` or two projection aliases.
3. An explicit request cannot repeat a public key or its equivalent SQL path. The canonical tie key is reserved unless the Host enables `allowTieBreakerSelection`. Deliberately whitelisted aliases to the same tie path can be selected under either setting. If a selected term resolves to the tie, it must be last or alone. Its requested label and direction are kept. The Host reserves the physical column by withholding its selectable aliases and reserving the canonical key.
4. The effective SQL order is the selection, or the default for an empty selection, with the configured tie appended when its path is absent. A default that already uses the tie path keeps its own label and direction. The Host chooses a tie unique on the actual result rows under JOIN, NULL and collation behavior. A three-term request can produce four SQL terms. The term limit bounds the input shape. Query cost depends on the query, indexes and data. Different snapshots can produce Offset drift even with a total order.
5. The returned `MultiSortPageResultDTO` exposes the `PageResultDTO` plus the normalized selected list and exact effective order, including when filtered count is zero or the page resets to one. An automatically appended tie appears only as the last term with `key: null` and its actual direction, regardless of the selection policy. An explicitly selected or configured default label may appear. The result carries public labels and directions. SQL identifiers stay in the validated execution order.

Existing `paginate()`, static sort, C1, B, and Keyset calls keep their own result contracts. See [DEC-025](docs/decisions/DEC-025-optional-dynamic-offset-sort-contract.md) and the [runnable dynamic example](examples/pagination_dynamic_sort_usage.php) in the unreleased source.

### 5.4 `ScopedOrderingManager`

The host owns the table schema. The locking and rollback behavior described below requires a transactional storage engine that provides row locks and savepoints. Integration tests use InnoDB on MySQL and MariaDB. On a nontransactional table, a failed move can leave earlier shifts in place.

`$updatedAtValue` must be null if and only if `updatedAtColumn` is null. When configured, the timestamp is written only to the target row when it actually moves. All four methods first validate scope usage (`$scopeValue` consistent with `$config->scopeColumn`/`$config->nullableScope`); `getNextPosition`, `getNextPositionForUpdate`, and `moveWithinScope` throw `InvalidOrderingOperationException` on misuse, while `rowExistsInScope` additionally just returns `false` for `$id <= 0` without throwing (`moveWithinScope` throws `InvalidOrderingOperationException` for `$id <= 0` or `$newOrder <= 0`).

The host selects the PDO error mode. Ordering uses it as configured, including `ERRMODE_EXCEPTION`, `ERRMODE_SILENT`, and `ERRMODE_WARNING`. A statement failure reported through a return value raises `OrderingExecutionException`. In warning mode, PDO can also emit its usual warning. Any throwable from PDO or the host's warning handler propagates unchanged. A successful empty scope or missing row keeps the result documented below.

Returned order values must be integers in the running PHP process's `PHP_INT_MIN..PHP_INT_MAX` range. The manager validates decimal strings returned by PDO before conversion. A non-integer or out-of-range value raises `OrderingExecutionException`. Both next-position methods raise that exception when the maximum is `PHP_INT_MAX`. A move rejects an out-of-range order encountered in its locking reads before writing any rows. See [DEC-017](docs/decisions/DEC-017-ordering-integer-boundaries.md).

#### `getNextPosition()`

Returns `MAX(orderColumn) + 1` (or `1` for an empty scope) with a **plain, non-locking read**, excluding soft-deleted rows when `deletedAtColumn` is configured. It does not start a transaction and does not lock anything; concurrent inserts into the same scope must be serialized by the caller (for example a lock on a parent row). Under `REPEATABLE READ`, this read uses the transaction's existing consistent-read snapshot — a locking read taken afterward does **not** refresh it, so this value can be stale inside a long-running caller transaction unless no older snapshot exists yet (outside a transaction, under `READ COMMITTED`, or when the caller's own serialization lock was already taken before this read).

#### `getNextPositionForUpdate()`

Requires an **active caller-owned PDO transaction**. The optional fourth argument `$maxTailReads` defaults to **64** and accepts **2–512** inclusive. Invalid limits, scopes or transaction state raise `InvalidOrderingOperationException` before SQL. The limit counts locking reads from the first `SELECT … ORDER BY orderColumn DESC, idColumn DESC LIMIT 1 FOR UPDATE`. An allocation succeeds after two consecutive matching tail values, including a match on the last allowed read. An empty scope still requires two reads and returns `1`.

If the tail remains unstable, the call closes the final cursor and raises `OrderingExecutionException` with `getMeta()['reason'] === 'TAIL_READ_LIMIT_EXCEEDED'` and integer `getMeta()['maxTailReads']`. Driver, fetch and cursor-close errors retain priority. The limit bounds the number of reads. Each read can still wait according to server policy. The Host owns earlier writes and acquired locks and decides when to commit, roll back or retry.

A successful current read can see new commits and the transaction's own writes. Concurrent first inserts into an empty scope need caller coordination. The row scan and lock can cover more than the active scope without a suitable `(scope, order)` index. Under `REPEATABLE READ` the lock can also cover the gap above the last scanned row. Lock timeouts and deadlocks follow the error-mode policy above. See §7.3 for MariaDB `1020`.

Existing positional and named calls keep their argument names, defaults and `int` return type. Reflection exposes the optional fourth argument. Compared with RC.2, a call that would stabilize only after 64 reads can now fail unless given a larger valid budget. [DEC-021](docs/decisions/DEC-021-bound-ordering-tail-reads.md) records this approved behavior change.

#### `moveWithinScope()`

Never trusts a caller-supplied "current order"; it first locks the active ordering scope and derives its maximum from that locking read. It then reads the target’s current order through a separate locking query. A positive `$newOrder` is limited to `min($newOrder, max(1, $lockedMaximum))`. Runs through `PdoTransactionRunner` (so it owns a transaction when none is active, or participates in the caller's with the behavior of §5.1).

The range shift runs while the target row still occupies its original position. A uniqueness constraint on the order column or on the scope and order columns can reject an intermediate update, even when the intended final order would satisfy the constraint. For general reordering, use a schema that permits these intermediate positions. If the Host requires unique dense positions, see the [protected B1 profile](#541-protected-unique-safe-ordering-b1).

- Returns `true` on a successful move, and `true` (as a no-op, no SQL mutation issued beyond the scope lock and the read) when the row is already at the requested/clamped position.
- Returns `false` when the target row does not exist in the given scope at the moment it is read under the scope lock — **this is not an exception**, and in this common case **no savepoint statement is issued at all**: the absence is detected before any savepoint is opened.
- **Participating in a caller-owned transaction:** only once the row is confirmed to exist *and* its clamped target order actually differs from its current order (the no-op and missing-row cases above never reach this point) does it open an operation-local savepoint (`SavepointScope::begin()`), immediately before the first mutating statement. A `Throwable` from the range-shift or target-write statements triggers best-effort `cleanupAfterFailure()`. The cleanup attempts `ROLLBACK TO` only if PDO reports an active transaction, and attempts `RELEASE` only after the rollback is acknowledged. The original `Throwable` is rethrown unchanged. In the narrower case where the shifts succeed but the final target-row `UPDATE` itself still affects zero rows, the open savepoint is undone through `undoOrFail()` before returning `false` — there, `ROLLBACK TO` is **not** best-effort: its own failure is thrown instead of the `false` result; the following `RELEASE SAVEPOINT` is still best effort. If releasing the savepoint after a successful update fails, the method attempts to undo the operation and throws the release failure. The caller owns the surrounding transaction and its earlier work while that transaction remains active.
- **Owned path (no active caller transaction):** the method uses no savepoint. It requests a whole-transaction rollback on an exception, on a missing target, or when the target update affects zero rows. Rollback is best effort through `PdoTransactionRunner`.
- A deadlock (`1213`), the MariaDB `1020` hazard (§7.3), or a lock-wait timeout with `innodb_rollback_on_timeout=ON` can end the **caller's whole transaction** on the server. If PDO throws, that exception propagates unchanged. If PDO reports the statement failure by return value, the method raises `OrderingExecutionException`. This method does not retry either failure.

#### `rowExistsInScope()`

A scope-aware existence check using a plain read. It excludes soft-deleted rows when configured. After validating the scope it returns `false` immediately for `$id <= 0`.

---

#### 5.4.1 Protected UNIQUE-safe Ordering B1

B1 is an opt-in contract in the current source. It applies to the three B1 methods on an active `PdoProtectedTransactionCheckout`. Existing raw and protected Ordering methods retain their contracts. The [B1 decision](docs/decisions/DEC-026-protected-unique-ordering-b1.md) assigns the generic ordering operations to the package and business authorization, insert payload, schema, and transaction completion to the Host.

**Eligible profile.** Use two distinct permanent, non-partitioned InnoDB tables named as `schema.table` in trusted configuration. Items have a single-column `PRIMARY KEY (id)` and exactly one additional full-column BTREE unique key. A global list uses `UNIQUE (pos)` and a fixed seeded positive `globalAnchorId`. A scoped list uses `UNIQUE (scope_id, pos)` and a positive, non-null scope ID that identifies its existing anchor row. The anchor has a single-column primary key and no other unique key. An optional single-column foreign key from the scope to that primary key must have matching INT signedness, share the schema, and use `RESTRICT` or `NO ACTION` for both operations. Other inbound and outbound foreign keys are outside B1. If the runtime account can see the scope-to-anchor key but cannot verify either referential action, B1 reports `SCHEMA_UNVERIFIED` before ordering writes. The deployment attestation does not substitute for these required metadata fields.

ID, scope, position, and anchor key columns must be `NOT NULL INT`. The allowed values are positive. Signed INT is capped at 2,147,483,647. Unsigned INT requires 64-bit PHP and is capped at 4,294,967,295. Payload columns and ordinary non-unique full-column BTREE indexes are allowed within these conditions. Exclusions include nullable scopes, soft deletes, invisible or generated columns, CHECK constraints, additional unique keys, triggers, automatic changes on UPDATE, temporary shadows, and scope transfers. The Host provisions an anchor at migration or scope creation and keeps its identity stable.

**Deployment receipt.** Pass a reviewed, immutable map from the deployment artifact into `schemaAttestation`. Supply `activeSchemaRevision` independently from the active deployment configuration. These are the fields for receipt version 1.

| Field | Required value |
| --- | --- |
| `formatVersion` | Integer `1` |
| `schemaRevision` | Non-empty identifier of the reviewed deployment, migrations and writer review, equal to `activeSchemaRevision` |
| `serverVersion` | Exact string returned by `SELECT VERSION()` on the reviewed server |
| `itemsTable`, `anchorTable` | Exact qualified identifiers from the trusted configuration |
| `itemsFingerprint`, `anchorFingerprint` | Lowercase SHA-256 of each `SHOW CREATE TABLE` definition. Keep its bytes, including line breaks, and remove only the table-option ` AUTO_INCREMENT=<digits>` immediately after `) ENGINE=InnoDB`. |
| `dependenciesReviewed` | Boolean `true` after reviewing dependent triggers and foreign keys with sufficient privileges |
| `writersReviewed` | Boolean `true` after verifying the shared anchor protocol, the trusted catalog, session settings and absence of temporary shadows |
| `anchorProvisioned` | Boolean `true` after verifying the anchor provisioning and lifecycle |

The package validates the active transaction and session, requires `unique_checks=1` and `foreign_key_checks=1`, reads definitions before and after acquiring metadata locks on both tables, compares structural fingerprints, and validates metadata visible to the runtime account. Use the reviewed runtime SQL mode, quoted SHOW CREATE output and natural PDO column names. Receiving a matching receipt is conditional on the Host routing to the reviewed deployment and rotating the revision at DDL, engine, dependency or writer changes. Runtime inspection cannot prove the absence of dependencies hidden by grants or detect every out-of-protocol writer. Generate the receipt from reviewed deployment metadata, outside the request path.

**Writer protocol and results.** Every writer locks the same anchor first in the transaction, including imports and repairs. Once the anchor is locked, the package reads and checks current positions `1..N` against `maxScopeRows` and `maxPosition`. The Host acquires anchors in a consistent order when doing multiple scopes and keeps each allocation and its registered INSERT on the same checkout. The anchor lock is held until Host completion.

| Operation | Contract |
| --- | --- |
| `getNextUniquePositionForAppend()` | Returns `N+1` for immediate use in the Host's registered INSERT. Requires room for the new row and one free staging position. Use one allocation for each inserted row. |
| `moveUniqueWithinScope()` | Returns `true` after a locally verified move or a verified no-op. Non-no-op moves park the target at `N+1`, shift up by incrementing in descending position order or down by decrementing in ascending order, then finalize and verify before releasing the savepoint. |
| `deleteUniqueWithinScope()` | Executes one registered DELETE on the reserved checkout and compacts higher positions in ascending order. Returns `true` after count, final ordering and savepoint release are confirmed. Deletion can reclaim capacity without a staging position. |
| Absent row | Move and delete return `false` for an eligible, dense scope with no matching row. Neither operation then performs DML. |

The registered DELETE has `maxRows: null` and the exact form below, using the configured quoted identifiers. The global form omits the `scope_id` predicate and binding. The package supplies the ID, scope, and locked position. The catalog rejects a missing command or incompatible binding list. Authorize and check lifecycle state in the Host transaction with a compatible lock order. Use B1 only if that authorization can remain valid through the registered DELETE. Domain rules requiring additional predicates, related deletes, or membership changes need a separate contract.

```sql
DELETE FROM `app`.`playlist_items` WHERE `id` = :id AND `scope_id` = :scope_id AND `pos` = :position
```

Invalid identifiers, bounds, or target positions raise the corresponding Ordering configuration or operation exception. `OrderingExecutionException::getMeta()['reason']` reports `SCHEMA_UNVERIFIED`, `INELIGIBLE_SCHEMA`, `ANCHOR_MISSING`, `SCOPE_NOT_DENSE`, `SCOPE_ROW_LIMIT`, `NO_STAGING_POSITION`, `MOVE_INVARIANT_FAILED` or `DELETE_INVARIANT_FAILED`. A positive move order beyond `N` is invalid when the target exists. A failed count or local verification cannot return a success result.

For confirmed local failures after mutation, the package undoes and releases the local savepoint only while it can verify continuity. A driver, cursor, savepoint, or continuity failure can fence the whole checkout. The original failure is propagated. Follow `settlement()` and discard on `DISCARD_REQUIRED` without any further SQL. Preserve earlier Host writes only if continuity and local undo were confirmed. The Host controls commit and rollback, and the library does not retry. See the [Basic and Professional workflows](docs/guides/USAGE_GUIDE.md#protected-unique-safe-ordering-b1).

## 6. Exceptions reference

Every package-defined exception listed below is `final`, extends a `maatify/exceptions` base class, and implements `PhpPersistenceExceptionInterface`. None declares its own constructor: construction, `getMessage()`, `getCode()`, `getPrevious()`, and the `ApiAwareExceptionInterface` accessors (`getErrorCode()`, `getCategory()`, `getHttpStatus()`, `isSafe()`, `getMeta()`, `isRetryable()`) are all inherited from `maatify/exceptions ^1.0`, whose public constructor shape is:

```php
__construct(
    string $message = '',
    int $code = 0,
    ?Throwable $previous = null,
    ?ErrorCodeInterface $errorCodeOverride = null,
    ?int $httpStatusOverride = null,
    ?bool $isSafeOverride = null,
    ?bool $isRetryableOverride = null,
    array $meta = [],
    ?ErrorPolicyInterface $policy = null,
    ?EscalationPolicyInterface $escalationPolicy = null,
)
```

That constructor and the resulting accessor API are owned by `maatify/exceptions`, not by this package; see that package's own documentation for details beyond what a consumer needs to catch and read these exceptions.

External `\PDOException` or callback/mapper `Throwable` generally propagates unchanged. Cleanup failures may be suppressed to preserve the primary failure, and an eligible owned deadlock can be consumed by successful replay. Catch `\Throwable` if you need to catch both package-defined and external errors and distinguish them with `instanceof PhpPersistenceExceptionInterface`.

### 6.1 Transaction

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidTransactionConfigurationException` | `SystemMaatifyException` | A transaction-runner constructor receives `$maxDeadlockRetries` outside `[0, 3]`. |
| `TransactionExecutionException` | `SystemMaatifyException` | A PDO transaction-control statement (`BEGIN`, `COMMIT`, `SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`) returns `false` instead of throwing; or the shared savepoint helper observes that a caller-owned transaction is no longer active immediately after a successful `SAVEPOINT`, before the callback or Ordering mutation. |
| `ProtectedTransactionException` | `SystemMaatifyException` | The optional [Protected](#521-optional-protected-host-write-transaction) and [Verified](#522-optional-verified-apply-and-discard) paths reject a connection, lifecycle action, command registration, lookup, bindings or row bound, or cannot confirm a resource, session or control operation when the driver does not throw. Verified also rejects an invalid callback decision, an unavailable command grant and an unsafe PDO handoff. Propagated driver and callback throwables retain their identity under the precedence rules in §5.2.1 and §5.2.2. |

### 6.2 Pagination

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidPaginationConfigurationException` | `SystemMaatifyException` | Invalid `PaginationConfig` or `StaticSortPlan` construction; an empty or malformed `SortWhitelist`; `SortWhitelist::quotedIdentifierFor()` called with a key outside the whitelist; ambiguous qualifier casing in dynamic sorting (§5.3.5); invalid Keyset limit policy or signing keyring (see §3.3). |
| `InvalidPaginationQueryException` | `SystemMaatifyException` | Invalid `PdoPaginationQueryDescriptor` construction (blank/`;`-containing/reserved-placeholder SQL, or an invalid parameter key/value); a whitelisted request key excluded from a static sort plan (§5.3.4); invalid or forbidden dynamic sort input (§5.3.5); an invalid Keyset registration, request or binding map, or an ineligible Keyset connection (see §5.3.2). |
| `PaginationExecutionException` | `SystemMaatifyException` | Offset execution failure in the standard, static or dynamic path (§5.3); an invalid `PageResultDTO`, inconsistent dynamic result metadata, or attempted native restoration of a dynamic result (§3.3); an invalid Keyset result, a failed Keyset execution or unsupported paginator serialization or restoration (§3.3/§5.3.2); rejected B page results such as invalid counts, data rows, or mapper return values (§5.3.3). |
| `SnapshotPaginationException` | `SystemMaatifyException` | C1 rejects the delegated connection or operation state, cannot confirm resources or owned transaction control, or is given an invalid discard outcome. Driver and mapper throwables retain their identities. |
| `InvalidKeysetCursorException` | `ValidationMaatifyException` | A supplied Keyset cursor is malformed, out of range, unauthentic, uses an unavailable key, or does not match the authorized query context. |
| `HostSnapshotPaginationException` | `SystemMaatifyException` | B reports invalid registration or bindings, an ineligible connection, an unavailable checkout or attempt, a rejected retirement, blocked serialization or restoration, a registered command exceeding its row bound, or a driver return value or observation that prevents confirmation of resources, session, or transaction state. External driver or mapper throwables retain their identities under the failure rules in [§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction). |

### 6.3 Ordering

| Exception | Base | Thrown when |
|---|---|---|
| `InvalidOrderingConfigurationException` | `SystemMaatifyException` | Invalid `ScopedOrderingConfig` construction (bad table/column identifier, or `nullableScope` without a `scopeColumn`). |
| `InvalidOrderingOperationException` | `ValidationMaatifyException` | Invalid runtime arguments or an unmet precondition on a `ScopedOrderingManager` call: inconsistent scope usage, an invalid row id or target order in `moveWithinScope()`, an updated-at value/column mismatch, an out-of-range locking tail-read limit, or a locking next-position read requested without an active PDO transaction. |
| `OrderingExecutionException` | `SystemMaatifyException` | Preparing, executing, fetching, or closing an Ordering statement fails without throwing, the next-position aggregate query returns no row, an ordering value is not a representable integer, the next position exceeds `PHP_INT_MAX`, or the tail-read budget ends without a stable tail. Only the last case sets the exhaustion metadata described in §5.4. A fetch with SQLSTATE `00000` and no row is ordinary end of results for reads that allow no row. |

---

## 7. Concurrency, transaction, and isolation limits

### 7.1 Transaction ownership summary

| Situation | Package behavior |
|---|---|
| Transaction runner or Ordering operation that owns transaction control, with no active transaction | Begin, run the operation, commit. On failure, roll back (best effort) and rethrow the original `Throwable`. A `false` from `beginTransaction()`/`commit()` fails closed with `TransactionExecutionException`. |
| Transaction runner or Ordering operation joins an active caller transaction | The caller retains final transaction control. A savepoint, when used, limits only the effects of this operation. |
| Callback/operation throws | The original `Throwable` is rethrown unchanged. A cleanup (rollback/savepoint-undo) failure never replaces it. |
| Deadlock (`40001`/`1213`) in a package-owned transaction | Replayed only under the exact conditions of §5.1, default off (`$maxDeadlockRetries = 0`). |
| Deadlock or any error in a caller-owned transaction | Never replayed by this package; it propagates to the caller, which decides. |
| Engine ends the transaction on the server (e.g. MariaDB `1020`, §7.3) | A thrown error propagates unchanged. Ordering reports a return-value failure as described in §5.4. The package never retries the caller's work and never changes a setting to work around it. |

Ordinary and Keyset pagination use the transaction context supplied by the Host ([§5.3](#53-pdopaginatorpaginate) and [§5.3.2](#532-optional-forward-only-keyset-pagination)). C1 manages the read transaction it starts on an eligible delegated connection and reports a terminal outcome to the Host ([§5.3.1](#531-optional-c1-snapshot-pagination)). In B, the Host directs transaction control through explicit commands and keeps ownership throughout the checkout. The source serializes those commands with page attempts, and the Host decides remediation, handoff, or discard ([§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction)).

The protected write checkout executes explicit Host transaction commands and refuses further work after the transaction becomes unsafe (§5.2.1).

### 7.2 Savepoint ownership

Savepoints are used **only** inside a Host-owned transaction, and only to limit one operation's own failure without ending that transaction. `PdoSavepointTransactionRunner` and the movement operations of both Ordering managers use the same `@internal` helper, `SavepointScope`, owned by the Transaction capability per [DEC-011](docs/decisions/DEC-011-responsibility-placement-and-savepoint-helper.md). The protected checkout suppresses further savepoint SQL after a driver failure fences execution (§5.2.1). Savepoint names follow `maatify_persistence_sp_<32 lowercase hex characters>` (a 128-bit random suffix), chosen so that this package and `maatify/persistence` can coexist on the same connection without colliding ([DEC-005](docs/decisions/DEC-005-coexistence-with-legacy-package.md) item 4). A savepoint limits one operation's mutation window within a transaction the server still considers active (§7.3).

### 7.3 MariaDB `innodb_snapshot_isolation` hazard

This is a documented engine hazard. [DEC-009](docs/decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) activates the opt-in protected checkout. [DEC-013](docs/decisions/DEC-013-test-only-database-version-boundary.md) specifies test versions. Neither record advertises unconditional support.

The raw Ordering entry points have the following verified behavior on real MariaDB cells (`ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest`, `ScopedOrderingManagerStaleSnapshotTest`, and `ScopedOrderingManagerMoveStaleSnapshotTest`):

- With `innodb_snapshot_isolation=ON` (the MariaDB 11.8+ default), a locking read inside `getNextPositionForUpdate()` or the scope lock inside `moveWithinScope()`, taken after an older `REPEATABLE READ` snapshot and touching a row changed since that snapshot, fails with driver code `1020`. In the tested exception mode, the driver's `PDOException` propagates unchanged. A failure reported by return value follows the error-mode policy in §5.4. No savepoint is attempted, no retry happens, and no setting is overridden.
- In the covered scenarios the server rolled back the caller’s whole transaction, including an earlier write, while `PDO::inTransaction()` could still report `true`. Continuing to use the connection as if the transaction were alive risks later statements running under an unintended implicit/autocommit transaction.
- With the setting `OFF` (several ratified test cells default to `OFF`; see [docs/architecture/CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md) §3), the ordinary "locking read sees the latest committed state" guarantee holds, matching MySQL 8.0/8.4 behavior.
- Ordering leaves `innodb_snapshot_isolation`, the isolation level, and connection attributes under Host control, including when it propagates error `1020`. Separately, when the Host explicitly calls B’s `beginRepeatableRead()`, B sets `REPEATABLE READ` for the next transaction on the delegated connection as described in [§5.3.3](#533-optional-b-pagination-in-a-host-owned-transaction). This transaction-scoped setting leaves the session defaults and `innodb_snapshot_isolation` unchanged.

The connection owner must account for the observed difference between the server’s transaction state and PDO’s report. The protected write checkout (§5.2.1) fences further work after loss of transaction authority, preserves the original failure, and leaves reconciliation to the Host. `ProtectedScopedOrderingManagerIntegrationTest` exercises a real MariaDB `1020` before a savepoint and during an update after a savepoint.

### 7.4 Deadlock replay bounds

Deadlock replay (§5.1) is **opt-in** (`$maxDeadlockRetries` defaults to `0`), bounded to at most 3 retries after the initial attempt, applies **only** to a package-owned transaction (never a caller-owned one), and only for the specific SQLSTATE `40001` / driver code `1213` signature confirmed to have actually ended the runner-owned transaction. It is not a generic "retry on any database error" mechanism.

---

## 8. Database engine support

Per [docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md](docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md) §1, **"version tested" and "range verified" are different claims.** This document, like the README, makes only the narrower claim that is actually proven today.

- **No supported version range is declared for MySQL or MariaDB.** Both remain design targets ([DEC-008](docs/decisions/DEC-008-database-engine-targets-and-verification-policy.md)).
- The Transaction, Pagination, and Ordering capabilities **themselves** (not just infrastructure-level checks) have passing real-database Integration evidence, including genuine deadlocks, a deterministic concurrent-writer snapshot test (§5.3/B-08a), and real stale-snapshot concurrency, on the following **named, individually tested** versions ([DEC-013](docs/decisions/DEC-013-test-only-database-version-boundary.md)):

  | Engine | Versions tested |
  |---|---|
  | MySQL | `8.0.16`, `8.4.13`, `9.7.4` |
  | MariaDB | `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3` |

- `mysql-26.10.0` (the MySQL Innovation release) is **exploratory only**: it runs only on manual CI dispatch, is never required, and is excluded from every claim above.
- "Version tested" here means exactly that: these named versions pass the capability's own Integration suite. It is **not** a "range verified" claim under DEC-008's endpoint rule, and it does not imply that an untested intermediate or later version behaves identically — in particular, do not infer MariaDB behavior for a version not listed above, since the `innodb_snapshot_isolation` default itself differs between the listed MariaDB versions (§7.3).
- The package implements no retry, session setting change, or recovery for MariaDB `1020`; see §7.3.

---

## 9. Verification evidence

This section exists so that every behavioral claim above can be checked directly rather than taken on faith. It is not a duplicate of [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md); it names the currently-passing tests this reference's claims were checked against while writing it.

| Capability | Reflection/regression evidence (public API shape) | Representative behavior evidence |
|---|---|---|
| Transaction | `tests/Regression/TransactionPublicApiRegressionTest.php`, `tests/Regression/Exception/*ContractTest.php` | `tests/Unit/Transaction/Repository/{PdoTransactionRunnerTest,PdoSavepointTransactionRunnerTest,PdoSavepointTransactionRunnerRetryTest,OwnedTransactionExecutorTest,DeadlockRetryFailClosedTest,SavepointScopeTest}.php`; `tests/Integration/Transaction/{PdoTransactionRunnerIntegrationTest,PdoSavepointTransactionRunnerIntegrationTest,DeadlockRetryIntegrationTest}.php` (genuine deadlocks via a real partner process) |
| Pagination | `tests/Regression/Pagination/{PaginationPublicApiRegressionTest,PaginationSqlContractRegressionTest}.php` | `tests/Unit/Pagination/*.php`; `tests/Integration/Pagination/{PdoPaginatorQuerySemanticsTest,PdoPaginatorFailureContractTest,PdoPaginatorSnapshotConsistencyTest,PdoPaginatorTransactionTest}.php` (caller-controlled pagination); `tests/Integration/Pagination/{PdoSnapshotPaginatorTest,PdoSnapshotPaginatorFailureTest}.php` (C1 snapshot, session continuity, and Host disposition) |
| Optional Keyset | `harness/keyset-api.php` and the installed Composer example via `composer test:consumer` | `tests/Unit/Pagination/{KeysetContractTest,PdoKeysetPaginatorTest}.php` (registration, JSON, SQL/wire golden, diagnostics and failure); `tests/Integration/Pagination/PdoKeysetPaginatorIntegrationTest.php` (PDO, native/emulated, Host transaction, scope, and bounds) |
| Optional B | `harness/host-snapshot-api.php` from Consumer Verification | `tests/Integration/Pagination/PdoHostSnapshot*Test.php` (source, isolation, commands, settlement, handoff and discard) |
| Optional static composite Offset | `tests/Unit/Pagination/StaticSortPaginationTest.php` (Reflection, SQL/DTO golden, validation and failure) | `tests/Integration/Pagination/StaticSortPaginationIntegrationTest.php` (Product-like window parity, I18n-like Unicode grid, Host transaction and cursor); the installed example via `composer test:consumer` |
| Optional dynamic Offset | `tests/Unit/Pagination/DynamicSortPaginationTest.php` (Reflection, SQL/bindings, request and alias preflight, result invariants, failure precedence) | `tests/Integration/Pagination/DynamicSortPaginationIntegrationTest.php` (mixed-direction joined grid, Unicode/NULLs, snapshot drift, PDO modes and cursor release); the installed example via `composer test:consumer` |
| Caller-owned PDO composition | `tests/Integration/Pagination/PaginationOrderingSavepointTest.php` | An unbuffered mapper fails inside an operation savepoint. The original exception escapes, only the local write is undone, and the Host can allocate an Ordering position and finish the outer transaction on the same PDO. |
| Ordering | `tests/Regression/Ordering/OrderingPublicApiRegressionTest.php` | `tests/Unit/Ordering/{Config/ScopedOrderingConfigTest,Repository/ScopedOrderingManagerSavepointWiringTest,Repository/ScopedOrderingManagerExecutionFailureTest}.php`; `tests/Integration/Ordering/{ScopedOrderingManagerReadOperationsTest,ScopedOrderingManagerStaleSnapshotTest,ScopedOrderingManagerMoveStaleSnapshotTest,ScopedOrderingManagerLazySavepointTest,ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest,ScopedOrderingManagerGlobalMoveTest,ScopedOrderingManagerScopedMoveTest,ScopedOrderingManagerSoftDeleteMoveTest,ScopedOrderingManagerTransactionTest,ScopedOrderingManagerRollbackTest}.php` (real stale-snapshot concurrency and a genuine deadlock while acquiring the scope lock) |

A detailed, dated per-behavior mapping (the B-01 to B-16 rows, each with its legacy-vs-current delta) remains available in [docs/architecture/BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md) as a framed historical/evidence record; this section and §§5–8 above are the current normative restatement for a consumer.

---

## 10. Related documentation

- [README.md](README.md) — quick overview, installation/access status, requirements.
- [docs/guides/USAGE_GUIDE.md](docs/guides/USAGE_GUIDE.md) — capability map, walkthroughs, and the boundaries of each capability before its examples.
- [examples/](examples/) — runnable, Public-API-only examples for all three capabilities.
- [llms.txt](llms.txt) — AI consumer navigation map.
- [docs/architecture/ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md), [CONTRACT_MATRIX.md](docs/architecture/CONTRACT_MATRIX.md), [BEHAVIOR_CONTRACT.md](docs/architecture/BEHAVIOR_CONTRACT.md), [DATABASE_SUPPORT_AND_TEST_POLICY.md](docs/architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md), [CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md) — design, legacy-mapping, and test-matrix detail, dated and framed as historical/evidence records rather than a second current contract.
- [docs/decisions/DECISIONS_INDEX.md](docs/decisions/DECISIONS_INDEX.md) — durable decisions this reference applies.
