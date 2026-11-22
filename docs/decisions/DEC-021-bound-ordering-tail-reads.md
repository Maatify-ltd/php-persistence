# DEC-021 — Bound raw Ordering tail reads

- **Decision ID:** `DEC-021`
- **Status:** `ACTIVE`
- **Date:** `2026-11-21`
- **Decision Authority:** Project Owner approved the design and RC.2 behavior change on [Issue #57](https://github.com/Maatify-ltd/php-persistence/issues/57#issuecomment-7069653516) and authorized [execution](https://github.com/Maatify-ltd/php-persistence/issues/57#issuecomment-7072755885) after #16 passed post-merge CI.
- **Scope:** The raw PDO entry point `ScopedOrderingManager::getNextPositionForUpdate()`.
- **Canonical Contract:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#getnextpositionforupdate).

## Context

With independent appends under InnoDB READ COMMITTED, the locking tail can advance between consecutive reads. The [N3 proof](https://github.com/Maatify-ltd/php-persistence/issues/57#issuecomment-7068982292) produced a successful raw allocation after 65 reads. The old loop had no way to bound the number of reads under continued appends. The protected path of [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) already used the shared loop with a budget.

## Decision

Use that loop for raw allocation with a default of 64 reads and an optional fourth argument, `int $maxTailReads = 64`, accepting 2–512 inclusive. Reject invalid budgets before SQL. Count from the first read and accept two consecutive matching tail values on any allowed read, including the last one. If the values remain unstable, close the last cursor and raise the existing `OrderingExecutionException` with `reason=TAIL_READ_LIMIT_EXCEEDED` and the integer budget in `maxTailReads`. Driver, fetch, cursor cleanup and integer-boundary failures retain their precedence.

## Rationale

A default of two reads would fail after a single concurrent advance. An optional limit alone would leave the ordinary call unbounded. Returning the last value without confirmation would weaken the allocation rule. A bounded default gives ordinary callers a finite read count, and the optional argument supports callers that can accept a different count.

## Consequences

The old positional and named calls remain valid and Reflection exposes the new optional parameter. Calls that would have stabilized only after 64 reads can fail at the default, which is the approved change relative to RC.2. Statement lock waits are still governed by the engine and Host. The Host keeps transaction and lock ownership and decides whether to retry. SQL, scope rules, server settings, and the protected path retain their contracts.
