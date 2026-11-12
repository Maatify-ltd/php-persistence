# Consumer verification and coexistence

From a checkout of the committed candidate, run `composer test:consumer` with PHP 8.4 or newer (PDO MySQL enabled), Composer 2.10, Git, cURL, tar, and Docker Compose. Internet access is needed for Composer dependencies, the pinned legacy source archive, and the Integration database image. The candidate is archived from the committed Git HEAD and its commit and tree are printed. On pull-request CI, this is the commit that the workflow checked out.

The required CI job provisions two separate MySQL 8.4.13 services through the existing Integration lifecycle. Each service runs the consumer workload in two independently generated Composer roots outside the repository. Every root starts without a lock, vendor, or project state and installs both packages through Composer with `--no-dev`. It runs the repository's dependency policy, uses only the production autoloader, and verifies the installed package identities. The candidate is mirrored from the Git archive. The consumer project owns its schema and the input catalog fixtures. Each lifecycle verifies zero tables and open transactions, removes the consumer directories, and verifies Compose teardown.

| Scenario | Legacy identity | Evidence |
|---|---|---|
| Coexistence | Published `maatify/persistence:1.4.0` at `fc1459b49fe5af5ead17814eeb3ada7a3fa81791` | Dist reference checked in Composer lock. Both savepoint runners resolve to separate types. On one PDO connection, a nested candidate rollback preserves earlier caller and legacy writes. The caller can still roll back both packages' work. The candidate also executes the catalog workflow. |
| Differential | [Unpublished legacy source](https://github.com/Maatify/persistence/tree/1f6a7aafe601090b7e55f60d83794364afdefe67) | Exact source archive installed in its own consumer root alongside the candidate. The same fixture, assertions, and observable pagination results apply to both packages. |

## Behavior evidence map

The [behavior contract](../architecture/BEHAVIOR_CONTRACT.md) labels published legacy as A, unpublished legacy as B, and this package as C.

| Behavior | Evidence and limits |
|---|---|
| B-01, B-02, B-03, B-06, B-07 | Differential catalog exercises scoped/nullable allocation, a locking allocation followed by insert in the same transaction, a clamped move, no-op and missing moves, and soft-delete/row visibility. B-02 and the B-03 locking-snapshot change use B as the source baseline. Fine-grained lock footprint and stale-clamp behavior stay covered by the repository's Integration suite. |
| B-04 | Published and source coexistence scenarios prove nested savepoint isolation through transaction runners. The differential catalog also triggers a CHECK constraint failure after a range shift and proves local rollback preserves earlier caller work against B. The remaining Ordering-specific lazy rollback branches stay covered by `ScopedOrderingManagerLazySavepointTest` and `ScopedOrderingManagerSavepointWiringTest`; it is a documented delta from A. |
| B-05, B-10, B-13 | The catalog checks owned commit/rollback, move return values and callback exception identity against B. Fail-closed PDO control failure behavior is an approved delta from A and remains exercised by the named repository tests. Exception FQCNs and the package marker differ. |
| B-08, B-08a, B-09 | Differential catalog compares pagination normalization, filtering, ordering and the result envelope. A second real connection commits between the two count queries without timing sleeps. Autocommit can observe inconsistent counts and caller-owned InnoDB repeatable read gives a single snapshot. The PHP DTO name changes while the observable array and JSON payloads agree. |
| B-11, B-12 | Nested savepoints are checked against both legacy identities; rollback preserves exception identity and caller ownership. The source comparison uses the Gen2 runner names; default retry is disabled in the catalog. The real deadlock replay and fail-closed retry branches remain covered by `DeadlockRetryIntegrationTest` and `DeadlockRetryFailClosedTest`. Retry is a delta from A. |
| B-14, B-15, B-16 | Harness SQL and transactions execute on the named MySQL cell. Existing Integration and compatibility jobs remain required. Engine evidence, Composer constraints, and archive scope remain distinct. Exact release artifact verification belongs to WU-11. |

The orchestrator is checked by the package's PHPStan configuration. The independent consumer fixture is syntax-checked and validated through execution in the two production Composer roots, where its legacy imports resolve.

The harness is additional to Unit, Regression, Integration and Examples Smoke. This evidence concerns an unpublished candidate, with no production host migration or support-range claim. DEC-009 remains deferred, and DEC-013 records test versions.
