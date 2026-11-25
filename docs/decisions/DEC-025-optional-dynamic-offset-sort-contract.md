# DEC-025 — Approved design for optional dynamic Offset sorting

- **Decision ID:** DEC-025
- **Status:** ACTIVE
- **Date:** 2026-11-23
- **Decision Authority:** Project Owner, [contract approval](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108615742) following [independent design acceptance](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108166825).
- **Scope / Concern:** Optional dynamic Offset sorting. Design approval originally placed implementation on HOLD. The Owner subsequently authorized the bounded implementation in [WU-D4](https://github.com/Maatify-ltd/php-persistence/issues/84).
- **Canonical Contract / Current Owner:** [Package Reference §5.3.5](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#535-optional-dynamic-offset-sorting) for the implementation, based on the Owner's [approved design](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7106286763) and [F81-01 through F81-03](https://github.com/Maatify-ltd/php-persistence/issues/81#issuecomment-7108071764).
- **Supersedes:** —
- **Superseded By:** —

## Context

Server-side grid requests can carry an ordered selection of public sort keys with independent directions. The fixed tuple in [DEC-024](./DEC-024-optional-static-composite-offset-pagination.md) addresses a separate Host-defined use case.

## Decision

Approve the corrected design for a separate planned `PdoPaginator::paginateWithDynamicSort()` entry point and `MultiSortPageResultDTO`. The contract limits selection to three Host-approved public keys, validates resolved identifier paths and documented alias equivalences, and preserves a single effective tie-breaker. It distinguishes selected from effective sort terms and masks the label of an automatically added tie-breaker. It preserves the existing Pagination APIs and leaves SQL, connection, and transaction ownership with the Host.

## Rationale and consequences

The entry point and result envelope isolate request-selected order from the existing single-sort contract and from static profiles. At design approval, implementation and runtime availability were deferred pending static sorting acceptance and a separate Owner authorization. Dynamic sorting requires its own behavioral, database, and installed-artifact verification.

## Implementation authorization

After acceptance of static sorting, [WU-D4](https://github.com/Maatify-ltd/php-persistence/issues/84) authorized implementing the existing decision. The implementation was delivered in [PR #85](https://github.com/Maatify-ltd/php-persistence/pull/85) and is merged into the default branch (`a1a2721c52740f5c1b3900b78bd5d59d31134560`). It is not part of a released artifact: it requires separate qualification and publication, and published v1.0.0-rc.2 predates this API. Consumer adoption requires separate authorization. (Current-state note; the decision, its rationale and its `ACTIVE` status are unchanged.)
