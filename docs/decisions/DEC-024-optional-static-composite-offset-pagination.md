# DEC-024 — Optional static composite Offset pagination

- **Decision ID:** DEC-024
- **Status:** ACTIVE
- **Date:** 2026-11-23
- **Decision Authority:** Project Owner, [contract approval](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108615742) following [independent design acceptance](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108166825).
- **Scope / Concern:** Host-configured static composite sorting for optional Offset pagination, authorized for implementation in [WU-D3](https://github.com/Maatify-ltd/php-persistence/issues/82).
- **Canonical Contract / Current Owner:** [Package Reference §5.3.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#534-optional-static-composite-offset-pagination).
- **Supersedes:** —
- **Superseded By:** —

## Context

Offset pagination accepts one user-selected primary sort and a configured tie-breaker. Hosts that need a larger fixed tuple have used a window projection or repeated the primary-key resolution to choose a secondary key. [WU-D2](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7106286763) and its [accepted correction](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108071764) establish the value and constraints of a reusable plan.

## Decision

Offer an immutable Host-prepared `StaticSortPlan` and an explicit `PdoPaginator::paginateWithStaticSort()` entry point. The plan declares its permitted public keys and validates every profile at construction. The Host assigns either a complete ordered tuple or an explicit legacy primary/tie-breaker profile. A key registered in the underlying whitelist but omitted from the plan fails before query preparation. Reuse the existing request normalization, counts, bounds, mapping, and result envelope.

Keep the Host responsible for the SQL, connection, transaction, and a total order over the actual result rows. The plan validates trusted identifier syntax and rejects duplicate paths within a composite profile. The Host establishes equivalence between differently qualified names or aliases and accounts for joins, NULLs, and collation.

## Rationale and consequences

The separate entry point keeps callers of the existing `paginate()`, C1, B, and Keyset v1 on their existing contracts. Static profiles avoid asking callers to supply independent terms and directions. Real database parity verifies the observed behavior on qualified fixtures. A faster execution plan and consumer adoption each require separate evidence and authorization. The dynamic contract is recorded separately in [DEC-025](./DEC-025-optional-dynamic-offset-sort-contract.md).
