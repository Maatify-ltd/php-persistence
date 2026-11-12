# CI and Integration Infrastructure (WU-5)

Status: describes the CI workflow and the real-database Integration infrastructure introduced by WU-5. It is infrastructure, not a support claim. MySQL and MariaDB remain design targets ([DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md)); the version cells below are the test matrix ratified in [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) (the MySQL `8.0.16` minimum is also [DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md)); they are not support ranges. The Integration suite covers the real servers, `pdo_mysql`, and Transaction, Pagination and Ordering behavior. The [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) maps consumer contracts to test evidence.

## 1. Local and CI parity

Every CI gate runs a repository-owned command that a developer can run locally with the same verification contract.

| Gate | Local command | CI job |
|---|---|---|
| Composer validation | `composer validate --strict` | Static checks |
| Dependency resolution (no committed lock) | `composer update --prefer-stable` (latest) or `composer update --prefer-lowest --prefer-stable` (lowest) | Static checks, Compat |
| Platform requirements, nothing ignored | `composer check-platform-reqs` | Static checks, Compat |
| Strict PSR-4 | `composer dump-autoload --optimize --strict-psr` | Static checks |
| PHP syntax | `composer syntax` | Static checks |
| Whitespace | `composer whitespace` | Static checks |
| Dependency policy audit | `composer audit --no-interaction --abandoned=fail` (Composer 2.10+) | Compat (all four cells) |
| Static analysis | `composer analyse` | Compat |
| Code style and PER-CS 3.1 | `composer format:check` | Compat |
| Unit suite | `composer test:unit` | Compat |
| Regression suite | `composer test:regression` | Compat |
| Integration suite (real database) | `composer test:integration` | Integration |
| Standalone examples (real database) | `composer examples:smoke` | Examples smoke |
| Workflow lint | `actionlint` over `.github/workflows` | Workflow lint |

Unit and Regression need no Docker. `composer test` runs Unit, Regression, and Integration locally. The required `CI gate` aggregates Workflow lint, Static checks, Compat, Integration, and Examples smoke. The exploratory cell is optional.

## 2. Integration lifecycle

`composer test:integration` runs `scripts/integration.php` (functions in `scripts/integration-support.php`, which the regression suite exercises without Docker):

```text
validate prerequisites (docker, compose, daemon, ext-pdo_mysql, vendor)
→ resolve the cell from docker/integration-matrix.json (allowlist; image pinned by tag and digest)
→ docker compose up --wait on compose.integration.yaml (unique project, loopback dynamic port)
→ service health check, then a PDO probe loop with a deadline
→ verify SELECT VERSION() against the cell
→ run the Integration suite
→ residue check: no tables in the schema, no open InnoDB transaction
→ run the suite again on the same service (repeatability)
→ docker compose down --volumes --remove-orphans, always, then verify that no container, volume or network of the project remains
```

Commands:

```bash
composer test:integration                      # default cell, one fresh lifecycle, two suite passes
PP_DB_CELL=mariadb-11.8.9 composer test:integration
composer test:integration -- --cell=mysql-8.0.16 --fresh-runs=2 --suite-passes=2
```

Every external command has a deadline (120 s by default, the whole teardown `PP_TEARDOWN_TIMEOUT_SECONDS`, 180 s by default, startup and the suite the two settings above). A command that exceeds it is sent SIGTERM, killed after a 5 s grace period and reported with status 124; the lifecycle then ends as an infrastructure failure (exit `3`) after the same teardown.

The whole run also has an overall budget, `PP_TOTAL_BUDGET_SECONDS` (default 1500 s). Work never uses the part of the budget reserved for cleanup (`PP_TEARDOWN_TIMEOUT_SECONDS` plus 30 s): every command timeout is cut to what remains, and no new lifecycle or suite pass starts once nothing remains. The Integration job timeout (40 minutes) is sized for budget + reserve + 10 minutes of setup, so GitHub cannot end the job before the runner reaches its own teardown; a regression test enforces that relation. The teardown (both `down` attempts and the three resource queries) shares one deadline, so the reserved time is a true upper bound: a slow or hung Docker cannot stretch it command by command, and anything not checked in time counts as not verified. `docker compose down` is retried once when time allows, and a `down` that still fails makes the teardown fail even when the follow-up resource queries are empty, so `TEARDOWN VERIFIED` is only printed when `down` succeeded and no container, volume or network of the project remains.

