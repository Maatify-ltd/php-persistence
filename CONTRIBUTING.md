# Contributing

## Package identity and boundaries

`maatify/php-persistence` is a standalone Composer package (root namespace `Maatify\PhpPersistence\`) offering three independent capabilities over a caller-supplied `PDO` connection: Transaction, Pagination, and Ordering. See [PHP_PERSISTENCE_PACKAGE_REFERENCE.md §1](PHP_PERSISTENCE_PACKAGE_REFERENCE.md#1-purpose-and-boundaries) for what it explicitly does **not** do (no connection factory, no ORM, no schema ownership, no HTTP/host logic). It is a separate package from the published `maatify/persistence`; see [docs/decisions/DEC-005-coexistence-with-legacy-package.md](docs/decisions/DEC-005-coexistence-with-legacy-package.md).

This repository is governed: engineering process, Git permissions, and acceptance gates follow [AGENTS.md](AGENTS.md) and the pinned standards under [docs/php-engineering-standards/](docs/php-engineering-standards/STANDARDS_MANIFEST.md), and durable architectural/scope decisions are recorded under [docs/decisions/](docs/decisions/DECISIONS_INDEX.md). Read the applicable `ACTIVE` decisions before proposing a change that touches architecture, public API, or database-support claims.

## Ways to contribute

- Bug reports and reproductions (not security vulnerabilities — see [Reporting a security issue](#reporting-a-security-issue) below).
- Documentation fixes where this repository's documentation disagrees with the actual source or tests.
- Test coverage for an existing, undertested behavior.
- Proposed changes to Transaction, Pagination, or Ordering, within the boundaries in the Package Reference.

A GitHub issue is not required before every change; open one when you want to discuss scope or get feedback before investing in an implementation.

## Architecture and scope discussion

A change that would alter the public API, add a new capability, change a documented behavior contract, or change a database-support claim is a **Material Decision** under [docs/php-engineering-standards/standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md](docs/php-engineering-standards/standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) and needs a durable decision record, not just code review. Open an issue describing the change and its rationale before submitting such a pull request, so the architectural question is settled before the implementation is.

## Local verification

The commands below cover local setup and verification; see [docs/architecture/CI_AND_INTEGRATION.md](docs/architecture/CI_AND_INTEGRATION.md) for the full local/CI parity table.

```bash
composer update --prefer-stable --no-progress
composer validate --strict
composer check-platform-reqs
composer dump-autoload --optimize --strict-psr
composer audit --no-interaction --abandoned=fail
composer analyse               # PHPStan, level max, zero errors, no baseline
composer format:check          # PER-CS 3.1 code style, non-mutating
composer syntax                # php -l over src, tests, scripts, examples
composer whitespace             # git diff --check
composer test:unit             # no Docker needed
composer test:regression       # no Docker needed
composer test:integration      # needs Docker; starts a disposable MySQL/MariaDB service
composer examples:smoke        # needs Docker; smoke-executes examples/ against a real engine
composer test:consumer         # needs Docker; two fresh external Composer verification runs
composer release:verify -- --target=1.0.0-rc.1 --sha="$(git rev-parse HEAD)"
actionlint .github/workflows/*.yml
```

`composer format` (without `:check`) applies PER-CS 3.1 fixes; do not commit an automatic reformat of code you did not otherwise touch.

### Test and Integration requirements

- A behavior change needs a test that would fail without it, at the narrowest layer that actually proves it (Unit, Regression, or Integration) — see [docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md](docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md).
- A change to Transaction, Pagination, or Ordering's actual database behavior needs real-database Integration evidence, not only a mocked/scripted PDO. `composer test:integration` requires Docker and starts a disposable MySQL or MariaDB service from `compose.integration.yaml`; it never requires repository secrets.
- Do not weaken, remove, or silently skip an existing test or assertion to make a change pass.

### `composer.lock` policy

This package does not commit `composer.lock`. CI resolves both `latest` and `lowest` compatible dependency sets on every run (see the `compat` job in [.github/workflows/ci.yml](.github/workflows/ci.yml)) so that the declared `composer.json` constraints — not a single frozen resolution — are what gets verified. Do not add a committed lock file in a pull request.

## Pull request expectations

- Keep a pull request to one coherent change; include its own tests and any directly affected documentation in the same change rather than splitting them across separate pull requests.
- Describe the change, its rationale, and which local commands above you ran.
- All applicable CI gates (workflow lint, static checks, compatibility matrix, Integration, example smoke, consumer verification, and release artifact verification) must pass before a merge is considered; CI gating and Git/merge permissions follow [AGENTS.md](AGENTS.md) and the pinned `AI_COLLABORATION_WORKFLOW_AR.md`/`GITHUB_PHASE_STACK_WORKFLOW_AR.md` — in particular, merges require explicit Owner authorization.

## Reporting a security issue

Do not open a public issue for a security vulnerability. See [SECURITY.md](SECURITY.md) for the private reporting channel.

## License

By contributing, you agree that your contribution is licensed under the terms in [LICENSE](LICENSE) (Maatify Proprietary License).
