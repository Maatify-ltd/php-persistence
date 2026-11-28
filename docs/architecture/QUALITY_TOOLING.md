# Quality Tooling (WU-4)

Status: describes the repository-owned toolchain introduced by WU-4. It records what the local gates check and what they do not. It is not a CI definition and not a support claim; CI, the version matrix and the database services belong to WU-5.

## 1. Toolchain

| Concern | Tool and configuration | Constraint in `composer.json` |
|---|---|---|
| Tests | PHPUnit, `phpunit.xml`, suites `unit`, `regression`, and `integration` | `phpunit/phpunit ^12.5` |
| Static analysis | PHPStan, `phpstan.neon`, level `max`, paths `src`, `tests`, `scripts`, and `examples`, no baseline, no `ignoreErrors` | `phpstan/phpstan ^2.3` |
| Code style | PHP CS Fixer, `.php-cs-fixer.php`, rule set `@PER-CS3x0`, plus `scripts/check-per-cs-3-1.php` | `friendsofphp/php-cs-fixer ^3.95` |
| Archive boundary | `.gitattributes` `export-ignore` for repository-only files | none |

The constraints are caret ranges, not patch pins. Versions actually resolved on the delivery machine are in the pull request evidence.

## 2. Composer scripts

| Script | Meaning |
|---|---|
| `composer analyse` | PHPStan, level `max`, over `src`, `tests`, `scripts`, and `examples` |
| `composer format` | Mutating formatter (`php-cs-fixer fix`) |
| `composer format:check` | Non-mutating formatter check, then the supplemental PER-CS 3.1 check |
| `composer test` | Unit, Regression, and Integration |
| `composer test:unit` | The `unit` suite |
| `composer test:regression` | The `regression` suite |

`composer test:integration` is the canonical Integration entry (WU-5); `composer test` runs unit, regression and then Integration through it. `composer syntax` and `composer whitespace` give local parity for the CI syntax and whitespace gates. The Integration lifecycle, the Compose definition and the version matrix are in [CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md).

## 3. PER Coding Style 3.1 coverage

