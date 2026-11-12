# DEC-009 — MariaDB snapshot-isolation (`1020`) contract (version matrix moved to DEC-013)

- **Decision ID:** `DEC-009`
- **Title:** MariaDB snapshot-isolation (`1020`) contract (version matrix moved to DEC-013)
- **Status:** `PROPOSED`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — not yet ratified. The version-matrix rows of this record were ratified on 2026-10-27 and moved to [DEC-013](./DEC-013-test-only-database-version-boundary.md) (partial ratification). The `1020` contract below stays `PROPOSED`/`DEFERRED` by the Owner's decision. This record is not implementation authority.
- **Scope / Concern:** The contract for MariaDB error `1020` with `innodb_snapshot_isolation` in caller-owned transactions.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** [DATABASE_SUPPORT_AND_TEST_POLICY.md](../architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md) describes the hazard and the required tests; the matrix is owned by [DEC-013](./DEC-013-test-only-database-version-boundary.md). Policy already ratified is in [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md).

## Context

Version lines were observed on 2026-10-24 and 2026-10-27 from the cited announcements and are not tested in this repository: [Oracle, October 2026 releases](https://blogs.oracle.com/mysql/mysql-october-2026-ga-releases-now-available) and [MariaDB Foundation, Q3 2026 maintenance releases](https://mariadb.org/mariadb-server-12-3-11-8-11-4-and-10-11-q3-2026-maintenance-releases-and-goodbye-10-6/). The legacy study of Issue #47 ([REPORT.md at `1f6a7aa`](https://github.com/Maatify/persistence/blob/1f6a7aafe601090b7e55f60d83794364afdefe67/docs/studies/issue-47-mariadb-snapshot/REPORT.md)) measured the following on real engines with the legacy code: with `innodb_snapshot_isolation=ON`, a locking read or an update after an older `REPEATABLE READ` snapshot raises error `1020` when it touches a row changed after that snapshot; the server then rolls back the caller's whole transaction while `PDO::inTransaction()` still reports it as active; behavior after the error differs between 10.11.18, 11.4.7 and 11.8.9; the setting, not the version, decides. These measurements were made in the legacy repository, on the legacy code, and are evidence for the hazard, not proof for the new package.

## Proposal (not ratified)

The version-matrix rows that this record first proposed (endpoints, intermediate cells, the Innovation track and the upper-bound policy) were ratified and are recorded in [DEC-013](./DEC-013-test-only-database-version-boundary.md). They are removed from this record.


**Proposed MariaDB `1020` contract**

1. Positive and negative tests run with `innodb_snapshot_isolation` both `ON` and `OFF` at each MariaDB endpoint.
2. When the engine raises `1020`, the error reaches the caller unchanged. It is not swallowed, translated into success, or retried.
3. Documentation states that after `1020` the caller's transaction may already be rolled back by the server even though `PDO::inTransaction()` reports it as active.
4. The package does not change `innodb_snapshot_isolation`, the isolation level, or any connection attribute.
5. If an honest and safe contract cannot be demonstrated for caller-owned transactions, MariaDB support is not advertised unconditionally; the claim is narrowed or withheld.

## Deferred contract and WU-8 evidence

At proposal time, Ordering acceptance for MariaDB and broader MariaDB claims were listed as pending. WU-8 later completed with test-only evidence while the Owner kept the recovery contract deferred.

In the cases exercised by the [WU-8 Ordering integration test](https://github.com/Maatify-ltd/php-persistence/blob/2212fb0a74e8f44c82b2c508f3585e2b87ed5a8c/tests/Integration/Ordering/ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest.php), the server rolls back a prior caller-owned write after error `1020`. The test also attempts a subsequent statement on the same connection and observes whether it is rejected or commits independently. [DEC-013](./DEC-013-test-only-database-version-boundary.md) governs the tested matrix. A general recovery guarantee or supported range remains outside the accepted contract.

## Consequences

- Nothing in this record may be cited as a supported range or as a MariaDB support claim.
- `SUPERSEDED` applies only to a record that was `ACTIVE` ([Decision Governance Standard](../php-engineering-standards/standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) status model), so it is never the outcome for this `PROPOSED` record.
- When the Owner ratifies the `1020` contract, as written or amended, this record is edited to the ratified content and its status becomes `ACTIVE` (`PROPOSED` → `ACTIVE`), with the index updated in the same change. If it is postponed it becomes `DEFERRED`; if declined, `REJECTED`. It is never `SUPERSEDED`, because it was never `ACTIVE`.
- The partial ratification of 2026-10-27 moved the version rows to DEC-013; neither record uses `Supersedes` or `Superseded By`.
