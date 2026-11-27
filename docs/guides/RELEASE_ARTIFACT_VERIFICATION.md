# Release Artifact Verification

[`maatify/php-persistence:1.0.0-rc.2`](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2) was published from the qualified commit tagged [`v1.0.0-rc.2`](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2).

Run the same archive checks as CI from a checkout containing the release tag. The command needs Git, tar, PHP, and Composer.

```sh
QUALIFIED_SHA=71dc61548e48fd053376db69149a00bc87069b70
composer release:verify -- --target=1.0.0-rc.2 --sha="$QUALIFIED_SHA"
```

The verifier creates an isolated checkout of the selected commit. It generates a VCS archive with `git archive` and a Composer archive with `composer archive`, independently of each other. The verification checks the actual entries and bytes, including required consumer files, the full runtime source, manifest identity and production PSR-4 mapping, and repository-only or sensitive paths. The log records the target, commit, tree, and a file inventory and SHA-256 for each generated archive. The temporary workspace is removed when verification ends. The sensitive-path and private-key checks are structural. The Lead reviews release claims, changelog allocation, and the contents for sensitive data in context.

The CI archive job checks out `v1.0.0-rc.2` and verifies it against the recorded qualified SHA. The other jobs continue to verify the pull request or pushed commit. Keep the archive result with the evidence for RC.2 and its qualified SHA.

## Release evidence handoff

For first-Stable eligibility, follow [Library Presentation Standard §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md#141-first-stable-release-gate). When using an exception, obtain Owner approval and an indexed `ACTIVE` decision before qualification. Include an immutable reference to that decision alongside the candidate SHA.

Before requesting publication authorization, record `maatify/php-persistence`, the exact target version, and the actual `main` candidate SHA. Attach separate passing results for [Full Applicable CI](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#24-release-sha-qualification), the [Consumer Verification Harness](CONSUMER_VERIFICATION.md), and [Release Artifact Verification](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#25-release-artifact-verification) for that candidate, along with Strict Lead Acceptance. After external publication, attach [Published Artifact Verification](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md#26-published-artifact-verification) for the published target and qualified SHA.

## Published Artifact Verification

Run this command from the same checkout to verify the published Composer distribution. Use the `QUALIFIED_SHA` recorded above.

```sh
composer release:verify-published -- --target=1.0.0-rc.2 --sha="$QUALIFIED_SHA"
```

The command requires the release tag to resolve to the supplied qualified SHA. It uses a fresh external Composer root, home, and cache. It resolves the exact version through Packagist, checks the GitHub dist and source references against the qualified SHA, and requires Composer's installed metadata to report an actual `dist` installation. It compares the complete installed file inventory and bytes with a Git archive of the qualified commit, and logs version, installation mode, installed path, resolved metadata, and file hashes before cleaning up. Save the output with the release evidence. A missing version or dist, a source fallback, or any content difference fails verification. The command is read-only with respect to GitHub and Packagist. It is separate from the Consumer Verification Harness.
