# DEC-034 — Surface settlement failures in raw Ordering

- **Decision ID:** `DEC-034`
- **Status:** `ACTIVE`
- **Date:** `2026-11-27`
- **Decision Authority:** Project Owner. [Approval recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7228781651).
- **Scope / Concern:** Settlement of a missing target or zero-row target update in raw `ScopedOrderingManager::moveWithinScope()`.
- **Supersedes:** —
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager).

## Context

[Published RC.2](https://github.com/Maatify-ltd/php-persistence/blob/71dc61548e48fd053376db69149a00bc87069b70/PHP_PERSISTENCE_PACKAGE_REFERENCE.md#movewithinscope) documented best-effort rollback for an owned Ordering move and best-effort savepoint release after a successful local undo. The [F108-05 correctness evidence](https://github.com/Maatify-ltd/php-persistence/issues/44#issuecomment-7228136280) found that a failed owned rollback can leave the range shift pending while the call returns ordinary `false`. A failed release can also leave the savepoint live after a successful local undo.

## Decision

1. A missing row or zero-row update may return `false` once the required cleanup is confirmed. For an owned move, PDO must report an active transaction before rollback, acknowledge the rollback, and report no active transaction afterward. In a Host transaction, a missing row needs no savepoint. A zero-row update requires acknowledged `ROLLBACK TO SAVEPOINT` and then acknowledged `RELEASE SAVEPOINT`.
2. When settlement is the first failure, propagate the original driver throwable. Use the existing `TransactionExecutionException` for a failed control return or an unconfirmed owned rollback. When an earlier operation failed by throwing, preserve that original throwable across best-effort cleanup. An Ordering statement failure reported by return value still raises `OrderingExecutionException` under its existing contract.
3. Preserve caller ownership of the enclosing transaction, the public method signature, and the existing statement error mode behavior. The generic Transaction runners retain their callback result contracts. [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) governs protected checkout fencing and the MariaDB `1020` behavior, [DEC-015](./DEC-015-ordering-pdo-error-modes.md) governs statement failures, and [DEC-033](./DEC-033-validate-ordering-mutation-timestamps.md) governs timestamp validation.

## Rationale

Returning ordinary `false` after a failed owned rollback lets a consumer continue with a pending range shift and can allow partial writes to be committed. An opt-in replacement would leave that failure indistinguishable on the existing call. The approved change is `BREAKING` against published pre-Stable RC.2 and confines the change to unconfirmed settlement of an ordinary outcome, using the data-integrity path in the [Public Contract Evolution Standard §10](../php-engineering-standards/standards/contracts/PUBLIC_CONTRACT_EVOLUTION_STANDARD_AR.md#10-مسار-الاستثناء-خطر-أمني-أو-فساد-بيانات).

## Consequences

Consumers on RC.2 that handle unsuccessful moves solely through the boolean return should catch settlement exceptions and resolve transaction and connection state before retry or reuse. A Host that owns the transaction still decides how to finish it. The first-Stable changelog records the compatibility delta. [Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65) tracks the exact audit and implementation. [Issue #43](https://github.com/Maatify-ltd/php-persistence/issues/43) and [Issue #44](https://github.com/Maatify-ltd/php-persistence/issues/44) govern consumer compatibility and correctness at release qualification. Verify driver false and throwable outcomes, state confirmation, normal returns, local control order, and original error identity. This decision carries no merge, release, or consumer adoption authority.
