# DEC-013 — Test-only database version boundary

- **Decision ID:** `DEC-013`
- **Title:** Test-only database version boundary
- **Status:** `ACTIVE`
- **Date:** `2026-10-27`
- **Decision Authority:** `Project Owner` — explicit approval given in the WU-5 working session on 2026-10-27, on the Technical Lead's recommendation in [PR #11, Fresh Full Independent Re-Review #2](https://github.com/Maatify/php-persistence/pull/11#pullrequestreview-5672395452) and [Issue #10](https://github.com/Maatify/php-persistence/issues/10). The Owner approved the boundary "exactly as recommended" and kept the MariaDB `1020` contract `PROPOSED`/`DEFERRED`. This is a partial ratification of [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) through the path that record defines.
- **Scope / Concern:** Which database versions form the real-database test matrix, how the Innovation track is treated and how the upper edge is capped.
- **Supersedes:** `—`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** `docker/integration-matrix.json` for the exact cells, tags and digests; [CI_AND_INTEGRATION.md](../architecture/CI_AND_INTEGRATION.md) for the lifecycle and the evidence. The rule for turning test results into a support claim stays with [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md).

## Context

[DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md) ratified MySQL and MariaDB as independent design targets, the MySQL minimum `8.0.16` and the rule that a supported range needs real tests at its oldest and newest versions. The exact endpoints, the intermediate cells, the Innovation track and the upper-bound policy stayed in [DEC-009](./DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md) as `PROPOSED`. WU-5 built the matrix on those candidates and ran it on the real engines: every image exists in the official repositories, is pinned by tag and digest, and passed the Integration suite in CI ([CI_AND_INTEGRATION.md](../architecture/CI_AND_INTEGRATION.md)).

## Decision

1. **MySQL matrix:** `8.0.16` (oldest endpoint, already ratified by DEC-008), `8.4.13` (LTS intermediate) and `9.7.4` (newest endpoint).
2. **MariaDB matrix:** `10.11.19` (oldest endpoint), `11.4.13` and `11.8.9` (LTS intermediates) and `12.3.3` (newest endpoint).
3. **MySQL Innovation:** `26.10.0` is an exploratory cell only. It is outside any support claim, is never required, and is not a substitute for the LTS endpoint.
4. **Upper bound:** a published upper edge is capped at the highest endpoint that actually passed. No promise covers later major versions or the Innovation track.
5. **Scope of this decision:** it fixes the test matrix. It is not a support claim for the library and does not declare a supported range. A range may be advertised only under [DEC-008](./DEC-008-database-engine-targets-and-verification-policy.md) (endpoint rule) after the capabilities themselves are tested on these cells, and MariaDB additionally waits for the `1020` contract of DEC-009.
6. **Not decided here:** the MariaDB `1020` contract remains outside the test version boundary. DEC-009 owns the optional protected path.

## Consequences

- The cells and their digests in `docker/integration-matrix.json` are ratified test targets. Adding, removing or re-pinning a required cell, or promoting the exploratory cell, needs a new record that supersedes this one.
- Refreshing a digest does not need a new decision only when all of these hold: the tag and the expected `SELECT VERSION()` are unchanged (same version, rebuilt image); the registry evidence (old and new index digest, platforms) is in the pull request; and the full CI matrix passes on the new digests in that pull request. Any other change — a different version, a new or removed cell, a changed tier, or moving the exploratory cell to required — needs a new record that supersedes this one.
- The README and the Package Reference must not repeat these versions as support claims until DEC-008's conditions are met.
