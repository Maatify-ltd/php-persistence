# DEC-007 — Legacy source baseline, Gen2 runner reuse and deprecated retirement

- **Decision ID:** `DEC-007`
- **Title:** Legacy source baseline, Gen2 runner reuse and deprecated retirement
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — M-3 in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447); confirmed in [Issue #5, comment 6441236923](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6441236923).
- **Scope / Concern:** Which legacy state is the behavioral and source starting point, which legacy types are retired, how the runner names are reused, and what must be checked before any legacy code is copied.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** This record governs the legacy-source baseline, type disposition and provenance checks. The [Contract Matrix](../architecture/CONTRACT_MATRIX.md) and [Behavior Contract](../architecture/BEHAVIOR_CONTRACT.md) record the historical type and behavior comparisons. The [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) governs the current package contract.

## Context

Three legacy states exist and must not be conflated. Facts below were re-observed on 2026-10-27 from a clone of `Maatify/persistence` (`git ls-tree`, `git grep`).

| State | Reference | Observed |
|---|---|---|
| Published | tag `v1.4.0` → commit `fc1459b49fe5af5ead17814eeb3ada7a3fa81791`, tree `b477b06d23fc2101581513ca97c0948259079b67` | 21 files under `src/`, `php: >=8.2` |
| Old unreleased `main` | `1f6a7aafe601090b7e55f60d83794364afdefe67`, tree `c1e25e7653a3173c3339a269b1ad23b26f335b9d` | 26 files under `src/`; 10 `src/` files differ from `v1.4.0` (5 added, 5 modified) |
| New package | this repository | no source yet |

In the old `main` exactly three files under `src/` carry `@deprecated`: `Pdo/Transaction/PdoTransactionRunner.php`, `Pdo/Transaction/PdoSavepointTransactionRunner.php`, `Exception/OrderingTransactionException.php`. The unreleased `main` contains behavior changes that were never published (for example the Ordering clamp, the lazy savepoint, `getNextPositionForUpdate`, deadlock retry).

## Decision

1. **Baseline.** The starting point for behavior and code is the latest viable state of legacy `main`, not `v1.4.0` alone. The exact commit must be re-verified at the start of every implementation work unit; a changed head is reconciled, never assumed.
2. **Retired, not carried.** The Gen1 implementations behind `PdoTransactionRunner` and `PdoSavepointTransactionRunner`, and `OrderingTransactionException`, are not carried into the new package.
3. **Names reused with Gen2 behavior.** The new package exposes `PdoTransactionRunner` and `PdoSavepointTransactionRunner`. Their behavior derives from the legacy non-deprecated `PdoDeadlockRetryTransactionRunner`, `PdoDeadlockRetrySavepointTransactionRunner` and the shared `OwnedTransactionExecutor`. Constructor shape: `(PDO $pdo, int $maxDeadlockRetries = 0)`, with the retry limit in `[0, 3]`. The names `PdoDeadlockRetry*` are not carried.
4. **`OrderingTransactionException` is omitted.** It is not thrown by legacy code and has no value in a new package.
5. **No claim of equivalence without evidence.** The new package is not a drop-in `1.x` release of the legacy package. A behavior is called equivalent only where a named test in this repository demonstrates it (see the evidence rule in [BEHAVIOR_CONTRACT.md](../architecture/BEHAVIOR_CONTRACT.md)).
6. **Provenance gate before copying.** No legacy source or test is copied until a recorded check covers: the rights holder of the copied material, the MIT notice present in the legacy `LICENSE` (copyright line "Copyright (c) 2026 Maatify"), whether any third-party notice is inherited, and the source commit SHA recorded in the copying pull request. [DEC-002](./DEC-002-proprietary-licensing.md) already makes this a precondition; this record applies it to every porting work unit. The check is not performed in WU-3 because WU-3 copies nothing.
7. **Internal helpers are re-evaluated.** `@internal` legacy types (`OwnedTransactionExecutor`, `OperationSavepoint`) are not deprecated; they are redesigned under the new topology, not copied blindly ([DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md)).

## Rationale

The unreleased fixes and the deadlock-retry design are the most current behavior, but they have not been published. Using them as the baseline while requiring tests in the new repository captures the value without presenting unproven equivalence.

## Consequences

- The behavior differences between `v1.4.0`, old `main` and the new package are documented per behavior in [BEHAVIOR_CONTRACT.md](../architecture/BEHAVIOR_CONTRACT.md), including the two published-behavior changes that originate only in old `main` (B-03 and B-04).
- Legacy documents under `docs/studies/`, `docs/adr/` and `harness/` are cited by permanent link, not copied as new decisions.
- The legacy release state (issue #37, "RELEASE NO-GO") is outside this repository and not affected.
