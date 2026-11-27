# Maatify Engineering Standards Adoption Manifest

## Adoption Record

- **Overall Resolution Status:** `VALID`
- **Adoption Model:** Selective pinned adoption
- **Upstream Repository:** `https://github.com/Maatify-ltd/php-engineering-standards.git`
- **Adoption Commit:** `c1dbb2da062a35c45638abf2bcb2ae210140eb5d`
- **Adoption Date:** `2026-11-27`
- **Additional Standards:** None
- **Explicit Exceptions/Overrides:** None
- **Mixed-Commit Adoption:** No
- **Operational Assets:** None adopted. The `Pinned Operational Assets Set` is absent.

## Pinned Adoption Control Set

All files below match the exact Adoption Commit byte for byte.

- [Standards Adoption Standard](standards/STANDARDS_ADOPTION_STANDARD_AR.md) — `std-standards-adoption` `5.0.2`
- [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md) — `composer-package` `5.0.0`
- [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md) — `repository-governance` `4.0.0`

## Active Profiles

### Activation 1: `composer-package`

- **Profile Version:** `5.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md)

### Activation 2: `repository-governance`

- **Profile Version:** `4.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md)

## Resolved Applicable Standards Set

| Standard ID | Version | Local path | Resolution provenance |
| --- | ---: | --- | --- |
| `std-package-building` | `6.0.0` | [PACKAGE_BUILDING_STANDARD.md](standards/packages/PACKAGE_BUILDING_STANDARD.md) | `composer-package @ /`. Standalone reusable Composer library with direct-PDO persistence behavior. |
| `std-composer-package` | `8.0.0` | [COMPOSER_PACKAGE_STANDARD.md](standards/packages/COMPOSER_PACKAGE_STANDARD.md) | `composer-package @ /`. Standalone Composer library and root package manifest. |
| `std-ci-workflow` | `7.0.0` | [CI_WORKFLOW_STANDARD.md](standards/packages/CI_WORKFLOW_STANDARD.md) | `composer-package @ /`. Artifact CI verifies the reusable package. |
| `std-library-presentation` | `7.0.0` | [LIBRARY_PRESENTATION_STANDARD.md](standards/packages/LIBRARY_PRESENTATION_STANDARD.md) | `composer-package @ /`. Standalone library presentation and distribution. |
| `std-testing` | `3.0.0` | [TESTING_STANDARD.md](standards/testing/TESTING_STANDARD.md) | `composer-package @ /`. Package-owned verification, including repository-provisioned database integration. |
| `std-php-source-documentation` | `1.0.0` | [PHP_SOURCE_DOCUMENTATION_STANDARD.md](standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md) | `composer-package @ /`. Repository-owned PHP source. |
| `std-php-coding-style` | `1.0.2` | [PHP_CODING_STYLE_STANDARD.md](standards/php/PHP_CODING_STYLE_STANDARD.md) | `composer-package @ /`. Repository-owned handwritten PHP. |
| `std-documentation-lifecycle` | `3.1.1` | [DOCUMENTATION_LIFECYCLE_STANDARD_AR.md](standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md) | both activations. Durable repository documentation and decision records. |
| `std-decision-governance` | `1.0.0` | [DECISION_GOVERNANCE_STANDARD_AR.md](standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) | `repository-governance @ /`. Repository decision governance. |
| `std-ai-collaboration-workflow` | `13.0.0` | [AI_COLLABORATION_WORKFLOW_AR.md](standards/ai/AI_COLLABORATION_WORKFLOW_AR.md) | `repository-governance @ /`. Repository engineering collaboration. |
| `std-github-phase-stack-workflow` | `5.0.0` | [GITHUB_PHASE_STACK_WORKFLOW_AR.md](standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md) | `repository-governance @ /`. Repository GitHub phase and integration governance. |
| `std-public-contract-evolution` | `1.0.0` | [PUBLIC_CONTRACT_EVOLUTION_STANDARD_AR.md](standards/contracts/PUBLIC_CONTRACT_EVOLUTION_STANDARD_AR.md) | both activations. Published pre-Stable public contract on Packagist. |

## Resolution Summary

Stage 1 resolved ten direct candidates for `composer-package @ /` and five for `repository-governance @ /`. Neither Profile extends another. Documentation Lifecycle and Public Contract Evolution are shared, yielding thirteen distinct candidates. All candidate paths and required metadata were verified at the Adoption Commit.

The root is the standalone `maatify/php-persistence` library, with its own [Composer manifest](../../composer.json), PHP source, database integration tests, and release-facing documentation. The [published RC.2](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2) exposes a supported pre-Stable public contract, so Public Contract Evolution applies under both activations. The repository-provisioned MySQL and MariaDB integration services do not make this artifact the owner of an externally controlled provider-specific contract. External Provider Verification §1 excludes that candidate. The `composer-package` Profile retains its structural reference to it for Stage 1.

The applicable union contains twelve Standards. All relative file references from those Standards close within the final Pinned Sets. No additional Standards, Operational Assets, or mixed pins are used.

**Frozen version checks.** Same-version artifacts matched the known completed VALID pins at `ebcf55b0591db61d18a1407bc3effef0197b388f`, `c5d70cbe005fc26c38a7218967efe991194e76d6`, and `1f560795397f24bddcc3d09b982ff93a77f7154e`. The selected Adoption Standard is `5.0.2`, which has a separate version from the frozen `5.0.1` artifact. [Central correction and verification](https://github.com/Maatify-ltd/php-engineering-standards/pull/215#issuecomment-7207503163)

The full source inventory and the prior pinned baseline are recorded in [DEC-029](../decisions/DEC-029-selective-pinned-standards-upgrade.md).
