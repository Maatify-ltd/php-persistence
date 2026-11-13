# DEC-002 — Proprietary licensing

- **Decision ID:** `DEC-002`
- **Title:** Proprietary licensing
- **Status:** `SUPERSEDED`
- **Date:** `2026-10-23`
- **Decision Authority:** `Project Owner`
- **Scope / Concern:** License of the repository and of any package distributed from it.
- **Supersedes:** `—`
- **Superseded By:** `DEC-014`
- **Canonical Contract / Current Owner:** [LICENSE](../../LICENSE)

## Context

The initial study recommended MIT. The Project Owner did not ratify MIT and decided that the license is proprietary ([Issue #1, comment 6371707702](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6371707702)). The Owner then directed that the repository use the established Maatify license text instead of a short ad-hoc notice, and ratified the rights holder and the use of that text ([Issue #1, comment 6373127782](https://github.com/Maatify/php-persistence/issues/1#issuecomment-6373127782)).

## Decision

- The repository is licensed under the **Maatify Proprietary License**. The rights holder is `Maatify`. MIT is not active and the MIT text of the earlier `maatify/persistence` is not used.
- `LICENSE` is a byte-for-byte copy of `LICENSE` in `Maatify/php-rate-limiter`, which is identical to `LICENSE` in `Maatify/php-return-target`. Both sources have Git blob SHA-1 `23c643ab15d53d70fae3d36a0ffb7495d8d3425a`, and so has `LICENSE` here. This is an observed organizational precedent, not a central standard.
- A future `composer.json` uses `"license": "proprietary"` and must stay consistent with `LICENSE`, the README and any registry disclosure.

## Rationale

The Owner decides licensing. Public visibility of a repository does not make it open source, and the adopted text states this.

## Consequences

- Importing any code from the earlier MIT-licensed `maatify/persistence` requires a prior provenance check of rights-holder control and inherited notices, which are preserved where required. Reusing this license text is not legal due diligence for such code and does not waive third-party notices. This is a precondition for later source-porting work units.
- Visibility, commercial distribution and external package availability are separate Owner decisions.
