# DEC-009 — Protect delegated Host transactions from uncertain outcomes

- **Decision ID:** DEC-009
- **Status:** ACTIVE
- **Date:** 2026-11-21
- **Decision Authority:** Project Owner. [Design approval](https://github.com/Maatify-ltd/php-persistence/issues/16#issuecomment-7069652846) and [execution authorization](https://github.com/Maatify-ltd/php-persistence/issues/16#issuecomment-7069701815).
- **Scope / Concern:** Optional exclusive Host write checkout and protected Ordering, including the MariaDB `1020` failure.
- **Canonical Contract / Current Owner:** [Package Reference §3.2](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#32-transaction-capability--maatifyphppersistencetransaction), [§5.2.1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#521-optional-protected-host-write-transaction), and [§7.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#73-mariadb-innodb_snapshot_isolation-hazard).
- **Supersedes:** —
- **Superseded By:** —

## Context

With MariaDB `innodb_snapshot_isolation=ON`, a locking read that encounters a later change than an older REPEATABLE READ view can fail with `HY000/1020`. In [the pinned study](https://github.com/Maatify-ltd/php-persistence/issues/16#issuecomment-7068981491), the server rolled back an earlier Host write and the next statement could autocommit while PDO still indicated an active transaction. Explicitly probing transaction status exposed the discrepancy but left raw PDO usable. A wrapper around the raw Ordering API also exposed PDO in exception arguments. The Owner ratified only the test-version matrix in [DEC-013](./DEC-013-test-only-database-version-boundary.md) on 2026-10-27. The MariaDB recovery decision stayed open until the protected checkout design was approved on 2026-11-21.

## Decision

The Host may exclusively delegate an eligible PDO to one protected write checkout. The Host explicitly directs begin, commit, rollback, handoff, and discard. A reservation covers registered Host commands and protected Ordering. A terminal outcome is published once after completion checks and before reservation release. The driver receiver preserves the first exception and keeps PDO and statements out of ordinary trace arguments.

A broken or uncertain transaction is fenced before subsequent application SQL. Loss of use authority never proves that the server rolled back or makes replay safe. The settlement distinguishes an unknown commit from a confirmed commit whose connection is unsafe to return. Ordering and Transaction share savepoint control under [DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md) and keep the dependency direction in [DEC-010](./DEC-010-package-topology-multi-capability.md). Protected Ordering requires confirmed savepoint cleanup before a normal result can escape.

## Rationale

A transaction-status probe can identify the stale PDO state while the raw connection still accepts statements. Exclusive delegation gates those statements and gives the Host a durable local outcome after a failure. An optional checkout preserves the published raw contract for consumers that own and coordinate PDO directly.

## Consequences

Raw PDO entry points retain their existing behavior. Delegation excludes aliases, persistent connections, transparent reconnect, replay and multiplexing. The checkout does not acquire, reconfigure or replace connections. The Host may retrieve a connection with a safe disposition or explicitly discard the checkout. Driver cleanup and external diagnostic references can affect when the connection is physically destroyed. This decision makes no unconditional MariaDB support claim and grants no release authority.
