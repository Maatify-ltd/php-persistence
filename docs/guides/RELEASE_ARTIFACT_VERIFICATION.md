# Release Artifact Verification

The preparation target for this tree is `maatify/php-persistence:1.0.0-rc.1`. Its publication state during preparation is Unpublished.

Run the same archive checks as CI from a checkout containing the exact committed candidate. The command needs Git, tar, PHP and Composer.

```sh
composer release:verify -- --target=1.0.0-rc.1 --sha="$(git rev-parse HEAD)"
```

The verifier creates an isolated checkout of the selected commit. It generates a VCS archive with `git archive` and a Composer archive with `composer archive`, independently of each other. The verification checks the actual entries and bytes, including required consumer files, the full runtime source, manifest identity and production PSR-4 mapping, and repository-only or sensitive paths. The log records the target, commit, tree, and a file inventory and SHA-256 for each generated archive. The temporary workspace is removed when verification ends. The sensitive-path and private-key checks are structural. The Lead reviews release claims, changelog allocation and the contents for sensitive data in context.

In pull-request CI the archive job checks out and verifies the exact PR HEAD. On a push it verifies the exact pushed commit. Qualification requires Full Applicable CI and Release Artifact Verification on the same actual, Owner-authorized `main` commit and exact target. The [Consumer Verification Harness](CONSUMER_VERIFICATION.md) separately exercises runtime consumption.

After separately authorized publication, the approved Composer channel provides the version and distribution evidence for Published Artifact Verification. Its archive and install mode must be observed there. This preparation step makes no assumption about a source-only distribution mode.
