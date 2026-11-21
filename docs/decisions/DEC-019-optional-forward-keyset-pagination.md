# DEC-019 — Optional forward Keyset pagination

- **Decision ID:** `DEC-019`
- **Title:** Optional forward Keyset pagination
- **Status:** `ACTIVE`
- **Date:** `2026-11-21`
- **Decision Authority:** Project Owner approved the [Keyset design and Public API](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7056909760) after the [final API review](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7056892289).
- **Scope / Concern:** One bounded, forward-only Keyset page on Host-owned PDO.
- **Canonical Contract / Current Owner:** [Package Reference §3.3 and §5.3.2](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#532-optional-forward-only-keyset-pagination).
- **Supersedes:** —
- **Superseded By:** —

## Context

Offset pagination supports totals and page numbers. A consumer that navigates forward by a stable unique integer key can instead read one bounded page and continue from the last displayed key. The [isolated proof](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7056564260) exercised the query shape, signed context, and PDO behaviors on MySQL and MariaDB.

## Decision

Offer the approved `PdoKeysetPaginator` with a trusted `KeysetQueryDescriptor`, a dedicated `KeysetPageResultDTO` and `InvalidKeysetCursorException`, inside Pagination. Query execution and binding belong to its Repository under DEC-010/011. The scope is one InnoDB table and forward order by an immutable, unique positive `id` representable as a PHP integer. A v1 cursor authenticates the raw position and binds it to the registered query, Host scope, effective limit and typed bindings. A Host-managed key ID enables rotation. The [approved design](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7056253955) records the signing protocol.

The Host supplies the connection, transaction and authorization scope. The library performs one read in the view provided by that connection. The existing caller-controlled and optional C1 APIs retain their contracts.

## Consequences

The consumer chooses between Offset navigation, one C1 snapshot page, and forward Keyset reads. The Host maintains eligible registration, connection and keyring configuration. Engine evidence remains bounded by DEC-008/013 and the actual integration matrix. The professional B path and release decisions have independent gates.
