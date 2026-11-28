# CI and Integration Infrastructure (WU-5)

Status: describes the CI workflow and the real-database Integration infrastructure introduced by WU-5. It is infrastructure, not a support claim. MySQL and MariaDB remain design targets ([DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md)); the version cells below are the test matrix ratified in [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) (the MySQL `8.0.16` minimum is also [DEC-008](../decisions/DEC-008-database-engine-targets-and-verification-policy.md)); they are not support ranges. The Integration suite covers the real servers, `pdo_mysql`, and Transaction, Pagination and Ordering behavior. The [Package Reference](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md) maps consumer contracts to test evidence.

## 1. Local and CI parity

Every CI gate runs a repository-owned command that a developer can run locally with the same verification contract.

| Gate | Local command | CI job |
|---|---|---|
| Composer validation | `composer validate --strict` | Static checks |
| Composer PHP constraint boundaries | `composer verify:php-constraint` | Static checks |
| Dependency resolution (no committed lock) | `composer update --prefer-stable` (latest) or `composer update --prefer-lowest --prefer-stable` (lowest) | Static checks, Compat |
| Platform requirements, nothing ignored | `composer check-platform-reqs` | Static checks, Compat |
| Strict PSR-4 | `composer dump-autoload --optimize --strict-psr` | Static checks |
| PHP syntax | `composer syntax` | Static checks (PHP 8.4), Compat (every PHP minor, including 8.6) |
| Whitespace | `composer whitespace` | Static checks |
| Dependency policy audit | `php scripts/dependency-audit.php` after dependency resolution with Composer 2.10+ | Compat (all six cells) |
| Static analysis | `composer analyse` | Compat |
| Code style and PER-CS 3.1 | `composer format:check` | Compat on PHP 8.4 and 8.5 (§3.1) |
| PER-CS 3.1 verifier alone | `php scripts/check-per-cs-3-1.php` | Compat on PHP 8.6 (§3.1) |
| Unit suite | `composer test:unit` | Compat |
| Regression suite | `composer test:regression` | Compat |
| Integration suite (real database) | `composer test:integration` | Integration |
| Standalone examples (real database) | `composer examples:smoke` | Examples smoke |
| Consumer verification and coexistence | `composer test:consumer` | Consumer verification and coexistence |
| Stable `1.0.0` release archives | `composer release:verify -- --target=1.0.0 --sha="$ARTIFACT_SHA"` from the [selected checkout](../guides/RELEASE_ARTIFACT_VERIFICATION.md#verify-the-stable-100-target) | Release artifact verification |
| Historical RC.2 release archives | `composer release:verify -- --target=1.0.0-rc.2 --sha=71dc61548e48fd053376db69149a00bc87069b70` from the [tag checkout](../guides/RELEASE_ARTIFACT_VERIFICATION.md#verify-the-historical-rc2-archive) | Release artifact verification |
| Workflow lint | `actionlint` over `.github/workflows` | Workflow lint |

Unit and Regression need no Docker. `composer test` runs Unit, Regression, and Integration locally. The required `CI gate` aggregates Workflow lint, Static checks, Compat, Integration, Examples smoke, Consumer verification and coexistence, and Release artifact verification. The exploratory cell is optional and runs only when requested in a manual dispatch.

Run the dependency policy audit after `composer update` generates a current root lock. The same command in Compat checks applicable environment overrides, effective project and global Composer policy, and the secure default Packagist source. It accepts no arguments and audits all packages in that lock, including development dependencies. The legacy abandonment exception in [DEC-016](../decisions/DEC-016-legacy-coexistence-abandonment-audit.md) applies only to the separate coexistence fixture.

On pull requests, Workflow lint, Static checks, Compat, Integration, and Examples smoke use [the default merge checkout](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#how-the-merge-branch-affects-your-workflow). Consumer verification and the Stable archive check use the PR head. On pushes to `main`, those jobs verify the pushed commit. A manual dispatch uses the dispatched SHA for those same checks. The historical RC.2 check always uses its pinned tag. [Release evidence](../guides/RELEASE_ARTIFACT_VERIFICATION.md#release-evidence-handoff) must identify the actual candidate commit in `main`.

## 2. Integration lifecycle

`composer test:integration` runs `scripts/integration.php` (functions in `scripts/integration-support.php`, which the regression suite exercises without Docker):

```text
validate prerequisites (docker, compose, daemon, ext-pdo_mysql, vendor)
→ resolve the cell from docker/integration-matrix.json (allowlist; image pinned by tag and digest)
→ docker compose up --wait on compose.integration.yaml (unique project, loopback dynamic port)
→ service health check, then a PDO probe loop with a deadline
→ verify SELECT VERSION() against the cell
→ run the Integration suite in its own process group and verify its workers have exited
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

The required Integration step gives Composer 1560 seconds so the runner's 1500-second budget controls suite execution and teardown. The step still runs two suite passes in each of two fresh lifecycles.

Options: `--cell=<key>` (or `PP_DB_CELL`), `--fresh-runs=<n>` independent lifecycles from a fresh service, `--suite-passes=<n>` suite executions per lifecycle. A cell that is not in the matrix is rejected; there is no free-form image, `latest` or floating tag. Exit status: `0` success, `1` test failure, `2` invalid usage or configuration, `3` infrastructure failure (missing prerequisite, unhealthy service, identity mismatch, teardown residue). Nothing is skipped silently: a missing prerequisite or a missing `PHP_PERSISTENCE_IT_*` variable fails.

The PHP runner stays on the host PHP so the PHP matrix is truthful; only the database runs in Docker. Credentials are random per run, scoped to a disposable service and never reused. Baseline CI needs no repository secrets.

### Database worker ownership and fixture cleanup

[DEC-027](../decisions/DEC-027-database-test-work-ownership.md) applies to the PHPUnit Integration command, each database example and the installed consumer workloads that receive disposable database credentials. The supervisor starts a separate POSIX session and grants its controlled process group permission to launch only after observing that group. The controller reaps the direct process, leaves the group, and retains its PID while the supervisor checks for remaining members. A zero exit with an unfinished or unobservable group fails before emergency termination. Timeout and interruption also fail before cleanup. Cleanup uses bounded group signals and a nonblocking reap. An emergency termination never converts a failure into permission to sample.

This harness uses PHP CLI `pcntl` and `posix` on Linux and macOS. Docker/Composer setup commands outside database work retain their own deadlines. The owned scripts and their partners and proxies are trusted to stay in the assigned group. This boundary does not contain a program that deliberately changes group or session. Group completion certifies process ownership only. An intentionally interrupted fixture verifies its own server-side outcome through an END acknowledgement or independent locks and data for its affected rows.

The Shadow server-control fixture deliberately reads the InnoDB transaction cache while it owns live actors. It verifies their termination, restores instrumentation, removes the table and releases its connections. It then leaves one fixed quiet period of at least 200 ms measured on a monotonic clock, local to that fixture. The rule is qualified for the seven required pinned engine profiles in §3, whose exact upstream sources refresh the cache after more than 100 ms of read inactivity. The fixture fails explicitly for an unqualified profile, including the exploratory cell. The suite has no concurrent cache reader during the fixture's interval. Health probes send a ping. No delay or follow-up sample is added to the runner. The first immediate sample after successful work remains strict: any tables or transactions fail the gate and Shadow remains diagnostic.

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

CI requires both `mysql-8.4.13` and `mariadb-11.8.9` smoke jobs on PHP 8.4 and on PHP 8.6. This smoke matrix is separate from the capability Integration matrix below. The [Host Snapshot B example](../../examples/pagination_host_snapshot_usage.php) requires an application-owned source and registered page plan. It runs under the PHP syntax and static-analysis gates, with Host B behavior exercised independently in the installed Consumer Verification Harness.

On each of PHP 8.4 and 8.6, [Consumer verification](../guides/CONSUMER_VERIFICATION.md) runs two fresh lifecycles per engine on MySQL 8.4.13 and MariaDB 11.8.9, with separate production Composer roots.

## 3. Test matrix

One parameterized Compose definition (`compose.integration.yaml`) serves every cell. The data is in `docker/integration-matrix.json`; images come from the Docker Official Images `mysql` and `mariadb` repositories on Docker Hub.

| Cell | Tier | Image (tag and index digest) | Platforms | CI | Status |
|---|---|---|---|---|---|
| `mysql-8.0.16` | endpoint, oldest | `mysql:8.0.16@sha256:5d11283aee9b73509b737785e0ad79a2d9abf51f4abf3f221702a8add0e36bf2` | `linux/amd64` only | PHP 8.4, 8.5, 8.6 | minimum ratified by DEC-008; test cell (DEC-013) |
| `mysql-8.4.13` | intermediate (LTS line) | `mysql:8.4.13@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69` | amd64, arm64 | PHP 8.4 | ratified test cell (DEC-013) |
| `mysql-9.7.4` | endpoint, newest | `mysql:9.7.4@sha256:8fb11c38b3551e22d50adbb688f027f8ff4ea2d2445aa277c1cc97b4af85ec54` | amd64, arm64 | PHP 8.4, 8.5, 8.6 | ratified test cell (DEC-013) |
| `mysql-26.10.0` | exploratory (Innovation) | `mysql:26.10.0@sha256:f51c9c01c16b0eb5d81bb70ab7e7fcb3b96abc6a54114be3a94e8e569cae7815` | amd64, arm64 | manual dispatch only, not required | exploratory |
| `mariadb-10.11.19` | endpoint, oldest | `mariadb:10.11.19@sha256:d769e37960821dfdcf096954f6e7ac89f747e116c1bba98e48edd265dfc7deae` | amd64, arm64, ppc64le, s390x | PHP 8.4, 8.5, 8.6 | ratified test cell (DEC-013) |
| `mariadb-11.4.13` | intermediate (LTS line) | `mariadb:11.4.13@sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620` | same | PHP 8.4 | ratified test cell (DEC-013) |
| `mariadb-11.8.9` | intermediate (LTS line) | `mariadb:11.8.9@sha256:c4124d1afa875e47f2892edf26b482fa54f796e2f342d3717a04ca091a22a9bd` | same | PHP 8.4 | ratified test cell (DEC-013) |
| `mariadb-12.3.3` | endpoint, newest | `mariadb:12.3.3@sha256:d46e102fa8b9b72bfcdeb56f9dd538742092119dc8b11f31fe662605cd234cd9` | same | PHP 8.4, 8.5, 8.6 | ratified test cell (DEC-013) |

The PHP test matrix covers `8.4`, `8.5`, and `8.6`. Each engine endpoint runs on all three minors (15 Integration jobs: the 11 earlier cells plus `mysql-8.0.16`, `mysql-9.7.4`, `mariadb-10.11.19` and `mariadb-12.3.3` on PHP 8.6); the intermediate LTS cells run on `8.4` to avoid a full cross product without a risk that justifies it. Compat runs `{8.4, 8.5, 8.6} × {latest, lowest}` dependencies. Examples smoke runs `mysql-8.4.13` and `mariadb-11.8.9` on PHP 8.4 and 8.6, and Consumer verification uses the same two database versions and PHP minors. Jobs that use the shared PHP setup action prove a native stable CLI of the requested minor (`PHP_VERSION`, `cli`, `x86_64`, `PDO`, `pdo_mysql`, `pcntl`, `posix`); the runners are native `x86_64`, so no emulation substitutes the PHP CLI. The PHP installation range comes from [`composer.json`](../../composer.json). The matrix supplies execution evidence for the versions run by each cell.

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

"Raised" means a locking read and an `UPDATE` after an older `REPEATABLE READ` snapshot failed with driver error `1020` when the test set the session variable to `ON`. The tests set the variable only on their own connections of the disposable service. Ordering tests separately observe whole-transaction rollback and the next-statement hazard. Protected-checkout tests assert that a fenced checkout rejects later work.

### 3.1 Code style on PHP 8.6

PHP CS Fixer 3.95.32, the newest stable release at the time of this work, refuses to run on PHP 8.6 because it supports PHP syntax only up to 8.5. The Owner approved separating the formatter host from the library compatibility matrix ([#94 comment](https://github.com/Maatify-ltd/php-persistence/issues/94#issuecomment-7167804049)). `composer format:check` keeps its full meaning locally and runs unchanged on the PHP 8.4 and 8.5 Compat cells. On the PHP 8.6 cells CI runs `php scripts/check-per-cs-3-1.php` as an independent required step. The formatter is not run on PHP 8.6, no unsupported-version override is used, and the code-style verdict concerns the repository source, not the PHP minor. This is not a claim that the formatter ran on PHP 8.6. The decision, its scope and its review condition are recorded in [DEC-028](../decisions/DEC-028-php-cs-fixer-host-separation-for-php-86.md).

### 3.2 Pins of `shivammathur/setup-php`

The qualified release `2.40.0` installs `8.6` as a `PHP 8.6.0-dev` nightly (its `nightly_versions` is `8.[6-9]`), so it cannot qualify the stable minor. The composite action `.github/actions/setup-php-composer` therefore keeps PHP 8.4 and 8.5 on `2.40.0` and uses `main@2001cd3148dcbaf467b7fa06cd7155ebb6d4cacf` for PHP 8.6 only. That commit is 43 commits ahead of `2.40.0`: it sets `nightly_versions` to `8.[7-9]`, marks PHP 8.6 stable, rebuilds the bundled action as ES modules and changes the Linux apt and download helpers, which is why the other minors were not moved. Jobs using the composite action prove the installed build (`PHP_VERSION` of the requested minor, `cli`, `x86_64`, `PDO`, `pdo_mysql`, `pcntl`, `posix`). Review condition: when a tagged release that contains the commit exists, move every minor to the full commit SHA of that release and remove the split.

### 3.3 PHP security patch verification

After PHP setup and before Composer or package checks, the shared action records the actual `PHP_VERSION` and `PHP_VERSION_ID`, verifies the requested Stable minor, and fails when the patch level is unknown or below the applicable [Composer Package Standard §15.1.1 floor](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md#1511-php-security-patch-floors). The required Release artifact verification job uses this action with PHP 8.4 and runs two [archive checks](../guides/RELEASE_ARTIFACT_VERIFICATION.md). For Stable `1.0.0`, it verifies that checkout HEAD equals the PR head or pushed SHA and runs `composer release:verify -- --target=1.0.0 --sha="$ARTIFACT_SHA"`. For the same local check, set `ARTIFACT_SHA="$(git rev-parse HEAD)"` from the intended committed checkout and confirm it matches the candidate SHA. It then checks out `v1.0.0-rc.2` separately in `published-rc2`, verifies HEAD against `71dc61548e48fd053376db69149a00bc87069b70`, and runs `composer release:verify -- --target=1.0.0-rc.2 --sha=71dc61548e48fd053376db69149a00bc87069b70` with the verifier from that checkout.

`composer verify:php-constraint` uses Composer's solver with simulated versions in isolated manifests to verify the declared PHP range. Actual interpreter checks and `composer check-platform-reqs` verify the running platform. For release qualification on PHP 8.6, record the actual Stable interpreter patch, applicable official PHP security advisories and corrected releases for that branch, and its compatibility results under [CI Workflow Standard §7.1](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#71-php-security-patch-floor-verification).

## 4. Decision record

The Project Owner approved the test-only version boundary on 2026-10-27 exactly as recommended, and it is recorded in [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) through the partial-ratification path of [DEC-009](../decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md): MySQL `8.0.16`, `8.4.13`, `9.7.4`; MariaDB `10.11.19`, `11.4.13`, `11.8.9`, `12.3.3`; MySQL Innovation `26.10.0` exploratory only; the upper edge capped at the highest verified endpoint. The MariaDB `1020` policy for the optional protected path is owned by DEC-009. This is a decision about the test matrix, not a support claim.

## 5. Evidence

The evidence of the local matrix runs and of the CI runs is recorded in the pull request description, because it belongs to a specific commit.