Options: `--cell=<key>` (or `PP_DB_CELL`), `--fresh-runs=<n>` independent lifecycles from a fresh service, `--suite-passes=<n>` suite executions per lifecycle. A cell that is not in the matrix is rejected; there is no free-form image, `latest` or floating tag. Exit status: `0` success, `1` test failure, `2` invalid usage or configuration, `3` infrastructure failure (missing prerequisite, unhealthy service, identity mismatch, teardown residue). Nothing is skipped silently: a missing prerequisite or a missing `PHP_PERSISTENCE_IT_*` variable fails.

The PHP runner stays on the host PHP so the PHP matrix is truthful; only the database runs in Docker. Credentials are random per run, scoped to a disposable service and never reused. Baseline CI needs no repository secrets.

### Environment

| Variable | Set by | Meaning |
|---|---|---|
| `PP_DB_CELL` | developer / CI | Cell key; defaults to `default` in the matrix |
| `PP_COMPOSE_PROJECT`, `PP_DB_IMAGE`, `PP_DB_ROOT_PASSWORD`, `PP_DB_DATABASE`, `PP_DB_USER`, `PP_DB_PASSWORD` | `scripts/integration.php` | Compose parameters of one lifecycle |
| `PP_UP_TIMEOUT_SECONDS`, `PP_SUITE_TIMEOUT_SECONDS`, `PP_TEARDOWN_TIMEOUT_SECONDS`, `PP_TOTAL_BUDGET_SECONDS` | developer / CI (optional) | Whole seconds, 1 to 9999, validated before anything starts. Deadline for `docker compose up --wait` including image pull (default 660), for one suite pass (default 900), for the whole teardown (default 180) and for the whole run (default 1500, must exceed the teardown reserve) |
| `DOCKER_DEFAULT_PLATFORM` | `scripts/integration.php` | Set only for a cell that has a single platform (`mysql-8.0.16`: `linux/amd64`) |
| `PHP_PERSISTENCE_IT_ENGINE`, `_EXPECTED_VERSION`, `_CELL`, `_HOST`, `_PORT`, `_DATABASE`, `_USER`, `_PASSWORD` | `scripts/integration.php` | Suite configuration; the suite fails when one is missing |

### Example smoke checks

`composer examples:smoke` runs the Transaction, Pagination and Ordering examples using production autoload. It provisions a fresh service with the Integration Compose file and the same pinned cell definitions, verifies server identity through PDO, and checks table and transaction residue before verified teardown. Run it locally with Docker and installed Composer dependencies:

```bash
composer examples:smoke
PP_DB_CELL=mariadb-11.8.9 composer examples:smoke
```

CI requires both `mysql-8.4.13` and `mariadb-11.8.9` smoke jobs on PHP 8.4. This smoke matrix is separate from the capability Integration matrix below.

## 3. Test matrix

One parameterized Compose definition (`compose.integration.yaml`) serves every cell. The data is in `docker/integration-matrix.json`; images come from the Docker Official Images `mysql` and `mariadb` repositories on Docker Hub.

| Cell | Tier | Image (tag and index digest) | Platforms | CI | Status |
|---|---|---|---|---|---|
| `mysql-8.0.16` | endpoint, oldest | `mysql:8.0.16@sha256:5d11283aee9b73509b737785e0ad79a2d9abf51f4abf3f221702a8add0e36bf2` | `linux/amd64` only | PHP 8.4, 8.5 | minimum ratified by DEC-008; test cell (DEC-013) |
| `mysql-8.4.13` | intermediate (LTS line) | `mysql:8.4.13@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69` | amd64, arm64 | PHP 8.4 | ratified test cell (DEC-013) |
| `mysql-9.7.4` | endpoint, newest | `mysql:9.7.4@sha256:8fb11c38b3551e22d50adbb688f027f8ff4ea2d2445aa277c1cc97b4af85ec54` | amd64, arm64 | PHP 8.4, 8.5 | ratified test cell (DEC-013) |
| `mysql-26.10.0` | exploratory (Innovation) | `mysql:26.10.0@sha256:f51c9c01c16b0eb5d81bb70ab7e7fcb3b96abc6a54114be3a94e8e569cae7815` | amd64, arm64 | manual dispatch only, not required | exploratory |
| `mariadb-10.11.19` | endpoint, oldest | `mariadb:10.11.19@sha256:d769e37960821dfdcf096954f6e7ac89f747e116c1bba98e48edd265dfc7deae` | amd64, arm64, ppc64le, s390x | PHP 8.4, 8.5 | ratified test cell (DEC-013) |
| `mariadb-11.4.13` | intermediate (LTS line) | `mariadb:11.4.13@sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620` | same | PHP 8.4 | ratified test cell (DEC-013) |
| `mariadb-11.8.9` | intermediate (LTS line) | `mariadb:11.8.9@sha256:c4124d1afa875e47f2892edf26b482fa54f796e2f342d3717a04ca091a22a9bd` | same | PHP 8.4 | ratified test cell (DEC-013) |
| `mariadb-12.3.3` | endpoint, newest | `mariadb:12.3.3@sha256:d46e102fa8b9b72bfcdeb56f9dd538742092119dc8b11f31fe662605cd234cd9` | same | PHP 8.4, 8.5 | ratified test cell (DEC-013) |

