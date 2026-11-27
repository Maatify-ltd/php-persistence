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

The current Published Artifact Verification command accepts RC.2. Run it from the historical checkout using the `QUALIFIED_SHA` recorded above. Stable `1.0.0` requires separate Published Artifact Verification tooling for its eventual external distribution.

```sh
composer release:verify-published -- --target=1.0.0-rc.2 --sha="$QUALIFIED_SHA"
```

The command requires the release tag to resolve to the supplied qualified SHA. It uses a fresh external Composer root, home, and cache. It resolves the exact version through Packagist, checks the GitHub dist and source references against the qualified SHA, and requires Composer's installed metadata to report an actual `dist` installation. It compares the complete installed file inventory and bytes with a Git archive of the qualified commit, and logs version, installation mode, installed path, resolved metadata, and file hashes before cleaning up. Save the output with the release evidence. A missing version or dist, a source fallback, or any content difference fails verification. The command is read-only with respect to GitHub and Packagist. It is separate from the Consumer Verification Harness.
