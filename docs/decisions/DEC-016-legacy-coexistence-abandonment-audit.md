# DEC-016 — Limit the legacy abandonment ignore to the coexistence audit

- **Decision ID:** `DEC-016`
- **Title:** Limit the legacy abandonment ignore to the coexistence audit
- **Status:** `ACTIVE`
- **Date:** `2026-11-18`
- **Decision Authority:** The Project Owner approved this exact scope in the November 18, 2026 collaboration on [PR #33](https://github.com/Maatify-ltd/php-persistence/pull/33).
- **Scope / Concern:** Composer abandonment auditing of the exact published legacy migration fixture in the temporary coexistence root.
- **Canonical Contract / Current Owner:** [Consumer verification harness in the repository](https://github.com/Maatify-ltd/php-persistence/blob/main/scripts/consumer-verification.php) and its [audit guide](../guides/CONSUMER_VERIFICATION.md#coexistence-audit) implement this decision under the pinned [Composer Package Standard §23.5.4](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md#2354-abandoned-dependencies) and [CI Workflow Standard §9](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#9-composer-security-audit).

## Context

[Packagist](https://packagist.org/packages/maatify/persistence) marks the published legacy package as abandoned. The required coexistence check installs `maatify/persistence:1.4.0` deliberately to verify the gradual migration described in [DEC-005](./DEC-005-coexistence-with-legacy-package.md) and [DEC-006](./DEC-006-successor-intent-and-standards-ownership-boundary.md). During [PR #33 CI](https://github.com/Maatify-ltd/php-persistence/actions/runs/44225172654/job/134536473126), the coexistence audit failed on that fixture's abandonment flag.

## Decision

Allow an abandonment ignore during the audit in the ephemeral coexistence Composer root, for `maatify/persistence:1.4.0` with dist reference `fc1459b49fe5af5ead17814eeb3ada7a3fa81791`. Enforce that identity in the production lock before enabling the exact package-name rule because Composer matches the abandonment ignore by name. Keep `policy.abandoned.audit=fail`, pass `--abandoned=fail`, and disable use of this ignore during dependency blocking. Keep advisory, malware, and configured custom-policy checks in effect.

## Rationale

The published legacy fixture is essential to proving that consumers can install both packages while migrating. Scoping the ignore to this precise test fixture preserves that evidence and keeps abandonment failures for every other dependency enforced.

## Consequences

The exception applies only to the temporary coexistence audit after installation and its platform and lock checks. It grants no exception to the distributed package, differential harness root, published-artifact checks, other audits, or consumer applications. Any change to the approved legacy identity requires a new decision.
