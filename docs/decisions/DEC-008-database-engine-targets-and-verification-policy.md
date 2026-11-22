# DEC-008 — Database engine targets and endpoint verification policy

- **Decision ID:** `DEC-008`
- **Title:** Database engine targets and endpoint verification policy
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — M-4 and the MySQL 8.0.16 minimum in [Issue #5, comment 6384957447](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6384957447) and [Issue #5 body](https://github.com/Maatify/php-persistence/issues/5); dual-engine design target in [Issue #5, comment 6441236923](https://github.com/Maatify/php-persistence/issues/5#issuecomment-6441236923).
- **Scope / Concern:** Which database engines the design targets, the ratified MySQL minimum, and the mandatory rule for proving any declared support.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** This record for the project-specific policy. The pinned [CI Workflow Standard](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md) owns the generic rules (real services, one parameterized Compose definition, pinned images) and does not itself require oldest/newest database versions. The operational detail is [DATABASE_SUPPORT_AND_TEST_POLICY.md](../architecture/DATABASE_SUPPORT_AND_TEST_POLICY.md).

## Context

The study first proposed claiming MySQL 8.4 only and leaving MariaDB unsupported ([Issue #1, study 4/6 §4.3.5](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6370379269)). The Owner replaced that with a dual-engine target and a mandatory endpoint-verification rule. The Owner has not ratified any exact MariaDB bounds, MySQL upper bound or Innovation-track support.

## Decision

1. **Design target.** The package is designed for MySQL and for MariaDB, each treated as an independent engine. The design target is not a support claim.
2. **MySQL minimum.** `8.0.16` is the ratified minimum MySQL version. It was chosen for alignment with other Maatify packages, not because the package has a schema that needs it.
3. **Endpoint rule.** For every engine the package declares as supported, the CI contract must run real Integration tests on the oldest declared version and the newest declared version of that engine. Each endpoint is crossed with the oldest and newest supported PHP version. Intermediate versions are added where a substantive behavior difference calls for them.
4. **Independence.** Support for one engine is not inferred from the other. A passing MySQL matrix is no evidence for MariaDB.
5. **Evidence-bound claims.** No document or package metadata may declare a database version, engine or range that is not covered by a passing real-database test in this repository. This includes the README, the Package Reference, Composer keywords and descriptions.
6. **Test integrity.** Tests use real database services from one parameterized Compose definition with pinned image versions. No `latest` image tags, no SQLite or other storage substitutes, no silent skips.
7. **Host settings are never altered.** The package must not change the caller's PDO attributes or server settings to hide an incompatibility. It must not swallow engine errors, and it must not re-run work of a transaction the caller owns without an explicit, safe contract.

## Rationale

The endpoint rule bounds the claim by evidence on both ends of each range. Treating the engines separately prevents a MySQL result from being read as a MariaDB result, which is the very hazard documented in [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md).

## Consequences

- Until the matrix exists and passes, the package makes no public database-support statement beyond "design target".
- The test-matrix endpoints, the Innovation treatment and the upper-bound cap were later ratified in [DEC-013](./DEC-013-test-only-database-version-boundary.md); the optional MariaDB `1020` policy is recorded in [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md). A support claim still needs the endpoint rule above.
- The Composer requirement on `ext-pdo_mysql` is decided in WU-4, not here.
