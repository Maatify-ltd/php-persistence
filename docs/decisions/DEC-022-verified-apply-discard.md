# DEC-022 — Optional verified apply and discard

- **Decision ID:** DEC-022
- **Status:** `SUPERSEDED`
- **Date:** 2026-11-22
- **Decision Authority:** Project Owner, [contract approval](https://github.com/Maatify-ltd/php-persistence/issues/73#issuecomment-7078581978), following [focused technical acceptance](https://github.com/Maatify-ltd/php-persistence/issues/73#issuecomment-7078479638). [WU-B3](https://github.com/Maatify-ltd/php-persistence/issues/74) authorizes implementation.
- **Scope:** Optional Verified Apply/Discard within Transaction.
- **Canonical Contract / Current Owner:** Historical record. [DEC-032](./DEC-032-settlement-json-and-execution-contracts.md) governs the current contract.
- **Superseded By:** [DEC-032](./DEC-032-settlement-json-and-execution-contracts.md)

## Context

Ordinary return values from the current transaction runners do not express rollback intent. Best-effort cleanup also cannot establish a confirmed rollback. The [two-engine proof](https://github.com/Maatify-ltd/php-persistence/issues/71#issuecomment-7078023701) distinguished server effects, acknowledgement and safe handoff for an exclusively delegated connection. A raw alias can replace a transaction on the same server session without changing the session ID or the driver's active flag.

## Decision

Add the six public types and ten ordinary checkout operations of the approved C2 contract. The Host exclusively delegates a genuine, idle, non-persistent pdo_mysql connection with native prepares, exception mode, the default statement class and autocommit enabled. The Host supplies trusted, transaction-preserving InnoDB command plans and prevents aliases, reconnect, multiplexing and concurrent enrollment across capabilities. The package leaves the Host's settings intact.

In Owned mode the checkout starts and ends its single transaction. In Host mode the Host explicitly starts and ends its transaction, and one verified operation may execute inside a savepoint. A decision separates Apply or Discard from the payload. A normal return requires acknowledged control and confirmed continuity. Host Discard requires both rollback to and release of the savepoint. A confirmed local undo can survive as evidence when later cleanup fails.

Use one immutable snapshot for operation outcome, transaction outcome and PDO disposition. Lose authority to issue SQL or return PDO when a safety failure is latched. Preserve knowledge of an acknowledged end even when the idle connection check fails. An unknown end does not authorize replay or handoff. As resolved in F73-01, an exception escaping the callback retains its identity. The recorded driver or cleanup failure governs settlement and fencing. A normal decision after a driver failure is suppressed and that failure is thrown.

Share weak claims with the existing Protected checkout inside Transaction, retain a single internal savepoint helper, and preserve the contracts of both existing runners, Protected Ordering and Pagination. Handoff returns the same PDO once. Retirement releases library references without issuing transaction SQL or certifying server cleanup.

## Rationale

A raw runner method cannot enforce the ownership prerequisite. A Host-only window covers local undo but leaves safe Owned completion with the caller. C2 covers both under the same delegation, without adding a mode enum or changing Protected's eligibility or settlement. The proof harness and its private bindings are experimental evidence. Production acceptance requires tests at the public entry points.

## Consequences

The Host must arrange exclusive custody and decide reconciliation. The library cannot attest an externally replaced generation, undo out-of-transaction effects, or infer the server outcome of a lost reply. Existing consumers may keep their own domain coordination. No existing decision is superseded. Engine support and installed-artifact claims require the tests specified in WU-B3 and DEC-008/013. This decision does not authorize merge, release or consumer adoption.
