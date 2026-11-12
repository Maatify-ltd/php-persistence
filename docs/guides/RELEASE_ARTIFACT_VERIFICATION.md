# Release Artifact Verification

The release target covered here is `maatify/php-persistence:1.0.0-rc.1`. Its immutable artifact is available at `v1.0.0-rc.1`.

Run the same archive checks as CI from a checkout containing the exact committed candidate. The command needs Git, tar, PHP and Composer.

```sh
composer release:verify -- --target=1.0.0-rc.1 --sha=34c3b874525d36ae064c162cdd553bc75d18772b
```

The verifier creates an isolated checkout of the selected commit. It generates a VCS archive with `git archive` and a Composer archive with `composer archive`, independently of each other. The verification checks the actual entries and bytes, including required consumer files, the full runtime source, manifest identity and production PSR-4 mapping, and repository-only or sensitive paths. The log records the target, commit, tree, and a file inventory and SHA-256 for each generated archive. The temporary workspace is removed when verification ends. The sensitive-path and private-key checks are structural. The Lead reviews release claims, changelog allocation and the contents for sensitive data in context.

Before publication, the archive job verified the exact PR HEAD or pushed SHA. The published RC archive job now checks out the tag and verifies it against its recorded qualified SHA. Other jobs continue to verify the changes on the PR or pushed commit. Qualification of a future target requires Full Applicable CI and Release Artifact Verification on the same actual, Owner-authorized `main` candidate commit for that target. The [Consumer Verification Harness](CONSUMER_VERIFICATION.md) separately exercises runtime consumption.

## Published Artifact Verification

After authorized publication makes the exact version available through Packagist, run the following from a repository checkout containing the exact qualified commit and its existing `v1.0.0-rc.1` release tag. Set `QUALIFIED_SHA` to the full SHA from the recorded qualification evidence.

```sh
composer release:verify-published -- --target=1.0.0-rc.1 --sha="$QUALIFIED_SHA"
```

The command uses a fresh external Composer root, home, and cache. It resolves the exact version through Packagist, checks the GitHub dist and source references against the qualified SHA, and requires Composer's installed metadata to report an actual `dist` installation. It compares the complete installed file inventory and bytes with a Git archive of the qualified commit, and logs version, installation mode, installed path, resolved metadata, and file hashes before cleaning up. Save the output with the release evidence. A missing version or dist, a source fallback, or any content difference fails verification. The command is read-only with respect to GitHub and Packagist. It is separate from the Consumer Verification Harness.
