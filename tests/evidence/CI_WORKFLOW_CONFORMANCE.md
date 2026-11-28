# CI workflow conformance evidence

This review maps the published workflow, its local entry points and the actual
GitHub execution to the pinned CI Workflow Standard. It gives reviewers the
inputs needed to independently verify the gate, archive identities and findings.

- Reviewed implementation: [`cf07db569a43b6afe19d93b25694bb3a5487b183`](https://github.com/Maatify-ltd/php-persistence/commit/cf07db569a43b6afe19d93b25694bb3a5487b183)
  on [#108](https://github.com/Maatify-ltd/php-persistence/pull/108).
- Pinned standard: [CI Workflow 7.0.0](../../docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md)
  from `c1dbb2da062a35c45638abf2bcb2ae210140eb5d`.
- [Roadmap and release gates](https://github.com/Maatify-ltd/php-persistence/issues/65)
  and [Batch 6 handoff](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7234977176).
- Baseline verdict: **REQUEST CHANGES** for local audit parity. The correction
  has its own [review on #111](https://github.com/Maatify-ltd/php-persistence/pull/111)
  and [exact-HEAD CI checks](https://github.com/Maatify-ltd/php-persistence/pull/111/checks).

## Compliance and evidence

| Standard section | Result | Evidence |
| --- | --- | --- |
| §§1–2, 2.3 | PASS | The Composer artifact owns the deterministic checks. The full run for the Batch 6 integration boundary reports 31 required successes. Stable terminal gate and distinct jobs make the failure boundary explicit. |
| §2.1 Local parity | F108-19 correction implemented | The local map provides Composer validation, unconstrained-by-lock resolution, real platform checks, strict PSR-4, syntax, whitespace, analysis, style, test/fixture orchestration, lint and per-target archive invocations. The correction uses the same dependency-audit command locally and in Compat. |
| §2.2 Consumer Verification | PASS | The workflow calls `composer test:consumer` on PHP 8.4 and 8.6, MySQL 8.4.13 and MariaDB 11.8.9. Each job uses the repository lifecycle and clean production consumers. Logs record eight lifecycles and 16 installs of the exact candidate for coexistence/differential. The terminal gate requires the jobs. See [Consumer Verification](../../docs/guides/CONSUMER_VERIFICATION.md). |
| §2.4 SHA qualification | Gate documented, pending #88 | The [handoff](../../docs/guides/RELEASE_ARTIFACT_VERIFICATION.md#release-evidence-handoff) requires actual selected `main` SHA, Full CI and target-specific RAV. The current evidence covers the review branch. |
| §2.5 Release Artifact Verification | PASS for the reviewed target | The Stable verifier runs against explicit SHA and target. The RC.2 job uses a separate tag checkout and its own verifier. The current [guide](../../docs/guides/RELEASE_ARTIFACT_VERIFICATION.md) supplies equivalent local invocations. Exact install target, undated Stable changelog allocation and the dated security/support snapshot were checked in context. |
| §2.6 Published Artifact Verification | Tool ready, Stable execution pending publication | The [verifier](../../scripts/published-artifact.php) uses a fresh external root, isolated Composer configuration, controlled preference, exact metadata and tag reference, observed install mode, inventory/hash parity and record-before-cleanup. The required mode is dist. The regression suite exercises evidence on failure. There is no applicable intentional source-only delivery decision. |
| §2.7 Immutable version | PASS for current records | RC.2 tag and Packagist dist resolve to `71dc61548e48fd053376db69149a00bc87069b70`. CI checks that identity before running its verifier. The release handoff uses the qualified SHA from the actual target boundary. |
| §§3–4, 16–17 | PASS | Full baseline for PRs into `main` and pushes to `main`, with no workflow path filter. Manual dispatch is also available. `CI gate` inspects all seven required upstream job groups and rejects any non-success. GitHub's active rules on `main` select `CI gate` only, with GitHub Actions integration ID `15368`. |
| §§5–6 | PASS | `composer validate --strict`, temporary lock during resolution, latest and lowest across each PHP minor, real `composer check-platform-reqs`, strict PSR-4. No committed lock, vendor directory or cache artifacts. |
| §§7–7.1 | PASS for CI matrix; release security evidence pending #88 | Six Compat jobs recorded stable PHP `8.4.28`, `8.5.13` and `8.6.0`. Across 29 PHP jobs the common action verified actual CLI and applicable floors. The constraint probe accepts the pinned floors, rejects adjacent lower patches and exercises the forward range without running those interpreters. Official security evidence for admitting PHP 8.6 must be recorded at release qualification as specified in [CI and Integration §3.3, PHP security patch verification](../../docs/architecture/CI_AND_INTEGRATION.md#33-php-security-patch-verification). |
| §8 | PASS | PHP syntax checks cover tracked PHP, PHPStan max checks the configured source/test/script/example paths with no suppressions, Git checks whitespace against the empty tree, full format check runs on 8.4–8.5 and required PER-CS on 8.6 under DEC-028. Unit, Regression, Integration and four smoke cells ran. Smoke ran nine examples per cell with production autoload; the bootstrap is shared support. |
| §9 | PASS on the CI baseline | Six baseline jobs used Composer 2.10.3, checked bypass environment and effective advisories/malware/abandonment policy, then audited non-interactively. The shared command below replaces those workflow steps. DEC-016 covers only the exact legacy fixture in an isolated coexistence project. See the [correction's CI checks](https://github.com/Maatify-ltd/php-persistence/pull/111/checks) for runs on #111. |
| §10 | PASS | Pinned checksum-verified actionlint 1.7.12 checks the sole tracked workflow. Local lint also passed with actionlint 1.7.12. |
| §11, §18 | PASS | Shared Compose definition, explicit seven version/digest nodes, readiness, fresh project IDs, loopback endpoint and fixture-owned teardown. Required Integration covers the endpoint versions on PHP minimum/latest. Logs show 15 cells, 60 runs of 523 tests, 60 clean residue checks and 30 verified teardowns. See [CI and Integration](../../docs/architecture/CI_AND_INTEGRATION.md) and DEC-008/013/027. |
| §§12–13 | PASS | External actions use full SHA pins. The workflow grants read-only contents, drops persisted checkout credentials and uses test-owned services. Composer/actionlint downloads are pinned and checksum-checked. Required jobs set timeouts; the concurrency policy cancels superseded PR runs and preserves protected-branch runs. |
| §§14–15 | N/A | Required checks have no dependency on restored caches or coverage artifacts. |
| §19 checklist | See [#111 review](https://github.com/Maatify-ltd/php-persistence/pull/111) | Track F108-19 acceptance on #111 and the roadmap handoff in [#65](https://github.com/Maatify-ltd/php-persistence/issues/65). Exact-`main` qualification and post-publication execution follow #88 and the release handoff. |

## F108-19 — local dependency audit omits the CI policy preflight

The baseline [CI workflow](https://github.com/Maatify-ltd/php-persistence/blob/cf07db569a43b6afe19d93b25694bb3a5487b183/.github/workflows/ci.yml#L117-L131) fails before
audit when the configured bypass environment is present and requires the
effective Composer advisories, malware and abandonment policy to match
specific values. The baseline [CI parity table](https://github.com/Maatify-ltd/php-persistence/blob/cf07db569a43b6afe19d93b25694bb3a5487b183/docs/architecture/CI_AND_INTEGRATION.md#1-local-and-ci-parity)
and [contribution commands](https://github.com/Maatify-ltd/php-persistence/blob/cf07db569a43b6afe19d93b25694bb3a5487b183/CONTRIBUTING.md#local-verification) give
only `composer audit --no-interaction --abandoned=fail`. The historical
checks in [Quality Tooling §6](../../docs/architecture/QUALITY_TOOLING.md#6-dependency-policy-verification-composer-2103)
print the policy and describe isolated experiments; they do not document an
equivalent fail-closed local gate.

On the reviewed checkout with Composer 2.10.2, the documented audit exited
0 with `COMPOSER_POLICY_ADVISORIES_BLOCK=0` scoped to that invocation. The
CI preflight on the same input exited 1. The installed packages were otherwise
unchanged. This shows different local and CI acceptance semantics under
§§2.1 and 9. Close the finding by using a shared fail-closed check or an
equivalent documented local sequence and prove that the normal and weakened
configurations yield the same outcomes locally and in CI.

### F108-19 correction on #111

[Local verification](../../CONTRIBUTING.md#local-verification) and the Compat
workflow run the same [dependency audit command](../../scripts/dependency-audit.php).
It rejects weakening environment values and effective Composer configuration,
validates the root lock, and runs the non-interactive audit with development
dependencies included. It rejects caller arguments and returns Composer's
nonzero audit status. The [CLI regression](../Regression/DependencyAuditCommandTest.php)
checks that preflight failures stop before the audit and verifies the exact
Composer invocation and status propagation.

## Recheck the exact evidence

- [CI run 46113345165](https://github.com/Maatify-ltd/php-persistence/actions/runs/46113345165)
  records PR #108 HEAD `cf07db5` and base `e2cc22d`. The five job
  families using merge checkout ran on `d8a08d79b6bde48337c927f28384fcdf79c90d18`.
  Its tree `408d6187894e471e2c399ce5686f5b05e89b55c0` equals the reviewed
  HEAD tree. Harness and Stable RAV recorded `cf07db5` directly.
- Local replay passed `actionlint .github/workflows/ci.yml`,
  `composer validate --strict`, `composer check-platform-reqs`,
  `composer whitespace` and `composer verify:php-constraint`.
  The focused PAV evidence regression ran eight cases and 581 assertions.
- Local `composer release:verify` recorded 171 files per archive for Stable
  `1.0.0` on `cf07db5` and 80 files per archive in the separate RC.2 tag
  checkout. Stable Git SHA-256
  `63a1e490d8a545e7665a89221af1729fe1d6a8bf34bf989b90e375e9373829bd`,
  Composer SHA-256
  `7dd83cae96b7ea206539807a36a5b9a52ee39d74183fc018b1671acd9053b27e`.
- Packagist exact-version metadata listed RC.2 and no Stable `1.0.0` at
  review time. PAV for Stable remains pending publication. #88 remains HOLD.
