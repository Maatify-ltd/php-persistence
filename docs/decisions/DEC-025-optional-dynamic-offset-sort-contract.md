# DEC-025 — Approved design for optional dynamic Offset sorting

- **Decision ID:** DEC-025
- **Status:** ACTIVE
- **Date:** 2026-11-23
- **Decision Authority:** Project Owner, [contract approval](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108615742) following [independent design acceptance](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108166825).
- **Scope / Concern:** Design approval for a planned dynamic Offset sorting API. Implementation remains on HOLD.
- **Canonical Contract / Current Owner:** Project Owner's [approved design](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7106286763) as corrected by [F81-01 through F81-03](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108071764). The Package Reference continues to describe available runtime APIs.
- **Supersedes:** —
- **Superseded By:** —

## Context

Server-side grid requests can carry an ordered selection of public sort keys with independent directions. The fixed tuple in [DEC-024](./DEC-024-optional-static-composite-offset-pagination.md) addresses a separate Host-defined use case.

## Decision

Approve the corrected design for a separate planned `PdoPaginator::paginateWithDynamicSort()` entry point and `MultiSortPageResultDTO`. The contract limits selection to three Host-approved public keys, validates resolved identifier paths and documented alias equivalences, and preserves a single effective tie-breaker. It distinguishes selected from effective sort terms and masks the label of an automatically added tie-breaker. It preserves the existing Pagination APIs and leaves SQL, connection, and transaction ownership with the Host.

## Rationale and consequences

The entry point and result envelope isolate request-selected order from the existing single-sort contract and from static profiles. The approved design is **not implemented or available**. This record authorizes no runtime API, tests, consumer adoption, or release for dynamic sorting. Implementation requires separate Owner authorization after static sorting acceptance and its own behavioral, database, and installed-artifact verification.