The pinned PHP Coding Style Standard names PHP-FIG **PER Coding Style 3.1** as the baseline and requires the combined verification surface to cover all of it. PHP CS Fixer implements PER Coding Style only up to 3.0: version 3.95.27 lists `@PER-CS3x0` as its newest versioned set (`php-cs-fixer list-sets`), and its upstream repository has no 3.1 set. PHP CS Fixer 3.95.32 also refuses to run on PHP 8.6, so in CI the formatter runs on PHP 8.4 and 8.5 and the PHP 8.6 cells run the 3.1 verifier alone ([CI_AND_INTEGRATION.md §3.1](./CI_AND_INTEGRATION.md#31-code-style-on-php-86)). The formatter checks the rules implemented in its 3.0 preset. The supplemental token verifier implements the 3.1 changes in the PHP-FIG [migration document](https://www.php-fig.org/per/coding-style/meta/migration-3.1/) and fills identified gaps in the baseline:

| Requirement | Verification |
|---|---|
| `clone` always with parentheses | script: `clone` must be followed by `(` |
| `case` body not wrapped in braces; every non-empty `case` ends with a terminating statement, also the last; multi-line `case` condition in parentheses with `)` and `:` alone on the last line | script: parses every `switch` block, accepts conforming switches and rejects the others. A non-empty case that falls through to the next case is accepted only with a clear comment ("No break", "Deliberate fall-through"), as the specification requires; the last case must always terminate |
| Pipe operator `\|>` spaced on both sides | script: checks spacing when the tokenizer supports the operator (PHP 8.5+); on PHP 8.4 the syntax does not parse, so the syntax check rejects it first |
| Split pipe chain starts each line with one operator and indents continuation lines | script: detects line breaks between the operator and its adjacent operands even when comments intervene. If an operator splits, every operator in that chain starts its line one level deeper than the start of the expression. Separate arguments, branches and nested chains are checked independently. A multiline operand can retain inline pipes |
| Empty closure written as `{}` after one space on the same line | script |
| Attributes on anonymous classes start on the line after `new`, indented once, class declaration on its own line with the same indentation | script |
| Enum cases use PascalCase and non-public enum members use `private` | script: checks direct enum cases, constants and methods. An uppercase enum constant is valid; nested anonymous class methods keep their own visibility rules |
| PSR-1 named declarations and method names | script: a named class, interface, trait or enum has a formal namespace and its own, case-matching source file. Methods use camelCase except for PHP magic methods. Anonymous classes and standalone functions retain their applicable rules. Composer autoload and entry-point tests establish discovery |
| Multiline array layout | script: the opening bracket stays with the declaration, each direct value starts on a separate line, and the closing bracket follows the last value on its own line. A nested unkeyed list can open on its own line as in PER-CS §11 |
| Split ternary layout | script: both operators start lines indented once from their condition when the operators themselves split across lines. A multiline operand can retain an otherwise inline ternary |
| Split binary operator layout, including short ternary `?:` | script: the operator starts the line carrying its right operand, indented one level. Logical operators in multiline control conditions follow the indentation of the condition group. Type separators, references and unary signs retain their own syntax rules |

Scope: the formatter and supplemental verifier scan handwritten PHP, including PHP under `docs/`, the root configuration file `.php-cs-fixer.php`, and `scripts/`. Dependency and tool directories remain excluded as applicable. `composer syntax` also lints PHP under `docs/`. `tests/Regression/PerCodingStyleDeltaCheckTest.php` has accepting and rejecting cases and checks default discovery of a PHP example in an isolated documentation tree.

Limits that remain: the script is a repository-owned supplemental verifier and is not the PHP-FIG reference implementation. The pipe-operator rules cannot be exercised on PHP 8.4. Their fixtures (`PerCodingStyleDeltaCheckTest`: a conforming chain, no spaces, operator at the end of a line, unindented continuation) assert the real rules on PHP 8.5 and assert that the verifier refuses the operator on earlier versions; the PHP 8.5 CI cells run the real-rule branch (WU-5). A passing check proves the configured rules for the files it scanned. The full normative reference remains PHP-FIG PER Coding Style 3.1. Before switching to a future formatter preset, verify each supplemental rule against it and retain coverage for any rule it omits.

## 4. Historical planning inputs for WU-5

These requirements guided the original CI setup. The [test matrix in CI and Integration](CI_AND_INTEGRATION.md#3-test-matrix) describes the current jobs.

- PHP: the oldest supported minor, `8.4`, and the latest released minor allowed by `^8.4`, for Unit, Regression and Integration (implemented in WU-5: [CI_AND_INTEGRATION.md](./CI_AND_INTEGRATION.md)).
- Dependencies: a latest-compatible resolution and a lowest-supported resolution (`composer update --prefer-lowest --prefer-stable`) on PHP 8.4, running analysis and tests.
- Composer: run the dependency-policy audit with Composer 2.10 or later (CI Workflow Standard §5.1), provisioned deterministically by the CI tooling. `composer.json` carries `config.policy` (advisories block and audit `fail`, malware block with scope `all` and audit `fail`, abandoned audit `fail`). The local verification of this policy with Composer 2.10.3 is recorded in section 6.
- Database services, matrix cells and pinned images per [DATABASE_SUPPORT_AND_TEST_POLICY.md](./DATABASE_SUPPORT_AND_TEST_POLICY.md) and [DEC-013](../decisions/DEC-013-test-only-database-version-boundary.md) (DEC-009 covers the optional protected write checkout).
- A required gate job, workflow lint, immutable action pinning, and the whitespace check (`git diff --check`).

## 5. Dependency compatibility checks done in WU-4

`maatify/exceptions` is declared as `^1.0` ([DEC-012](../decisions/DEC-012-mandatory-pdo-mysql-driver.md)). In WU-4, the published stable versions checked were `v1.0.0` and `v1.1.0`, which declared `php ^8.2`. WU-4 resolved the lowest and the latest stable versions with PHP 8.4, loaded every class of the dependency with all errors and deprecations reported, and ran the repository gates against the lowest resolution. The recorded results are in the pull request evidence. This does not replace the WU-5 jobs.

## 6. Dependency-policy verification (Composer 2.10.3)

Composer 2.10.3 (`composer.phar` from getcomposer.org, SHA-256 `7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6`, matching the published checksum) was used on the delivery machine in addition to the default Composer 2.8.6. Results:

- `composer validate --strict` and `composer audit --no-interaction --abandoned=fail` pass for this repository, and `composer config policy` reports the configured values.
- Advisories block: in a scratch project with the same policy, requiring `symfony/http-foundation` `5.0.0` fails at dependency resolution because the version is affected by security advisories.
- Abandoned audit: in a scratch project with the same policy, an installed abandoned package (`fzaninotto/faker`) makes `composer audit` exit with status 1. The same audit exits 0 when `--abandoned=report` is passed, or when the policy is set to `ignore`; this is why those overrides are prohibited in required gates.
- Malware policy: the configuration is accepted and reported by Composer 2.10.3. No known malicious package was available to produce a blocking demonstration, so the malware behavior is verified as configuration only.

The scratch projects were outside the repository and were not committed. These are local results from WU-4. The [current Compat matrix](CI_AND_INTEGRATION.md#3-test-matrix) runs the policy audit on six PHP and dependency cells. The shared setup action provisions Composer 2.10.3 and checks its SHA-256.
