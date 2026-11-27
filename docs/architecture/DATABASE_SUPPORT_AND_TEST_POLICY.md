# Database Support, Compatibility and Test Policy

Status: policy and requirements for WU-5 and later. Nothing here is a support claim. The CI of WU-5 runs infrastructure-level tests on the real engines ([CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md)); WU-6 additionally verifies the Transaction capability itself (B-10 to B-13, [BEHAVIOR_CONTRACT.md](./BEHAVIOR_CONTRACT.md)) on named cells, including genuine deadlocks; WU-7 additionally verifies the Pagination capability itself (B-08, B-08a, B-09) on named cells, including a deterministic concurrent-writer snapshot test; WU-8 additionally verifies the Ordering capability itself (B-01 to B-07) on named cells, including real stale-snapshot concurrency (a second real connection, `tests/Integration/Support/OrderingNextPositionWaiter.php`) and a genuine deadlock while acquiring the ordering scope lock.

Ratified policy: [DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md). Test-only version boundary: [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) (`ACTIVE`). MariaDB `1020` contract: [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) (`ACTIVE` for the optional protected checkout).

## 1. Support states

| State | Meaning | May appear in README, Package Reference, Composer metadata |
|---|---|---|
| Design target | The design aims at it; nothing is proven. | Yes, worded as a target. |
| Range verified | Real Integration tests pass at both ends (oldest and newest declared version) of the declared range for that engine, on the PHP matrix, as required by [DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md). | Yes, as the declared range of that engine, worded as "supported range verified at its endpoints" and never as "every version in the range was tested". |
| Version tested | A specific version has its own passing real Integration cells. | Yes, by name, for exactly the versions tested. |
| Unverified | A version outside the declared range, an engine whose endpoints are not both verified, or a behavior difference that is known or suspected but not covered by a test. | No. |

Range support and per-version testing are different claims. The endpoint rule is what supports a range; a version between two verified endpoints is covered by the range claim and is not called individually tested unless it has its own cells. Intermediate cells are added by risk (section 4), not as a precondition for the range.
Evidence snapshot from 2026-10-29. MySQL and MariaDB are design targets; no supported range is declared for either. The Integration infrastructure of WU-5 ([CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md)) runs infrastructure-level tests on candidate cells, which is test evidence for those cells and not a verification of any package capability. WU-6, WU-7 and WU-8 are exceptions: the Transaction capability's tests, including genuine deadlocks; the Pagination capability's tests, including a deterministic concurrent-writer snapshot test; and the Ordering capability's tests, including real stale-snapshot concurrency, a genuine deadlock while acquiring the scope lock, and the MariaDB `innodb_snapshot_isolation` ON/OFF evidence of §7, pass on named MySQL and MariaDB cells of the required matrix (Ordering directly re-run by this work unit on `mysql-8.4.13` — 220 tests, two full suite passes, zero residue — and `mariadb-11.8.9` with `innodb_snapshot_isolation=ON` by default — 220 tests, two full suite passes, zero residue; the full required matrix runs as the CI gate on the pull request) — "version tested" evidence for those named versions, not "range verified".

## 2. Engines and versions

| Engine | Ratified | Proposed or open |
|---|---|---|
| MySQL | Minimum `8.0.16` (DEC-008); test matrix `8.0.16`, `8.4.13`, `9.7.4` (DEC-013); Innovation `26.10.0` exploratory only | Nothing about the version matrix; a support range still needs the endpoint rule and tested capabilities |
| MariaDB | Target engine (independent from MySQL); test matrix `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3` (DEC-013) | The protected checkout in DEC-009 does not qualify a general support range |

Version numbers come from the vendors' announcements; the exact tags and digests are pinned in `docker/integration-matrix.json` (WU-5).

## 3. PHP and dependency axes

