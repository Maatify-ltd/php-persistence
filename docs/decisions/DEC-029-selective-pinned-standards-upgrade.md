# DEC-029 — Selective pinned standards upgrade

- **Decision ID:** `DEC-029`
- **Title:** Selective pinned standards upgrade
- **Status:** `ACTIVE`
- **Date:** `2026-11-27`
- **Decision Authority:** The [Project Owner authorization recorded in Issue #65](https://github.com/Maatify-ltd/php-persistence/issues/65#issuecomment-7207751440) selects `c1dbb2da062a35c45638abf2bcb2ae210140eb5d` for this local upgrade on 2026-11-27. The [central readiness record](https://github.com/Maatify-ltd/php-engineering-standards/issues/208) and the [central post-merge verification](https://github.com/Maatify-ltd/php-engineering-standards/pull/215#issuecomment-7207503163) record upstream availability and verification separately.
- **Scope / Concern:** The local Selective Pinned Adoption for the standalone `maatify/php-persistence` library at `/`.
- **Supersedes:** [DEC-003](./DEC-003-pinned-standards-adoption.md)
- **Superseded By:** —
- **Canonical Contract / Current Owner:** [STANDARDS_MANIFEST.md](../php-engineering-standards/STANDARDS_MANIFEST.md) owns the pin inventory. The pinned [Standards Adoption Standard](../php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md) owns the adoption mechanism.

## Context

[DEC-003](./DEC-003-pinned-standards-adoption.md) records the completed original adoption from `ebcf55b0591db61d18a1407bc3effef0197b388f` (tree `a5fc5bab2229e7865d9a5053ea19e98df8beddbe`). The central repository opened adoption and corrected a frozen-version mismatch by merging Adoption Standard 5.0.2 into its default branch. The separate Owner direction for Persistence places this local upgrade before work on the planned first-Stable `1.0.0` target.

## Decision

- Pin `Maatify-ltd/php-engineering-standards` at `c1dbb2da062a35c45638abf2bcb2ae210140eb5d` (tree `d917f7ab71cb6ecde4a944dc6473923f1d6f5937`). Keep both activations at `/`, updating `composer-package` to `5.0.0` and `repository-governance` to `4.0.0`.
- Pin the three Control Set files and twelve applicable Standards from that exact commit. Public Contract Evolution enters the resolved set because this package has a published RC. The provider-specific verification Standard remains outside the set by its canonical applicability.
- Adopt with no Additional Standards, Operational Assets, mixed pins, or exceptions.

| Exact upstream path | Version | Git blob |
| --- | ---: | --- |
| `standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md` | `5.0.0` | `c1bbc75d7de3c66bc56f1a42ee7b90dbe9366f45` |
| `standards/STANDARDS_ADOPTION_STANDARD_AR.md` | `5.0.2` | `2d9849361ff3b99561b7cb4cb40e11e70dd2e190` |
| `standards/ai/AI_COLLABORATION_WORKFLOW_AR.md` | `13.0.0` | `18ead6a9b173133eb9c3aee6d11b1e6b117180ca` |
| `standards/contracts/PUBLIC_CONTRACT_EVOLUTION_STANDARD_AR.md` | `1.0.0` | `1f6b977dc308e369d5bc8ee93a37a5553e35d10d` |
| `standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md` | `1.0.0` | `2c21ee8b3611eb234641bb3c9640ee3ccc756e5f` |
| `standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` | `3.1.1` | `fa7a2758dcee6748fe4ab10a8b69ef177053c885` |
| `standards/packages/CI_WORKFLOW_STANDARD.md` | `7.0.0` | `d1fca42e0d289a1125ed902d6c1d3223f766661f` |
| `standards/packages/COMPOSER_PACKAGE_STANDARD.md` | `8.0.0` | `1c0d58b98f4fddf0e6266f5283a6d2ff5b587a9f` |
| `standards/packages/LIBRARY_PRESENTATION_STANDARD.md` | `7.0.0` | `5af49075de300c85b7b20cc4b7f70f58794a5a4f` |
| `standards/packages/PACKAGE_BUILDING_STANDARD.md` | `6.0.0` | `46eb3e49efeac3d8d8ce90fb9e05db7fd1ac3df3` |
| `standards/php/PHP_CODING_STYLE_STANDARD.md` | `1.0.2` | `23569eb6781f55a501a39274f6a7c6e569650c6e` |
| `standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md` | `1.0.0` | `92864c0a696af4b571bb9ba8fdd527be9d076d33` |
| `standards/profiles/COMPOSER_PACKAGE_PROFILE.md` | `5.0.0` | `9c7930b8cc7cf60af348db93920ecea207a6644a` |
| `standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md` | `4.0.0` | `4ec0c4513b9c49480996bc9e6e0bb48d9639f4ed` |
| `standards/testing/TESTING_STANDARD.md` | `3.0.0` | `6d74329d0c5d262ca1c7c2469947442ec08d9467` |

## Rationale

The selected snapshot applies the centrally approved Stable Major 1 boundary, the first-Stable eligibility rules, and Public Contract Evolution. Resolving the two active Profiles against this exact commit provides a reproducible local contract while limiting the pin to the rules applicable to this library.

## Consequences

- The original adoption and its blob identities remain historical evidence under [DEC-003](./DEC-003-pinned-standards-adoption.md). Future upgrades require their own exact-source resolution and review.
- The first-Stable path and any specific Owner-approved exception are governed by [Library Presentation §14.1](../php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md#141-first-stable-release-gate). Release qualification, Publication, and validation by consumers retain their own approvals and evidence.
- [DEC-030](./DEC-030-successor-transition-before-first-stable.md) records successor intent. The pinned [Package Building §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities) governs shared-capability ownership and eligible consumption. Package identity and coexistence remain governed by [DEC-004](./DEC-004-package-identity-namespace-and-exception-marker.md) and [DEC-005](./DEC-005-coexistence-with-legacy-package.md).