PHP axis: the oldest supported minor `8.4` and the latest released minor `8.5` (current patches 8.4.27 and 8.5.12 observed on Docker Hub and php.net). Each engine endpoint runs on both PHP minors; the intermediate LTS cells run on `8.4` to avoid a full cross product without a risk that justifies it. Compat runs `{8.4, 8.5} × {latest, lowest}` dependencies. A passing cell is test evidence for that cell, not a support range; the declared range is not widened by this work unit.

### Image provenance evidence (2026-10-27)

- Every tag above exists in the official `library/mysql` and `library/mariadb` repositories (Docker Hub tag API), and every digest in the matrix is the index digest reported by the registry and by `docker pull`.
- `mysql:8.0.16` exists exactly (published 2019-07-17) and is a `linux/amd64`-only image. It runs natively on the amd64 GitHub runners and under emulation on arm64 hosts. No floating `8.0` tag is used and the ratified minimum is not changed.
- All images are pinned by `tag@digest`; a mutable tag alone is never used. Re-pinning is a deliberate change of `docker/integration-matrix.json` reviewed with its own evidence.
- The orchestration compares `SELECT VERSION()` with the cell's expected version and fails on a mismatch, so a retagged image cannot pass silently.

### Observed server facts (images of 2026-10-27, from the Integration runs)

| Cell | `SELECT VERSION()` | Default `innodb_snapshot_isolation` | Error `1020` with the setting ON | Locking read after an older snapshot with the setting OFF or absent |
|---|---|---|---|---|
| `mysql-8.0.16`, `mysql-8.4.13`, `mysql-9.7.4`, `mysql-26.10.0` | `8.0.16`, `8.4.13`, `9.7.4`, `26.10.0` | variable absent | not applicable | sees the latest committed row |
| `mariadb-10.11.19` | `10.11.19-MariaDB-ubu2204` | `OFF` | raised | sees the latest committed row |
| `mariadb-11.4.13` | `11.4.13-MariaDB-ubu2404` | `OFF` | raised | sees the latest committed row |
| `mariadb-11.8.9` | `11.8.9-MariaDB-ubu2404` | `ON` | raised | sees the latest committed row |
| `mariadb-12.3.3` | `12.3.3-MariaDB-ubu2404` | `ON` | raised | sees the latest committed row |

"Raised" means a locking read and an `UPDATE` after an older `REPEATABLE READ` snapshot failed with driver error `1020` when the test set the session variable to `ON`. The tests set the variable only on their own connections of the disposable service. What the server does after `1020` (whether it already ended the transaction) is deliberately not asserted because it differs between versions; this remains an open point of DEC-009 and of the WU-8 contract.

## 4. Decision record

The Project Owner approved the test-only version boundary on 2026-10-27 exactly as recommended, and it is recorded in [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) through the partial-ratification path of [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md): MySQL `8.0.16`, `8.4.13`, `9.7.4`; MariaDB `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3`; MySQL Innovation `26.10.0` exploratory only; the upper edge capped at the highest verified endpoint. The MariaDB `1020` contract stays `PROPOSED`/`DEFERRED` in DEC-009. This is a decision about the test matrix, not a support claim.

## 5. Evidence

The evidence of the local matrix runs and of the CI runs is recorded in the pull request description, because it belongs to a specific commit.