- [`composer.json`](../../composer.json) defines Composer's PHP range and its minimum patch levels. The [CI test matrix](CI_AND_INTEGRATION.md#3-test-matrix) records the jobs on PHP `8.4`, `8.5`, and `8.6`.
- Compat runs latest-compatible and lowest-compatible dependency resolutions. Unit and Regression run without a database.
- Integration and the consumer harness select their own cells from the shared real-database matrix. See [CI and Integration](CI_AND_INTEGRATION.md) for the coverage of each job.

## 4. Required matrix shape (for WU-5)

For each declared engine, with PHP oldest = `P_min` and PHP latest = `P_max`:

| | Engine oldest | Engine newest |
|---|---|---|
| `P_min` | required | required |
| `P_max` | required | required |

Intermediate versions are not required for the range claim. Add a cell for an intermediate version when a behavior difference between versions is known or suspected, when a long-term-support line is declared as a named target, or when a defect is reported on it; the cell then moves that version to "version tested". If a known difference is found in an untested intermediate version, the range claim is narrowed or the cell is added before the claim is published. Add the cells for the MariaDB setting `innodb_snapshot_isolation` (both states) wherever MariaDB versions that support it are used.

## 5. Mandatory properties of the test environment

1. One parameterized Docker Compose definition that takes the engine and version as parameters.
2. Pinned image versions or digests. Never `latest`.
3. Real database services only. No SQLite, no mocked storage for storage behavior.
4. Health check before tests, not a fixed sleep.
5. Fresh schema per run and isolated lifecycle; teardown on success and on failure.
6. Dynamic loopback port, no fixed container name, run-unique project identity.
7. The suite runs twice from a clean state to prove repeatability and absence of residue.
8. No silent skips. A skipped test fails the gate unless the skip is declared and justified in the same change.
9. Failure-path tests are mandatory, not optional.

## 6. Behaviors that need engine-specific tests

| Area | Why engine-specific |
|---|---|
| Caller-owned transactions | Ownership boundaries and what the engine does to the transaction after an error differ. |
| Savepoint and rollback behavior | Statement support and error behavior may differ by engine and version. |
| Transaction state after engine failure | `PDO::inTransaction()` follows the last server reply; after an error the server may already have rolled back (see section 7). |
| Deadlock retry | Depends on SQLSTATE `40001`, driver code `1213`, and the `DO 0` probe; must be proven per engine. |
| Locking reads under stale snapshots | Behavior differs on MariaDB depending on `innodb_snapshot_isolation`. |
| Identifier quoting and `FOR UPDATE` | Dialect assumptions. |

## 7. MariaDB hazard to cover

Source: [legacy Issue #47 study, REPORT.md at `1f6a7aa`](https://github.com/Maatify/persistence/blob/1f6a7aafe601090b7e55f60d83794364afdefe67/docs/studies/issue-47-mariadb-snapshot/REPORT.md). Findings are the legacy repository's measurements, not this package's.

- With `innodb_snapshot_isolation=ON` (the default on MariaDB 11.8.9 in that study), a locking read or an update after an older `REPEATABLE READ` snapshot can fail with error `1020` when it touches a row changed after the snapshot.
- The server can roll back the caller's whole transaction while `PDO::inTransaction()` still reports it as active. What follows depends on the version, so a caller that swallows the error loses atomicity.
- The package cannot restore the guarantee from inside without changing the host configuration or silently overriding it.

Required tests (future): positive and negative cases with the setting `ON` and `OFF` at every MariaDB endpoint; check what the next statement does after `1020`; assert the error propagates unchanged; assert the package issues no `SET` of the setting or the isolation level. Forbidden responses: changing connection or server settings, swallowing the error, retrying the caller's work.

## 8. What WU-3 does not decide

- MySQL newest endpoint and upper-bound policy; Innovation track.
- MariaDB oldest and newest versions.
- Whether a safe MariaDB contract exists for caller-owned transactions.
- `ext-pdo_mysql` in `require` (decided later in [DEC-012](../decisions/DEC-012-mandatory-pdo-mysql-driver.md), WU-4).
- Exact image tags or digests, project and environment variable names.
