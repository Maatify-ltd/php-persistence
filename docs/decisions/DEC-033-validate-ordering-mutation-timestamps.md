# DEC-033 — Validate Ordering mutation timestamps

- **Decision ID:** `DEC-033`
- **Status:** `ACTIVE`
- **Date:** `2026-11-27`
- **Decision Authority:** Project Owner. [Approval recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7227930099).
- **Scope / Concern:** The `updatedAtValue` argument of raw and protected `moveWithinScope()`.
- **Supersedes:** —
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager).

## Context

[Published RC.2](https://github.com/Maatify-ltd/php-persistence/blob/71dc61548e48fd053376db69149a00bc87069b70/PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager) documented the optional timestamp and required it exactly when the timestamp column was configured. The raw manager passed a supplied string to the driver without calendar or format validation. Driver and column behavior then determined which values succeeded. The [Package Building Standard §8](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#8-command-rules) places date-time validation at the input boundary.

## Decision

1. When `updatedAtColumn` is configured, require a real Gregorian date and a 24-hour clock time in the exact ASCII format `YYYY-MM-DD HH:MM:SS`, followed optionally by a decimal point and 1–6 fractional-second digits. Validate calendar and clock fields. Reject a malformed or missing value with `InvalidOrderingOperationException` before the move issues SQL. When the column is absent, the only accepted value remains null.
2. Bind the original accepted string as the target row's timestamp. The Host owns timezone conversion, and the database column retains responsibility for supported range and precision. The signature, default, named parameter, target-only update, connection ownership and locking behavior stay as published. Protected UNIQUE-safe Ordering B1 still requires an absent timestamp column.
3. Classify the narrower acceptance boundary as `BREAKING` against published pre-Stable RC.2. A database could previously accept an alternate input or interpret an unvalidated string. Record the required consumer change in the first-Stable changelog and [compatibility gate #43](https://github.com/Maatify-ltd/php-persistence/issues/43). Record behavior evidence at [#44](https://github.com/Maatify-ltd/php-persistence/issues/44). RC.2 retains its historical behavior.
4. The Host supplies the required format before calling the manager. For example, format an existing date-time object with `Y-m-d H:i:s` or `Y-m-d H:i:s.u` after choosing the appropriate Host timezone and database precision. Keep valid existing strings and exact RC.2 pins until the consumer can migrate through its own release gate.

## Rationale

Validating before SQL keeps malformed inputs from affecting the move or depending on the server's coercion settings. Keeping the accepted bytes preserves fractional digits and leaves timezone and database choices with the Host.

## Consequences

Verify rejection before PDO work, valid leap dates and fractional bounds, byte-for-byte binding, and the protected checkout's availability after validation failure. This scope preserves the raw cleanup and MariaDB `1020` protections described in [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md). Changing the ordering cleanup contract requires a separate decision. This decision carries no merge, release, or consumer adoption authority.
