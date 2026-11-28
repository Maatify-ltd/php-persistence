# Testing conformance evidence

This point-in-time audit covers the Testing batch in the standards conformance
review. It records how a reviewer can trace public behavior to executed tests and
recheck the identified coverage gap. The package reference owns the public
contracts.

## Reviewed baseline

- Parent review: [PR #108](https://github.com/Maatify-ltd/php-persistence/pull/108)
  at [`e27c5b472b96a1f1bbe6f3bc33643073c309e13c`](https://github.com/Maatify-ltd/php-persistence/commit/e27c5b472b96a1f1bbe6f3bc33643073c309e13c).
- Reviewed tree: `39a8e723cdb6bdeeeffc6ca9dffb3a17aefd299f`. The parent PR's
  synthetic merge `9d1a74d4bf2804179ef4ec999fa0fab18cf3b546` has the same tree.
- Applicable standard: [Testing 3.0.0](../../docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md)
  at the [adopted pin](../../docs/php-engineering-standards/STANDARDS_MANIFEST.md)
  `c1dbb2da062a35c45638abf2bcb2ae210140eb5d`.
- Audit verdict: **REQUEST CHANGES** for the system-level coverage in
  [F108-18](#f108-18--raw-ordering-settlement-after-a-zero-row-update).
  The evidence below applies to the reviewed parent tree.

## Requirements and evidence

| Testing section | Result | Evidence |
| --- | --- | --- |
| §§1, 2 — Observable behavior and mandatory protection | Partial | Public API tests run across real PDO boundaries. F108-18 identifies a specific externally observable settlement outcome that has only isolated recurrence coverage. |
| §3.1 — Unit | Pass | [PHPUnit suite definitions](../../phpunit.xml) separate Unit, Regression and Integration. The local Unit and Regression rerun completed 970 tests and 7,969 assertions. |
| §3.2 — Integration | Pass for the observed runs | [Integration entry point](../../scripts/integration.php) provisions the database and executes the Integration suite. The recorded parent CI ran it on 15 real-service jobs. |
| §3.3 — System/E2E | Partial | The coverage map below identifies public entry points, real boundaries and final observations. F108-18 concerns a missing outcome in this layer. |
| §3.4 — Consumer Verification Harness | Pass | The [harness](../../scripts/consumer-verification.php) creates separate temporary Composer roots with isolated Composer home, cache and vendor directories. It installs the candidate archive and legacy fixture as dependencies with `--no-dev`, checks installation identity and runs the scenarios through production Composer autoloading. Actual runs and observable outcomes are below. |
| §3.5 — External-provider boundary | Not applicable to this package | The [Manifest](../../docs/php-engineering-standards/STANDARDS_MANIFEST.md#resolution-summary) explains applicability. Test databases are provisioned and controlled by this repository. |
| §3.6 — Final classes and doubles | Pass | The reviewed doubles target PDO or PDOStatement boundaries. Public final classes are instantiated directly. The [development dependencies](../../composer.json) and [PHPStan configuration](../../phpstan.neon) identify the package's testing and analysis tools. |
| §4.1 — Existing behavior | Pass for reviewed workflows | The differential consumer runs, public API regression tests and real-PDO assertions protect the established contracts listed below. Compatibility with the five external consumers is tracked in [#43](https://github.com/Maatify-ltd/php-persistence/issues/43). |
| §4.2 — New observable behavior | Partial | Public workflow tests cover the changed Pagination and Transaction boundaries. F108-18 concerns the new raw Ordering exception outcome. |
| §4.3 — Critical workflows | Partial | Transaction replay and the protected Ordering writer invariants have real-contender evidence. Raw Ordering settlement after a zero-row write needs the specific system-level check in F108-18. |
| §4.4 — Integration boundaries | Partial | The observed fixtures use real databases and observable state. The isolated raw Ordering settlement check and the related real-PDO helper checks leave the F108-18 boundary uncovered. |
| §4.5 — Regression protection | Partial | [RED/GREEN sources](#regression-evidence) cover the sampled fixes. The raw Ordering recurrence case has focused Unit protection. The affected public system boundary remains open under F108-18. |
| §4.6 — Internal refactors | Pass | The [Ordering structure regression](../Regression/Exception/OrderingExceptionContractTest.php) protects shared executor usage. The surrounding public workflows ran through the real-PDO suite. |
| §4.7 — Host CI | Not applicable to this artifact | The reviewed artifact is a standalone reusable Composer package. Its package CI evidence is linked below. |
| §5 — Completion | Open | The reviewed test runs passed. The batch gate still requires the public-path coverage identified in F108-18. |

## Public behavior coverage

| Guarantee | Executed evidence and observable result |
| --- | --- |
| Owned Transaction commit, rollback, result and exceptions | [Transaction runner](../Integration/Transaction/PdoTransactionRunnerIntegrationTest.php), [outcomes](../Integration/Transaction/TransactionOutcomeIntegrationTest.php) and the [consumer catalog](../../harness/consumer.php). An independent connection observes committed data and distinguishes pending or rolled-back writes. |
| Caller-owned savepoints and deadlock replay | [Savepoint runner](../Integration/Transaction/PdoSavepointTransactionRunnerIntegrationTest.php) and [deadlock tests](../Integration/Transaction/DeadlockRetryIntegrationTest.php). A real contender induces InnoDB deadlocks. Tests check replay bounds, a single committed outcome, exception identity and preservation of the caller's outer work. The differential consumer also exercises both runner variants. |
| Protected and Verified Transaction | [Protected checkout](../Integration/Transaction/PdoProtectedTransactionCheckoutTest.php), [protected deadlock](../Integration/Transaction/ProtectedTransactionDeadlockIntegrationTest.php), [Verified lifecycle](../Integration/Transaction/VerifiedTransactionIntegrationTest.php) and [Verified deadlock](../Integration/Transaction/VerifiedTransactionDeadlockIntegrationTest.php). Assertions cover recovered data, settlement, no replay after fencing, failure precedence, and explicit return or discard of the connection. The consumer runs the [protected scenario](../../harness/protected-transaction-api.php) and the installed [Verified example](../../examples/verified_transaction_usage.php). |
| Offset Pagination and sort plans | [Query semantics](../Integration/Pagination/PdoPaginatorQuerySemanticsTest.php), [failure](../Integration/Pagination/PdoPaginatorFailureContractTest.php), [snapshot consistency](../Integration/Pagination/PdoPaginatorSnapshotConsistencyTest.php), [static sort](../Integration/Pagination/StaticSortPaginationIntegrationTest.php) and [dynamic sort](../Integration/Pagination/DynamicSortPaginationIntegrationTest.php). The tests check returned pages and totals, bindings, cursor/transaction ownership and concurrent committed data. The consumer compares published results and runs the installed sort examples. |
| C1, Keyset and Host Snapshot B | [C1 results](../Integration/Pagination/PdoSnapshotPaginatorTest.php) and [failure outcomes](../Integration/Pagination/PdoSnapshotPaginatorFailureTest.php), [Keyset](../Integration/Pagination/PdoKeysetPaginatorIntegrationTest.php), [Host transaction](../Integration/Pagination/PdoHostSnapshotTransactionTest.php) and [retirement](../Integration/Pagination/PdoHostSnapshotRetirementTest.php). Observable results include page continuations, mapper exception identity, session exclusion, and return or quarantine. The installed [C1 and Keyset examples](../../examples/) and the [Host Snapshot scenario](../../harness/host-snapshot-api.php) exercise the consumer side. |
| Raw and Protected Ordering | [Raw stale-snapshot moves](../Integration/Ordering/ScopedOrderingManagerMoveStaleSnapshotTest.php), [savepoint and exception paths](../Integration/Ordering/ScopedOrderingManagerLazySavepointTest.php), [MariaDB outcomes](../Integration/Ordering/ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest.php) and [protected Ordering](../Integration/Ordering/ProtectedScopedOrderingManagerIntegrationTest.php). These observe committed positions, locks, rollback and fencing. The raw zero-row rejection with failed settlement is the outstanding case in F108-18. |
| Protected UNIQUE-safe Ordering B1 | [B1 system tests](../Integration/Ordering/ProtectedUniqueOrderingTest.php) cover dense append, move, delete and compact, schema eligibility, independent competing writers, rollback and a real deadlock. The installed [B1 example](../../examples/ordering_unique_usage.php) checks the consumer path. |
| Cross-capability caller ownership and serialization | The [Pagination/Ordering composition test](../Integration/Pagination/PaginationOrderingSavepointTest.php) exercises cursor release, original failure and caller-owned rollback on the same PDO. [Settlement JSON tests](../Regression/SettlementJsonContractTest.php) lock public codes and fields for the data-only projections. |

## Executed evidence

[Parent CI run 46101063647](https://github.com/Maatify-ltd/php-persistence/actions/runs/46101063647)
passed 31 required jobs on the reviewed head. Its logs contain 60 Integration
suite executions of 515 tests each, 60 immediate checks with zero leftover tables
and open transactions, and 30 verified teardowns. Examples Smoke executed nine
examples in each of four cells. The Consumer Verification jobs ran the two
scenarios on MySQL 8.4.13 and MariaDB 11.8.9 in two clean lifecycles each across
two PHP cells, for 16 candidate installations. The scenario sources check
production autoloading, stored state, nested savepoint isolation and an actual
deadlock retry. The [consumer evidence guide](../../docs/guides/CONSUMER_VERIFICATION.md)
describes the commands, fixture ownership, cleanup and legacy identities.

Use these commands from a checkout of the reviewed parent with its normal
dependencies. The local single-cell Integration command is a focused run.
The linked parent CI evidence records the repeated matrix execution.

```sh
vendor/bin/phpunit --testsuite unit,regression
php scripts/integration.php --cell=mysql-8.4.13 --fresh-runs=1 --suite-passes=1
PP_DB_CELL=mysql-8.4.13 composer test:consumer
```

The [DEC-031 exception](../../docs/decisions/DEC-031-first-stable-real-host-exception.md)
applies to first-Stable real-host validation. Release qualification, including
eligibility and final-candidate evidence, is tracked under [#88](https://github.com/Maatify-ltd/php-persistence/issues/88).

## Regression evidence

- Raw Ordering settlement: [F108-05 compared the prior and corrected results](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7229112789).
  The preserved [focused tests](../Unit/Ordering/Repository/ScopedOrderingManagerSavepointWiringTest.php)
  check false/throwable controls, primary error identity and transaction
  ownership. F108-18 records the separate system-level coverage gap.
- Exit status from the syntax command: the [F108-09 before/after record](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7229920165)
  captures the same missing-input condition on both heads and documents the
  propagated failure and the successful case.
- Style checker: the [reproduced failure](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7232695841)
  and [accepted correction](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7232974255)
  record the composite command and PHP 8.6 results. [Committed recurrence
  cases](../Regression/PerCodingStyleDeltaCheckTest.php) cover positive and
  negative placement and documentation scope.
- Published Artifact Verification tool: [CLI regression fixtures](../Regression/PublishedArtifactEvidenceTest.php)
  cover evidence preserved during failure and cleanup. The actual Stable
  distribution verification belongs after publication.

## F108-18 — Raw Ordering settlement after a zero-row update

**Open system-level test coverage gap under Testing §§1, 3.3, 4.2 and 4.5.**
[DEC-034](../../docs/decisions/DEC-034-raw-ordering-settlement-failures.md)
defines the public result of `ScopedOrderingManager::moveWithinScope()` when the
target write affects zero rows and settlement cannot be confirmed. The current
[raw Integration test](../Integration/Ordering/ScopedOrderingManagerLazySavepointTest.php#L489-L491)
explicitly assigns this S5 scenario to the
[scripted-PDO Unit coverage](../Unit/Ordering/Repository/ScopedOrderingManagerSavepointWiringTest.php#L108-L137).
Nearby raw Integration tests exercise an existing primary error or a failed
release after a successful target write. The [real-PDO savepoint helper test](../Integration/Transaction/TransactionOutcomeIntegrationTest.php#L120-L168)
invokes `SavepointScope` directly. The [protected manager case](../Integration/Ordering/ProtectedScopedOrderingManagerIntegrationTest.php#L158-L182)
uses the separate protected checkout API. Together these sources establish the
specific missing public-path observation on the reviewed tree.

The focused Unit checks remain useful for the driver fault permutations. Add
system-level proof through the public raw manager on a disposable real database:

1. Cause a scope shift followed by a zero-row target update without a primary
   exception. Verify that successful settlement permits the ordinary `false`
   result and that the stored ordering is restored.
2. Inject the first failure at the applicable settlement boundary. In the
   owned path, verify that a failed rollback cannot return the ordinary result.
   In the Host path, cover failed local undo and failed release after undo.
   Use an independent observer and a Host marker to distinguish pending work
   from committed state and prove that the enclosing transaction is still owned
   by the caller.
3. Verify the original driver exception or `TransactionExecutionException`
   according to DEC-034, and prove explicit fixture cleanup. Record the existing
   pre-fix evidence and the candidate execution. Keep fault injection limited
   to the settlement acknowledgement so the movement and state are observed
   against the real database.

The finding is about coverage. The current targeted and broader executed
evidence is recorded above for independent review.
