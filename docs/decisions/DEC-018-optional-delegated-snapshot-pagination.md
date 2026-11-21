# DEC-018 — Optional consistent pagination on a delegated read connection

- **Decision ID:** `DEC-018`
- **Title:** Optional consistent pagination on a delegated read connection
- **Status:** `ACTIVE`
- **Date:** `2026-11-21`
- **Decision Authority:** Project Owner approved the design and acceptance boundaries on [Issue #37](https://github.com/Maatify-ltd/php-persistence/issues/37#issuecomment-7054505363) and separately authorized [execution](https://github.com/Maatify-ltd/php-persistence/issues/37#issuecomment-7054594415).
- **Scope / Concern:** One opted-in snapshot page on a Host-delegated read connection. Transient state is scoped to one C1 operation.
- **Canonical Contract / Current Owner:** [Package Reference §3.3 and §5.3.1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#531-optional-c1-snapshot-pagination).
- **Supersedes:** —
- **Superseded By:** —

## Context

The existing paginator leaves transaction ownership and isolation with the Host. A concurrent commit can appear between count and data reads. [Product #25](https://github.com/Maatify-ltd/php-product/issues/25) demonstrated the discrepancy, and the [isolated proof](https://github.com/Maatify-ltd/php-persistence/issues/37#issuecomment-7049616561) confirmed the feasibility of a single snapshot under explicit eligibility conditions.

## Decision

Offer `PdoSnapshotPaginator` as a separate opt-in API inside `Pagination/Repository`. It shares the pagination execution and owns one read-only transaction only after accepting a qualified Host checkout. It reports `UNCHANGED`, `REUSABLE` or `DISCARD` independently of the page or exception. The Host retains the connection and decides its disposition. Cursor cleanup and acknowledged transaction completion on the same session are required for reuse. Observing the outcome after an exception requires retaining the same one-shot object.

The operation-local lifecycle and terminal outcome are an explicit exception to the general statement about state between calls in Package Reference §1.1. There is no shared state across operations. `PdoPaginator::paginate()` retains its existing contract. Pagination and Transaction remain independent under [DEC-010](./DEC-010-package-topology-multi-capability.md) and SQL-issuing code follows [DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md).

## Consequences

Consumers explicitly choose C1 for qualifying descriptor reads in a single InnoDB snapshot. Connection acquisition, pooling and configuration remain with the Host. The professional path on Host transactions and Keyset remain deferred. Engine claims require the verification boundary defined by [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md) and [DEC-013](./DEC-013-test-only-database-version-boundary.md).
