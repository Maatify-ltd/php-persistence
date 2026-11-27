# DEC-014 — Free written licenses and registration

- **Decision ID:** `DEC-014`
- **Title:** Free written licenses and registration
- **Status:** `ACTIVE`
- **Date:** `2026-11-13`
- **Decision Authority:** `Project Owner`
- **Scope / Concern:** Proprietary licensing and registration for the next package release.
- **Supersedes:** `DEC-002`
- **Superseded By:** `—`
- **Canonical Contract / Current Owner:** [LICENSE](../../LICENSE)

## Context

[DEC-002 at the time](https://github.com/Maatify-ltd/php-persistence/blob/f74917cf3df95437d42360ff5d3b83a7e63584d5/docs/decisions/DEC-002-proprietary-licensing.md#decision)
established the proprietary licensing model. The published
[RC.1 license](https://github.com/Maatify-ltd/php-persistence/blob/34c3b874525d36ae064c162cdd553bc75d18772b/LICENSE#L5-L15)
already required prior written authorization or a separate written license
agreement for uses outside the permissions it stated. [PR #30](https://github.com/Maatify-ltd/php-persistence/pull/30)
documented how to obtain a license at no charge and how registration would work.

## Decision

The package retains the Maatify Proprietary License and Maatify remains the
rights holder. Maatify issues written licenses at no charge and uses the
registered contact details to administer licenses and send security advisories
and critical update notices. Composer continues to identify the license as
`proprietary`. The provenance and third-party notice requirements in DEC-002
continue to apply.

## Rationale

The [registration terms merged in PR #30](https://github.com/Maatify-ltd/php-persistence/blob/0ca8d4a45daf09480fec5f02d31ab579170ea8f6/LICENSE#L15-L24)
give the purpose for collecting designated contact details. Maatify uses them
to administer licenses and send security advisories and critical update notices.
The contemporaneous records establish the no-charge policy. A separate reason
for setting the fee at zero remains undocumented in those records.

## Consequences

The [original application instructions](https://github.com/Maatify-ltd/php-persistence/blob/0ca8d4a45daf09480fec5f02d31ab579170ea8f6/README.md#L151-L158)
ask applicants for an organization name where applicable and an email address
for notices. The [original changelog entry](https://github.com/Maatify-ltd/php-persistence/blob/0ca8d4a45daf09480fec5f02d31ab579170ea8f6/CHANGELOG.md#L7-L15)
records the change under `Unreleased` for the next RC or Stable release.

## Release boundary

Include these terms with the next RC or Stable release through the usual
release process. This decision does not authorize a release or modify an
already published artifact.
