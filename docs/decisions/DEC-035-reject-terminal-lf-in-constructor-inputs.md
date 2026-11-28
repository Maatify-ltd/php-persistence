# DEC-035 — Reject trailing LF in published constructor inputs

- **Decision ID:** `DEC-035`
- **Status:** `ACTIVE`
- **Date:** `2026-11-28`
- **Decision Authority:** Project Owner. [Approval recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7230226330).
- **Scope / Concern:** The first Stable `1.0.0` boundary for `maatify/php-persistence`, covering the direct `PageResultDTO` sort key, `SortWhitelist` keys and identifier paths, `PdoPaginationQueryDescriptor` binding keys, and `ScopedOrderingConfig` table and column names.
- **Supersedes:** —
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Reference §1.5](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#15-rc2-constructor-input-migration) for migration and exception boundaries, §§3.3–3.4 for constructor contracts, and §5.3 for request normalization and COUNT results.

## Context

The [published RC.2 Package Reference](https://github.com/Maatify-ltd/php-persistence/blob/71dc61548e48fd053376db69149a00bc87069b70/PHP_PERSISTENCE_PACKAGE_REFERENCE.md) specified identifier patterns with the PHP regex end anchor `$`. It can match before a final LF. In the [review of the installed RC.2 distribution](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7230164181), direct `PageResultDTO` construction with `sortBy: "id\n"` succeeded and preserved that value in JSON. The review also reproduced the acceptance difference for a whitelist key, a binding key, and a table name. The full-string checks already present in the Stable candidate reject these names during construction.

## Decision

1. Retain full-string validation on the four constructors in scope. Classify the narrower acceptance of inputs with trailing LF as `BREAKING` against `PUBLISHED_PRE_STABLE` RC.2 for the first Stable `1.0.0` target. Constructor signatures and exception types stay the same. Calls with canonical names keep their behavior and result representations. The published RC.2 artifact retains its original behavior.
2. Consumers supply canonical sort keys and correct trusted SQL identifier constants at their source. Binding keys must match the bare names of the corresponding SQL placeholders. A direct DTO caller supplies the confirmed canonical sort key before construction. `PaginationConfig` defaults and tie-breakers must refer to the corrected whitelist keys.
3. Preserve the existing Offset `PageRequest` trimming behavior. Rejecting trailing LF in COUNT text remains a fix for enforcing the published requirement that COUNT strings consist of decimal digits. The change to constructor acceptance is recorded under `Changed` in [CHANGELOG](../../CHANGELOG.md#changed) and in the [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#15-rc2-constructor-input-migration).

## Rationale

An accepted name becomes a lookup key, SQL name, binding key, or serialized value. Validating every supplied byte keeps those uses consistent and lets the caller choose the intended name. Trimming a trusted identifier inside the library can change which identifier the host meant. Preserving an opt-in strict path alongside permissive default validation would leave the mismatch on those same constructors. The pre-Stable classification and the consumer migration follow [Public Contract Evolution §§4 and 9](../php-engineering-standards/standards/contracts/PUBLIC_CONTRACT_EVOLUTION_STANDARD_AR.md#4-تصنيف-التوافق-compatibility-classification).

## Consequences

The runtime already implements the rejection and the existing exception types. Review the exact candidate's behavior for canonical names, terminal LF inputs, the DTO JSON projection, and the independent request and COUNT behaviors. [Issue #43](https://github.com/Maatify-ltd/php-persistence/issues/43) tracks the published-contract comparison and the remaining release-candidate consumer evidence. The approved decision concerns this constructor delta. Merge, release, and consumer rollout retain their own authorization and verification gates.
