# Release presentation conformance evidence

This review describes the presentation controls and actual evidence needed for an
external reviewer to verify the current Stable preparation. The implementation
reviewed is [`5565625ee710555044b830c6e7d6a93480ddcfb0`](https://github.com/Maatify-ltd/php-persistence/commit/5565625ee710555044b830c6e7d6a93480ddcfb0)
on [#108](https://github.com/Maatify-ltd/php-persistence/pull/108), with
[Library Presentation 7.0.0](../../docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md)
and [Documentation Lifecycle 3.1.1](../../docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md)
pinned at `c1dbb2da062a35c45638abf2bcb2ae210140eb5d`. [#65](https://github.com/Maatify-ltd/php-persistence/issues/65)
tracks the findings and their disposition.

**Reviewed baseline verdict: REQUEST CHANGES.** The release-facing facts and
the four findings below are distinct from the exact-main-SHA gates in #88.

## Library Presentation coverage

| Sections | Result | Evidence |
| --- | --- | --- |
| §§1–6 | PASS for scope and required files | Standalone reusable Composer artifact, licensed for authorized use. All applicable release-facing files are present. An independent local Git and Composer RAV run for Stable `1.0.0` on `5565625` passed with 171 files in each archive. |
| §§7–9 | PASS | Exact-head GitHub-rendered README HTML includes the centered header, light and dark logo sources, three badge rows, repository-local PHP and license values, and links for the artifact scope. The logo and registry download assets returned successfully. The dated RC.2 status and separate Stable target follow §§8.1.1–8.1.2. |
| §§10.1, 11–12 | PASS for presentation | The public overview links to the canonical Package Reference. The README separates three public capabilities, the manifest requirements and tested database versions; it states ownership and failure boundaries. Heading hierarchy and Author formatting were checked in the rendered README. |
| §10.2 | FAIL, F108-22 | The capability map and prerequisites are present. The nine standalone PHP examples are covered by smoke jobs. Host Snapshot B is presented as an inline, Host-dependent example and falls outside the maintained syntax and static-analysis checks. |
| §§10.3–10.4 | PASS | Root `llms.txt` has one heading, consumer/contributor boundaries, the complete canonical Public Runtime API reference, usage map, and six links resolving in the archive. |
| §§13, 22 | FAIL, F108-20 | The Code of Conduct Ecosystem badge uses the old organization. SECURITY points to another package's security instructions as authority for this library's reporting contact even though its own LICENSE supplies it. |
| §14 | PASS for current artifact presentation; first-Stable evidence pending #88 | README and SECURITY distinguish the earlier RC.2 snapshot and the unpublished Stable target. RC.2 can be resolved and installed from dist. DEC-031 covers actual Host validation on the published RC for the first Stable target. The RC.1 Harness evidence remains applicable to the recorded exception path. |
| §15 | PASS | The allocated `1.0.0` section is undated and has no release link. The RC.1 and RC.2 dates and bottom links match release history. This file contains no represented unallocated changes requiring an Unreleased section. |
| §§16–18 | PASS | CONTRIBUTING describes the actual shared dependency-audit gate and local verification. README uses the required Author formatting and final Maatify footer. |
| §19 | PASS for observed metadata | Composer and GitHub description, topics, package identity, homepage and license agree with the documented package. GitHub Releases has the two managed prereleases. No package or deployment panel was observed in the public repo view. Administrative visibility toggles were not inspected. |
| §20 | FAIL, F108-23 | The umbrella carries changes allocated to exact Stable `1.0.0`. Its title and body omit the required preparation/publication state and release-control statement, and the body omits explicit scope. |
| §20.1, §23 | Later release gates pending #88 | Exact main candidate, Full CI, Consumer Harness, RAV, Strict Lead Acceptance, Owner release authorization, then immutable tag and release, external publication, PAV and live documentation synchronization remain ordered release controls. |
| §21 | Verified within the stated review limits | Reviewed GitHub-rendered HTML, logo and badge endpoints, canonical slug and local targets. The independent check resolved 144 tracked relative links and 61 local Markdown anchors in the sampled release-facing docs; a viewport screenshot was not captured. |
| §25 | FAIL, F108-21 | LICENSE and its relative badge are correct. The short README license section does not explicitly state the requirement to obtain written authorization before use. |

## Documentation Lifecycle coverage

| Sections | Result | Evidence |
| --- | --- | --- |
| §§1–3 | PASS for ownership and document roles | The Package Reference owns the current public runtime contract. The architecture and decision records preserve historical context. README, guides, CHANGELOG, SECURITY and LICENSE each direct readers to the appropriate owner. |
| §§4–5.1 | PASS for published and artifact identity | The immutable artifact records exact target `1.0.0`; the RC.2 publication and support statements are dated. The exact install command is conditional on actual publication. The live state is checked in the approved distribution channel. |
| §6 | Review record | This evidence supports independent reproduction on the draft. #65 and PR review/checks carry the current verdict and subsequent corrections. |
| §7 | PASS for reviewed surfaces | Maatify identity uses `maatify.dev`. The separate outdated GitHub organization link is handled under Library Presentation §13 in F108-20. |

## Findings and acceptance evidence

| Finding | Evidence on the reviewed state | Required correction |
| --- | --- | --- |
| **F108-20 — Governance identity and reporting authority** | [CODE_OF_CONDUCT.md:4](../../CODE_OF_CONDUCT.md#L4) points the Ecosystem badge to `github.com/Maatify`; §13 gives the exact `Maatify-ltd` value. [SECURITY.md:19–31](../../SECURITY.md#L19-L31) uses the legacy repository's security instructions in the reporting channel paragraph. | Use the canonical Ecosystem link. Cite this package's own LICENSE for its existing private channel and retain the distinct-package scope. |
| **F108-21 — License summary misses prior written authorization** | [README.md:182–189](../../README.md#L182-L189) describes requesting a free written license. [LICENSE](../../LICENSE) and §25 require the summary to say that written authorization must be obtained before use. | Make prior written authorization explicit in the README while preserving the approved license terms, no-charge process and contact. |
| **F108-22 — Host B example bypasses syntax/static coverage** | [Usage Guide:25–34](../../docs/guides/USAGE_GUIDE.md#L25-L34) designates the [fenced PHP example](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#L964-L977) as the Host B example. Composer syntax scans PHP files and [PHPStan](../../phpstan.neon) does not analyze this Markdown block. The installed Harness proves Host B behavior through its own fixture. | Validate the delivered Host-dependent example with the maintained syntax and static gates, retaining its explicit Host prerequisites. A validated PHP example file is one way to keep its verification attached to the delivered code. |
| **F108-23 — Stable preparation metadata in #108** | At review time the [umbrella title and body](https://github.com/Maatify-ltd/php-persistence/pull/108) did not state the exact target's actual unpublished status or the Owner release-control boundary. This branch already allocates real changes under [CHANGELOG's `1.0.0` target](../../CHANGELOG.md#L7). | Reconcile the umbrella title and description with §20. Name the unpublished target, current preparatory scope and separate Owner authorization. Reference DEC-031, the actual RC.1 Harness, and zero validated real Hosts under the approved exception path. |

## Publication and evidence boundaries

- Packagist and GitHub show RC.1 and RC.2. A clean external Composer project
  installed `maatify/php-persistence:1.0.0-rc.2` in observed `dist` mode at
  `71dc61548e48fd053376db69149a00bc87069b70`.
  [Packagist exact versions](https://repo.packagist.org/p2/maatify/php-persistence.json) ·
  [GitHub RC.2 release](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2).
- [DEC-031](../../docs/decisions/DEC-031-first-stable-real-host-exception.md) records the
  exact `1.0.0` Host-only exception and [published RC.1 Harness evidence](https://github.com/Maatify-ltd/php-persistence/issues/29#issuecomment-6839437553).
  The approved path calls for the final candidate verification on `main`.
- Stable `1.0.0` is a prepared target. Its local archive pass and the independent
  published RC.2 install are evidence for those respective checks. Stable PAV
  follows external publication. [#88](https://github.com/Maatify-ltd/php-persistence/issues/88) remains HOLD.
