# DEC-020 — Optional pagination in a Host-owned transaction

- **Decision ID:** DEC-020
- **Status:** `SUPERSEDED`
- **Decision Authority:** Project Owner. [Design and Public API approval](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7066152213) and [execution authorization](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7066221226).
- **Date:** 2026-11-21
- **Scope / Concern:** Optional professional pagination path on the Host's own transaction.
- **Canonical Contract / Current Owner:** Historical record. [DEC-036](./DEC-036-pascalcase-enums-and-stable-settlement-json.md) governs the current contract.
- **Supersedes:** —
- **Superseded By:** [DEC-032](./DEC-032-settlement-json-and-execution-contracts.md)

## Context

Offset pagination on the caller's connection leaves transaction control to the caller. C1 serves a single page on a delegated read checkout and owns that page's read-only transaction. A Host that already needs its own transaction may need its earlier writes and one consistent view while reading several pages. The [isolated evidence](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7058092920) and [independent review](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7065310899) establish the requirements for the transaction proof and shared reservation.

## Decision

Add an optional B surface to Pagination. A single source controls one Host-supplied, exclusively delegated genuine PDO connection and a single active checkout. The Host chooses transaction commands and the final disposition of the connection. Its explicit transaction start qualifies the actual session and generation for REPEATABLE READ. Registered commands and one-shot page authorizations use the same reservation. The source invalidates the prior generation before an operation that may change its transaction.

Each invocation has a query authorization and a separate attempt. The attempt publishes a terminal, connection-free outcome before the reservation is released. It remains readable after an exception and later invocations. The original driver or mapper exception keeps its identity. B keeps driver resources out of ordinary exception arguments. An uncertain outcome prevents page delivery and further circulation. The Host may request a classified handoff after resource release is confirmed, or explicitly discard the source. Discard drops the library's references and may trigger automatic driver rollback or disconnect.

The source registry, checkout and attempt state are specific to B. This is a local exception to the baseline statelessness guidance and respects [DEC-010](./DEC-010-package-topology-multi-capability.md) and [DEC-011](./DEC-011-responsibility-placement-and-savepoint-helper.md). Existing offset pagination and [C1](./DEC-018-optional-delegated-snapshot-pagination.md) retain their contracts.

## Consequences

The Host must register trusted commands and query plans, keep the source for the lifetime of its exclusive delegation, and route all connection use through it. An observed alias or automatic reconnect invalidates those prerequisites. Multi-engine evidence follows [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md) and [DEC-013](./DEC-013-test-only-database-version-boundary.md). This decision carries no package release authority.
