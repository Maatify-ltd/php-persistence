# Release Artifact Verification

## Verify the Stable 1.0.0 target

Run the repository command from a Git checkout at the committed source you intend to verify. It requires Git, tar, PHP, and Composer. Compare the printed commit and tree with the intended PR head or `main` SHA.

```sh
ARTIFACT_SHA="$(git rev-parse HEAD)"
git rev-parse HEAD 'HEAD^{tree}'
composer release:verify -- --target=1.0.0 --sha="$ARTIFACT_SHA"
```

The verifier accepts only the exact `1.0.0` target and a full commit SHA. It checks the selected commit in an isolated checkout and produces independent Git and Composer archives. For each archive it checks required consumer files and examples, the full runtime source, exclusions for repository-only and sensitive paths, and byte equality against the selected commit. The verifier checks Composer identity and production PSR-4 autoloading, exact target and installation markers in the README, the undated CHANGELOG target heading, and the SECURITY lifecycle and support markers. The output records package target, commit, tree, sorted file inventories, SHA-256 hashes, and the verification result. The temporary workspace is removed when verification ends.

Structural checks cover archive paths and private key markers. The release review verifies claims and their context, the allocation of CHANGELOG entries and any remaining unreleased changes, support semantics, local links, and archive contents for sensitive data.

The required CI job checks out the PR head or pushed SHA, verifies HEAD against that SHA, and invokes the same command for `1.0.0`. Qualification requires evidence on the actual `main` candidate SHA. A passing PR run verifies its own source and archives.

## Verify the historical RC.2 archive

[`maatify/php-persistence:1.0.0-rc.2`](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2) was published from the qualified commit tagged [`v1.0.0-rc.2`](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2). The CI job verifies this target separately with the verifier from that tag. Use a separate checkout at that tag for the same historical check.

```sh
QUALIFIED_SHA=71dc61548e48fd053376db69149a00bc87069b70
test "$(git rev-parse HEAD)" = "$QUALIFIED_SHA"
composer release:verify -- --target=1.0.0-rc.2 --sha="$QUALIFIED_SHA"
```

## Release evidence handoff

For first-Stable eligibility, follow [Library Presentation Standard §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md#141-first-stable-release-gate). When using an exception, obtain Owner approval and an indexed `ACTIVE` decision before qualification. Include an immutable reference to that decision alongside the candidate SHA.

Before requesting publication authorization, record `maatify/php-persistence`, the exact target version, and the actual `main` candidate SHA. Attach separate passing results for [Full Applicable CI](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#24-release-sha-qualification), the [Consumer Verification Harness](CONSUMER_VERIFICATION.md), and [Release Artifact Verification](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#25-release-artifact-verification) for that candidate, along with Strict Lead Acceptance. After external publication, attach [Published Artifact Verification](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#26-published-artifact-verification) for the published target and qualified SHA.

## Published Artifact Verification

Run this command after Packagist publishes the exact Stable `1.0.0` version. Use a Git checkout containing `v1.0.0` and set `QUALIFIED_SHA` to the full qualified commit recorded in the release evidence.

```sh
composer release:verify-published -- --target=1.0.0 --sha="$QUALIFIED_SHA"
```

The verifier requires the local release tag to resolve to that SHA. It uses a fresh external Composer project, home, and cache, with scripts and plugins disabled. It checks the resolved Packagist version and both GitHub references, then requires Composer's installed metadata to report `dist` at the expected package path. It checks required files, runtime paths, and release presentation, and compares the complete installed inventory and file bytes with a Git archive of the qualified commit. Missing metadata or files, source fallback, and content differences fail verification.

Save the output and exit status with the release evidence. The output records the Composer version, install configuration, resolved and installed versions, observed mode, package path, references, and file hashes. This read-only check records integrity evidence for the published distribution. The Consumer Verification Harness and external publication evidence have their own checks.

### Historical RC.2 publication check

For the earlier release, run its original verifier from a separate checkout at `v1.0.0-rc.2`.

```sh
QUALIFIED_SHA=71dc61548e48fd053376db69149a00bc87069b70
composer release:verify-published -- --target=1.0.0-rc.2 --sha="$QUALIFIED_SHA"
```
