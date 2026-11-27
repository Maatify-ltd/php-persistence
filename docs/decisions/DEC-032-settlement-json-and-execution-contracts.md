# DEC-032 — Settlement JSON and execution contracts

- **Decision ID:** `DEC-032`
- **Status:** `ACTIVE`
- **Date:** `2026-11-27`
- **Decision Authority:** Project Owner. [Approval recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7227930099).
- **Scope / Concern:** Intent, live PDO handoff, and immutable outcome contracts for the first Stable candidate.
- **Supersedes:** [DEC-020](./DEC-020-optional-host-transaction-snapshot-pagination.md) and [DEC-022](./DEC-022-verified-apply-discard.md).
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [Package Reference §3.2](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#32-transaction-capability--maatifyphppersistencetransaction) for Transaction and [§3.3](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#33-pagination-capability--maatifyphppersistencepagination) for Pagination.

## Context

The approved designs for [B](https://github.com/Maatify-ltd/php-persistence/issues/35#issuecomment-7065436693) and [C2](https://github.com/Maatify-ltd/php-persistence/issues/73#issuecomment-7078289307) used DTO names for a live PDO handoff and a callback's intent. C2 described its DTOs as local PHP values without a JSON contract. The adopted [Package Building Standard §§8–9](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#8-command-rules) assigns execution intent to a command and requires JSON for data result DTOs. The affected types are absent from the exact published RC.2, `71dc61548e48fd053376db69149a00bc87069b70`. The earlier design and Owner approvals remain in DEC-020 and DEC-022.

## Decision

1. C2 callbacks return `Transaction\Command\VerifiedTransactionDecisionCommand`. The final readonly command keeps its private constructor, `isApply` and `payload` properties and the `apply(mixed $payload = null)` and `discard(mixed $payload = null)` factories. Callback payload identity and the distinction between `false` and `null` remain intact. The command has no JSON contract.
2. B presents the handoff as `Pagination\Repository\HostSnapshotRetirement`, a final readonly PHP value with the same `disposition` and live `pdo` properties. The Host receives the same PDO once, with the connection-free page settlement retained separately. Handoff has no JSON contract. It still depends on confirmed resource release and the classified reuse or reconciliation disposition.
3. `HostSnapshotPageSettlementDTO`, `SnapshotConnectionOutcomeDTO`, `ProtectedTransactionSettlementDTO`, and `VerifiedTransactionSettlementDTO` expose JSON projections of their existing named fields. Unit enum fields use case-name strings. Unset fields retain `null`, and C1's optional diagnostic retains its string or null value. These snapshots carry data only. Reading the PHP properties still returns their original types. JSON carries no PDO, command payload, or authority to settle, replay, or resume work.
4. The approval updates only the contracts in items 1–3. It carries forward the ownership, API operations, guards, state transitions, failure and settlement semantics, database verification and authority boundaries from DEC-020 and DEC-022. In B, keep the one-source, one-checkout reservation for registered commands and page attempts, RR generation proof, one-shot page authorization, settlement before reservation release, original error identity, quarantine, and Host-directed handoff or discard, including the possibility of automatic driver cleanup on discard. In C2, keep exclusive delegation with the approved genuine PDO eligibility, command catalog and scope controls, Owned and Host modes, acknowledged end and local undo, fencing, first-error precedence, immutable outcome snapshots, returning the original PDO once, and discarding without issuing transaction SQL or certifying server cleanup. Preserve the Transaction weak claims, shared savepoint helper, and capability dependency boundaries. The C1 and Protected outcome policies in [DEC-018](./DEC-018-optional-delegated-snapshot-pagination.md) and [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) remain active. Their JSON projections add a representation of the existing outcomes.

## Rationale

A callback uses the command to select an effect before the result exists. The live PDO handoff transfers an object under the Host's existing authority. The outcome DTOs contain the facts needed for JSON diagnostics independently of either operation.

## Consequences

Candidate users importing the draft DTO names for the command and handoff use the types in this record. Published RC.2 consumers have neither of those types. Public entry point and outcome tests must verify the unchanged behavior together with the new representations under the existing MySQL/MariaDB verification policy. This decision carries no merge, release, or consumer adoption authority.
