# DEC-015 — Report Ordering statement failures across PDO error modes

- **Decision ID:** `DEC-015`
- **Title:** Report Ordering statement failures across PDO error modes
- **Status:** `ACTIVE`
- **Date:** `2026-11-18`
- **Decision Authority:** The Project Owner directed a robust solution with a simple consumer experience during the November 18, 2026 discussion of [Issue #32](https://github.com/Maatify-ltd/php-persistence/issues/32). The Technical Lead selected this error contract within that authorized scope.
- **Scope / Concern:** Statement failures in the public methods of `ScopedOrderingManager` on caller-supplied PDO connections.
- **Canonical Contract / Current Owner:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager) and [§6.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#63-ordering).

## Context

PDO can report failures by return value in non-throwing error modes. Ordering had paths where such a result could be mistaken for an empty scope, a missing row, or a successful move. The host owns the connection settings, including its error mode, under [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md).

## Decision

Ordering accepts the host's selected PDO error mode and leaves its settings in place. When PDO reports a statement failure by return value, Ordering raises the package-defined `OrderingExecutionException` under the marker and exception policy in [DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md). Exceptions thrown by PDO propagate unchanged. A successful read with no matching row retains its documented result.

## Rationale

Requiring `ERRMODE_EXCEPTION` would force hosts that use silent or warning mode to reconfigure or supply a separate connection. Detecting return-value failures inside Ordering gives those hosts an explicit failure while preserving their connection configuration and normal call sites.

## Consequences

The existing transaction owner handles a failed move. The package requests rollback for a package-owned transaction, and attempts savepoint rollback for a failed mutation inside a caller-owned transaction while that transaction remains active. Cursor or transaction cleanup cannot replace a primary statement failure. The decision leaves transaction ownership and database-engine recovery guarantees as documented in the Package Reference and leaves the MariaDB `1020` recovery decision in [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md).
