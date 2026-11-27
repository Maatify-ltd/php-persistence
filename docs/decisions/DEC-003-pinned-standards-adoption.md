# DEC-003 — Selective pinned standards adoption at an exact upstream commit

- **Decision ID:** `DEC-003`
- **Title:** Selective pinned standards adoption at an exact upstream commit
- **Status:** `SUPERSEDED`
- **Date:** `2026-10-24`
- **Decision Authority:** `Project Owner` — decision M-5, ratified in [Issue #2, comment 6375609651](https://github.com/Maatify/php-persistence/issues/2#issuecomment-6375609651); implementation authorized by [Issue #3](https://github.com/Maatify/php-persistence/issues/3).
- **Scope / Concern:** Which engineering standards govern this repository, and the exact upstream snapshot they are pinned to.
- **Supersedes:** `—`
- **Superseded By:** [DEC-029](./DEC-029-selective-pinned-standards-upgrade.md)
- **Canonical Contract / Current Owner:** [STANDARDS_MANIFEST.md](../php-engineering-standards/STANDARDS_MANIFEST.md) records the adoption result; the pinned [Standards Adoption Standard](../php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md) owns the mechanism.

## Context

The repository was bootstrapped with six files ([DEC-001](./DEC-001-initial-repository-bootstrap.md), [DEC-002](./DEC-002-proprietary-licensing.md)) and has no pinned standards. The preflight in [Issue #2](https://github.com/Maatify/php-persistence/issues/2#issuecomment-6375565376) compared candidate upstream commits for frozen-version/content consistency. The current upstream head was `INVALID` for three standards, and the newest commit free of frozen mismatches was not chosen. The Owner chose the older commit below, a snapshot that is byte-identical to the one pinned by completed `VALID` adoptions of public consumers.

## Decision

- Adopt the `Maatify/php-engineering-standards` snapshot at the exact commit `ebcf55b0591db61d18a1407bc3effef0197b388f` (Git tree `a5fc5bab2229e7865d9a5053ea19e98df8beddbe`). No moving reference and no artifact from a later commit is used.
- Activate the Profiles `composer-package` `4.0.0` and `repository-governance` `3.0.0`, both at scope `/`.
- Pin exactly fourteen upstream files: the Adoption Standard, the two Profile manifests, and the eleven applicable Standards (`std-external-provider-verification` is excluded by its own canonical applicability). Their Git blob identities are:

| Pinned file (under `docs/php-engineering-standards/`) | Git blob |
|---|---|
| `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | `f96f94a7be5a90caea629ad4fa5051819084859c` |
| `standards/STANDARDS_ADOPTION_STANDARD_AR.md` | `05e37ef12bacb8cb7c0b7bfcfe9633462a868c99` |
| `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | `0b59270ba06bffffb31024ed9935b95de06ae4f1` |
| `standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md` | `2c21ee8b3611eb234641bb3c9640ee3ccc756e5f` |
| `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | `5e75fa711a507f4195d7ca4b8cc43874d6255d5c` |
| `standards/packages/CI_WORKFLOW_STANDARD.md` | `74e499519da522f0f1719c6370c58c2a4eef15a3` |
| `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | `64ce9bc38a87034c5a57df0265f551d083658b32` |
| `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | `ebb130494e57405a35b464eaebb1b716b5471403` |
| `standards/packages/PACKAGE_BUILDING_STANDARD.md` | `1475bb006eefe9e1ef9a6973f6664c3d0690dc2c` |
| `standards/php/PHP_CODING_STYLE_STANDARD.md` | `a4cfae0200db193b9d1a8407f1c034345f3265e7` |
| `standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md` | `92864c0a696af4b571bb9ba8fdd527be9d076d33` |
| `standards/profiles/COMPOSER_PACKAGE_PROFILE.md` | `9c7bf1952372c59d50bb62daa03ce45729d1ebc4` |
| `standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md` | `ef7e13313e804cb6f02785dbff667b58119176b1` |
| `standards/testing/TESTING_STANDARD.md` | `587821a939976b3d7d87c2e8c9c7cfbbba56d7af` |

- No Operational Assets, no Additional Standards, no mixed pinning, no exceptions.
- The pinned snapshot is deliberately older than the current upstream head. Rules added upstream after this commit are not pinned and must not be presented as pinned. Moving to another commit is a separate reviewed Upgrade.

## Rationale

Selective pinned adoption makes the applicable standards local, auditable and independent of a moving upstream. The chosen snapshot is the one whose every artifact matches a frozen, completed adoption, so it carries no frozen-version/content mismatch.

## Consequences

- The pinned Package Building Standard (`3.0.2`) contains an older description of `maatify/persistence` ordering behavior than the current upstream text. It is a consumer-facing description of the published package and does not decide this repository's design.
- This adoption makes **no** decision on Composer identity, namespace, runtime behavior, or on how the Package Building ownership rules apply to a separate successor library. `maatify/persistence` remains the published owner under the pinned standards. Those questions need their own decisions before the work that relies on them.
- Central `DEC-` records are not copied; this repository relies on its own `ACTIVE` decisions plus the pinned standards.
- A later Upgrade must re-run Stage 1, Stage 2, closure, frozen-version checks and the official helper against its own exact commit.
