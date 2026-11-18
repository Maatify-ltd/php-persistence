# DEC-017 — Enforce PHP integer bounds for Ordering

- **Decision ID:** `DEC-017`
- **Title:** Enforce PHP integer bounds for Ordering
- **Status:** `ACTIVE`
- **Date:** `2026-11-18`
- **Decision Authority:** The Project Owner approved remediation of the integer-range finding from the readiness review of [PR #33](https://github.com/Maatify-ltd/php-persistence/pull/33).
- **Scope / Concern:** Conversion of stored order values and next-position arithmetic in `ScopedOrderingManager`.
- **Canonical Contract / Current Owner:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager) and [§6.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#63-ordering).

## Context

PDO can return an integer column as a decimal string. An unchecked PHP cast can collapse a wider database integer into `PHP_INT_MAX`, allowing a move to report a no-op against a different stored position. Adding one to `PHP_INT_MAX` produces a float that cannot satisfy the public `int` return type.

## Decision

Validate decimal strings against the signed integer range of the running PHP process before converting them. Report malformed or out-of-range stored values and next-position overflow as `OrderingExecutionException`, consistent with the execution failure boundary in [DEC-015](./DEC-015-ordering-pdo-error-modes.md).

## Rationale and consequences

The existing public methods use PHP integers for ordering positions. Validation preserves exact representable positions and fails before the first write if a locking read encounters an unrepresentable value. The existing transaction owner handles rollback. Consumers can catch this failure through the package exception marker.
