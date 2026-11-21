# Quality Tooling (WU-4)

Status: describes the repository-owned toolchain introduced by WU-4. It records what the local gates check and what they do not. It is not a CI definition and not a support claim; CI, the version matrix and the database services belong to WU-5.

## 1. Toolchain

| Concern | Tool and configuration | Constraint in `composer.json` |
|---|---|---|
| Tests | PHPUnit, `phpunit.xml`, suites `unit` and `regression` | `phpunit/phpunit ^12.5` |
| Static analysis | PHPStan, `phpstan.neon`, level `max`, paths `src`, `tests` and `scripts`, no baseline, no `ignoreErrors` | `phpstan/phpstan ^2.3` |
| Code style | PHP CS Fixer, `.php-cs-fixer.php`, rule set `@PER-CS3x0`, plus `scripts/check-per-cs-3-1.php` | `friendsofphp/php-cs-fixer ^3.95` |
| Archive boundary | `.gitattributes` `export-ignore` for repository-only files | none |

The constraints are caret ranges, not patch pins. Versions actually resolved on the delivery machine are in the pull request evidence.

## 2. Composer scripts

| Script | Meaning |
|---|---|
| `composer analyse` | PHPStan, level `max`, over `src`, `tests` and `scripts` |
| `composer format` | Mutating formatter (`php-cs-fixer fix`) |
| `composer format:check` | Non-mutating formatter check, then the PER-CS 3.1 delta check |
| `composer test` | The complete suite: `unit` and `regression` |
| `composer test:unit` | The `unit` suite |
| `composer test:regression` | The `regression` suite |

`composer test:integration` is the canonical Integration entry (WU-5); `composer test` runs unit, regression and then Integration through it. `composer syntax` and `composer whitespace` give local parity for the CI syntax and whitespace gates. The Integration lifecycle, the Compose definition and the version matrix are in [CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md).

## 3. PER Coding Style 3.1 coverage

The pinned PHP Coding Style Standard names PHP-FIG **PER Coding Style 3.1** as the baseline and requires the combined verification surface to cover all of it. PHP CS Fixer implements PER Coding Style only up to 3.0: version 3.95.27 lists `@PER-CS3x0` as its newest versioned set (`php-cs-fixer list-sets`), and its upstream repository has no 3.1 set. The formatter therefore verifies the 3.0 baseline, and `scripts/check-per-cs-3-1.php` verifies the eight changes of 3.1 listed in the PHP-FIG [migration document](https://www.php-fig.org/per/coding-style/meta/migration-3.1/) on the token stream:

| 3.1 change | Verification |
|---|---|
| `clone` always with parentheses | script: `clone` must be followed by `(` |
| `case` body not wrapped in braces; every non-empty `case` ends with a terminating statement, also the last; multi-line `case` condition in parentheses with `)` and `:` alone on the last line | script: parses every `switch` block, accepts conforming switches and rejects the others. A non-empty case that falls through to the next case is accepted only with a clear comment ("No break", "Deliberate fall-through"), as the specification requires; the last case must always terminate |
| Pipe operator `\|>` spaced on both sides | script: checks spacing when the tokenizer supports the operator (PHP 8.5+); on PHP 8.4 the syntax does not parse, so the syntax check rejects it first |
| Split pipe chain starts each line with the operator, continuation lines indented | script, same condition as above |
| Empty closure written as `{}` after one space on the same line | script |
| Attributes on anonymous classes start on the line after `new`, indented once, class declaration on its own line with the same indentation | script |
| Enum constants follow the `private` instead of `protected` rule (as methods already did) | script: no `protected` member at the level of an `enum` body; method bodies and classes nested inside the enum are not enum members and are not checked by this rule |
| Opening bracket of a multi-line array not alone on its line, in all contexts | script |

Scope: the script scans every PHP file of the repository except `vendor`, `docs`, `.git` and tool caches, so the root configuration file `.php-cs-fixer.php` and `scripts/` are included. `tests/Regression/PerCodingStyleDeltaCheckTest.php` has an accepting and a rejecting case for each rule and asserts the scan scope.

Limits that remain: the script is a repository-owned verifier written for these eight rules and is not the PHP-FIG reference implementation. The pipe-operator rules cannot be exercised on PHP 8.4. Their fixtures (`PerCodingStyleDeltaCheckTest`: a conforming chain, no spaces, operator at the end of a line, unindented continuation) assert the real rules on PHP 8.5 and assert that the verifier refuses the operator on earlier versions; the PHP 8.5 CI cells run the real-rule branch (WU-5). The rest of the 3.1 text is covered by the 3.0 rules the formatter implements. When PHP CS Fixer ships a 3.1 rule set, the configuration moves to it and the script is reduced to what the set does not cover.

## 4. Planning inputs for WU-5 (not a CI matrix)

Local runs on one PHP version are not a substitute for the CI matrix. WU-5 must provide:

- PHP: the oldest supported minor, `8.4`, and the latest released minor allowed by `^8.4`, for Unit, Regression and Integration (implemented in WU-5: [CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md)).
- Dependencies: a latest-compatible resolution and a lowest-supported resolution (`composer update --prefer-lowest --prefer-stable`) on PHP 8.4, running analysis and tests.
- Composer: run the dependency-policy audit with Composer 2.10 or later (CI Workflow Standard §5.1), provisioned deterministically by the CI tooling. `composer.json` carries `config.policy` (advisories block and audit `fail`, malware block with scope `all` and audit `fail`, abandoned audit `fail`). The local verification of this policy with Composer 2.10.3 is recorded in section 6.
- Database services, matrix cells and pinned images per [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md) and [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) (DEC-009 covers the optional protected write checkout).
- A required gate job, workflow lint, immutable action pinning, and the whitespace check (`git diff --check`).

## 5. Dependency compatibility checks done in WU-4

`maatify/exceptions` is declared as `^1.0` ([DEC-012](../decisions/DEC-012-mandatory-pdo-mysql-driver.md)). Its published stable versions are `v1.0.0` and `v1.1.0`, and it declares `php ^8.2`. WU-4 resolved the lowest and the latest stable versions with PHP 8.4, loaded every class of the dependency with all errors and deprecations reported, and ran the repository gates against the lowest resolution. The recorded results are in the pull request evidence. This does not replace the WU-5 jobs.

## 6. Dependency-policy verification (Composer 2.10.3)

Composer 2.10.3 (`composer.phar` from getcomposer.org, SHA-256 `7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6`, matching the published checksum) was used on the delivery machine in addition to the default Composer 2.8.6. Results:

- `composer validate --strict` and `composer audit --no-interaction --abandoned=fail` pass for this repository, and `composer config policy` reports the configured values.
- Advisories block: in a scratch project with the same policy, requiring `symfony/http-foundation` `5.0.0` fails at dependency resolution because the version is affected by security advisories.
- Abandoned audit: in a scratch project with the same policy, an installed abandoned package (`fzaninotto/faker`) makes `composer audit` exit with status 1. The same audit exits 0 when `--abandoned=report` is passed, or when the policy is set to `ignore`; this is why those overrides are prohibited in required gates.
- Malware policy: the configuration is accepted and reported by Composer 2.10.3. No known malicious package was available to produce a blocking demonstration, so the malware behavior is verified as configuration only.

The scratch projects were outside the repository and were not committed. These are local results from WU-4. The authoritative gate is the Compat job of `.github/workflows/ci.yml` (WU-5), which provisions the same Composer 2.10.3 with a SHA-256 check and runs the policy audit on all four PHP and dependency cells.
