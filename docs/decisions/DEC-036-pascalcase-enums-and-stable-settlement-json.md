# DEC-036 — PascalCase enum cases and stable settlement JSON

- **Decision ID:** `DEC-036`
- **Status:** `ACTIVE`
- **Date:** `2026-11-28`
- **Decision Authority:** Project Owner. [Approval recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7231034094).
- **Scope / Concern:** Enum case naming, the published RC.2 sort-direction contract, and the first Stable candidate's C1, B, Protected and Verified settlement JSON. B handoff and Verified execution intent retain their approved contracts.
- **Supersedes:** [DEC-032](./DEC-032-settlement-json-and-execution-contracts.md).
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Reference §1.6](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#16-rc2-sort-direction-migration) for RC.2 migration and [§§3.2–3.6](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#32-transaction-capability--maatifyphppersistencetransaction) for the runtime contracts.

## Context

The adopted [PHP Coding Style Standard](../php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md) specifies PHP-FIG PER Coding Style 3.1. [PER-CS §9](https://www.php-fig.org/per/coding-style/#9-enumerations) requires PascalCase for enum case declarations and permits uppercase constant names. Published RC.2 at `71dc61548e48fd053376db69149a00bc87069b70` declares `ASC` and `DESC` as cases of `SortDirectionEnum`. The [conformance review](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7230975588) identified the impact on PHP case identity and the candidate's JSON projections, which DEC-032 derived from unit enum case names.

## Decision

1. Use PascalCase for PHP enum case declarations. `SortDirectionEnum` declares `Asc` and `Desc`, retains backing strings `ASC` and `DESC`, and exposes `ASC` and `DESC` class constants as aliases for existing case expressions. Preserve enum class and parameter types, SQL direction tokens, and pagination result JSON. Classify the changed enum names, case introspection and native serialized representation as `BREAKING` against `PUBLISHED_PRE_STABLE` RC.2 at the first Stable `1.0.0` boundary.
2. `HostSnapshotPageSettlementDTO`, `SnapshotConnectionOutcomeDTO`, `ProtectedTransactionSettlementDTO` and `VerifiedTransactionSettlementDTO` keep their existing JSON field names, stable uppercase string codes, field order, nulls and diagnostics. The codes are explicit mappings independent of PHP case names. DTO properties retain their respective unit enum types. Their JSON carries no PDO, command payload or authority to settle, replay or resume work.
3. C2 callbacks return the final readonly `Transaction\Command\VerifiedTransactionDecisionCommand` through its existing `apply()` and `discard()` factories. They preserve payload identity and distinguish false from null. B hands off the exact PDO once through the final readonly `Pagination\Repository\HostSnapshotRetirement`, after resource release is confirmed and classified. The command and handoff remain PHP-only contracts.
4. Carry forward the ownership, guards, transaction boundaries, state transitions, failure and settlement semantics, and database verification from [DEC-032](./DEC-032-settlement-json-and-execution-contracts.md), including the inherited B and C2 boundaries. C1 and Protected outcome policies under [DEC-018](./DEC-018-optional-delegated-snapshot-pagination.md) and [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) stay active. No production behavior or release authorization changes beyond the representation decision.

## Rationale

An alias preserves the enum object and backing value for an old case expression. It does not create a second case. A native serialized enum is identified by its declared case name, so the compatibility constants cannot preserve that part of the published contract. Explicit settlement codes prevent a source-level rename from silently changing JSON. The candidate settlement DTOs are absent from RC.2.

## Consequences

Update code that consumes case names or Reflection, and decode stored RC.2 native payloads before upgrading or invalidate caches. Persist `value` and restore through `SortDirectionEnum::from()` when the stored contract is the direction. Verify the alias identities, backing behavior, PHP case inventory, native serialization and every JSON code on the exact candidate. [Issue #43](https://github.com/Maatify-ltd/php-persistence/issues/43) tracks the published-contract comparison and the final consumer evidence. This decision does not authorize merge, publication or consumer migration.
