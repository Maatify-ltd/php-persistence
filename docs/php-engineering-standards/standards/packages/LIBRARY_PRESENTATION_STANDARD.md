# Maatify PHP Library Repository Presentation Standard

## Standard Metadata

- **Standard ID:** `std-library-presentation`
- **Standard Version:** `7.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`

## 1. Normative Language

The key words "MUST", "MUST NOT", "REQUIRED", "SHOULD", "SHOULD NOT", "MAY", and "OPTIONAL" in this document are to be interpreted as described in RFC 2119.

* `MUST / MUST NOT`: These are binding rules.
* `SHOULD / SHOULD NOT`: These represent the default expected behavior. Any deviation requires a documented reason.
* `MAY`: This is an optional choice allowed depending on the nature of the library.

## 2. Scope and Relationship to Other Standards

This Standard is responsible for governing:
* Standalone Package presentation and artifact-owned presentation for an extractable Base Module.
* README visual and information architecture.
* Usage Guide and root `examples/` presence, navigation, and discoverability.
* AI consumer discovery and navigation through the artifact-local `llms.txt` entry point.
* Deterministic badge architecture by actual publication state.
* Governance document identity.
* Release-facing documentation state.
* Author and ecosystem identity.
* GitHub description, topics and PR metadata.
* Tag and Release consumption of a release-qualified SHA.
* General exact-SemVer release preparation, SemVer Release Candidate presentation, and first Stable release readiness.

It explicitly does **not** govern:
* Runtime architecture.
* Public API design.
* Database behavior.
* Exception hierarchy.
* Test architecture.
* CI implementation.
* Dependency constraints.

### Relationship to other standards:
* `PACKAGE_BUILDING_STANDARD.md`: Governs library building, runtime architecture, and the stable public/runtime/behavioral package contract.
* `COMPOSER_PACKAGE_STANDARD.md`: Governs Composer metadata, dependencies and Composer stability constraints, autoloading, scripts, configuration, and lock-file policy.
* `CI_WORKFLOW_STANDARD.md`: Governs CI, quality gates, automated testing, and execution of release-artifact verification.
* `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md`: Governs document roles, authority boundaries, current-versus-historical semantics, freshness, retention, and documentation-reference hygiene, including immutable release-artifact documents versus live repository presentation.

For the language of durable technical documentation, including README, Package Reference, Usage/Integration Guides, and release-facing documentation, this Standard follows the canonical default in `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md`; it does not define a second language contract.

This Standard owns consumer-facing presentation, general exact-target release preparation, release-artifact self-consistency, release-facing consumption, and first Stable readiness. `CI_WORKFLOW_STANDARD.md` owns exact release-SHA qualification, Release Artifact Verification execution, and evidence. Composer stability constraints govern dependency resolution; they do not define release eligibility or publication state.

The `llms.txt` contract defined by this Standard is an AI consumer discovery and navigation layer. It does not replace the README, Package Reference, Usage Guide, examples, `AGENTS.md`, or contributor instructions, and it does not create a second public or runtime contract.

Each Standard has clear boundaries and must not duplicate the content of another.

## 3. Applicability

The current version of this Standard applies to:

* `Maatify` standalone PHP Composer libraries; and
* an extractable Base Module Artifact Root when that root owns the files and public contracts being presented.

It MUST NOT be generalized to JavaScript, Rust, or any other language. Host repositories and their root GitHub surfaces remain Host-owned unless they actually represent the artifact being presented.
Every other language or ecosystem MUST have a separate Standard when needed.

### Applicability matrix

| Scope | Consumer documentation and artifact presentation | GitHub/repository surfaces |
|---|---|---|
| Standalone reusable Package repository | Full Usage Guide, root `examples/`, Package Reference, README, badges, and release-facing presentation when applicable | The actual Package repository only |
| Extractable Base Module Artifact Root | Usage Guide, root `examples/`, Package Reference, Quick Usage, and artifact-local README rules | The actual Host/repository remains Host-owned; artifact distribution badges are conditional |
| Host repository root | Host-owned presentation only; it does not represent an embedded Base Module contract | Host-owned Description, Topics, Releases, Deployments, Packages, and other repository surfaces |

Artifact-owned rules apply only where the artifact owns the relevant file or contract. Host metadata MUST NOT present the Host as the embedded Base Package.

### AI consumer discovery applicability

Every reusable consumer-facing artifact to which this Standard already applies MUST provide an artifact-local `llms.txt` at its actual Artifact Root. This follows the existing applicability of this Standard and does not create a separate applicability rule.

* A standalone reusable PHP Composer Package repository MUST provide `/llms.txt`.
* An applicable extractable Base Module Artifact Root MUST provide `llms.txt` inside the Artifact Root that owns its README, Package Reference, Usage Guide, examples, and consumer-facing public contract.
* A Host repository root MUST NOT receive `llms.txt` through this requirement merely because it contains a Base Module.
* A Project-Aware host-specific artifact that is not independently extractable does not receive `llms.txt` merely through Profile inheritance.
* A Slim artifact is covered only when its actual artifact facts make this Standard applicable; the Profile name `slim-module` alone does not create coverage.

The canonical README footer is defined exclusively in [Section 18](#18-canonical-maatify-footer).
Do not replace `PHP Libraries` with `Software Libraries` or any other generic term.

## 4. Canonical Placeholders

The examples in this Standard use canonical placeholders. When applying these templates, the placeholders MUST be replaced with actual values.

* `{PACKAGE_DISPLAY_NAME}`: The human-readable display name of the package.
* `{COMPOSER_PACKAGE_NAME}`: The full Composer package name (e.g., `vendor/package`).
* `{REPOSITORY_SLUG}`: The repository name within the Maatify organization.
* `{PACKAGE_BADGE_NAME}`: The display name used inside badges.
* `{PACKAGE_BADGE_TOKEN}`: The text used within shields.io badges (note that hyphens `-` in the text might need to be encoded as `--`).
* `{PACKAGE_REFERENCE_FILE}`: The specific reference markdown file for the package.
* `{MINIMUM_PHP_VERSION}`: The minimum supported PHP version.
* `{SUPPORTED_MAJOR_LINE}`: The actively supported release line.
* `{EXACT_RELEASE_TARGET}`: The exact SemVer version without a tag prefix allocated to changes currently being prepared or represented, including any pre-release identifier.
* `{ACTUAL_RELEASE_DATE}`: The actual date of an already Published release; never a planned date for an unpublished target.
* `{LATEST_ACTUAL_PUBLISHED_VERSION}`: The latest version without a tag prefix that satisfies the Publication State Definition; used as the `[Unreleased]` compare base only when such a version exists.
* `{PUBLISHED_RELEASE_VERSION}`: The exact already-Published version without a tag prefix used by a published-release link.
* `{BADGE_AREA}`: The complete rendered badge groups selected for the current library according to the badge architecture defined by this Standard.
* `{PACKAGE_SUMMARY}`: A concise and technically accurate summary of the library's primary purpose and supported scope.
* `{ENCODED_COMPOSER_PACKAGE_NAME}`: The URL-encoded Composer package name used inside shields.io badge text, with `/` encoded as `%2F`.

*Note: This standard MUST NOT contain hardcoded names of specific existing packages as default templates. Always use the placeholders.*

## 5. Core Presentation Principles

1. Every library MUST clearly look like a part of the Maatify ecosystem.
2. The first screen of the README MUST communicate:
   * The library name.
   * Its ecosystem identity.
   * Its primary purpose.
   * The package status.
   * How to install or access it.
3. The presentation MUST NOT claim features, support, or a quality status that is not proven.
4. The shared identity MUST NOT erase the functional differences between libraries.
5. A published SemVer Release Candidate MUST be presented against its exact externally resolvable pre-release version; preparation alone MUST NOT claim that it is published.
6. Copying the README or governance files from another library without replacing all names and links is strictly forbidden.
7. The GitHub-rendered appearance is the ultimate reference, not just the raw Markdown source.
8. Presentation changes MUST NOT alter runtime contracts.

## 6. Required Release-Facing Files

Any Maatify PHP library ready for release MUST contain the following files (where applicable):

* `README.md`: Quickly introduces the library.
* `{PACKAGE_REFERENCE_FILE}`: The canonical detailed reference for the stable public/runtime/behavioral package contract and Public Runtime API.
* `CHANGELOG.md`: Documents releases.
* `SECURITY.md`: Defines support, reporting, and scope.
* `CONTRIBUTING.md`: Explains contribution and local verification.
* `CODE_OF_CONDUCT.md`: Establishes community rules.
* `LICENSE`: The package license.
* `composer.json`: The Composer manifest; its canonical manifest contract is governed by `COMPOSER_PACKAGE_STANDARD.md`.

Every reusable standalone Package and every applicable Base Module Artifact Root MUST also provide:

* `docs/guides/USAGE_GUIDE.md`: Consumer-facing usage and integration guidance.
* `examples/`: Root consumer-facing examples.
* `llms.txt`: AI consumer discovery and navigation for the artifact.
* The canonical Package Reference and Quick Usage links to the Guide and examples.

The number of examples is determined by the actual public capabilities; this Standard does not impose a fixed count. A Host repository does not inherit these artifact requirements merely because it contains a Base Module.

The `README.md` MUST NOT be overloaded with all the details present in the Package Reference.

## 7. README Header Standard

By default, the README header MUST contain:
1. Package display name.
2. Maatify logo.
3. Badge area.
4. Package summary.
5. A separator before the detailed content.

### Canonical Template:
```markdown
<div align="center">

# {PACKAGE_DISPLAY_NAME}

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

{BADGE_AREA}

{PACKAGE_SUMMARY}

</div>

---
```

* Center alignment is the recommended default. It MAY be deviated from only with a documented reason.
* The logo link MUST point to the approved Maatify source.
* Logos of other libraries MUST NOT be added.
* The package summary MUST be concise and accurate.

## 8. README Badge Architecture

Badges MUST be divided into logical groups and selected from the actual publication state of the artifact. A badge MUST have a canonical role and a valid source of truth; it MUST NOT appear merely because a reference repository uses it.

The default README badge style is Shields' default/small style. `style=for-the-badge` MUST NOT be used as the README default. The intentional governance-document exceptions in Section 13 remain unchanged.

### 8.1 Publication-State Matrix

This matrix governs **live/default-branch** publication-state presentation. An immutable Release Artifact may target an unpublished version and MUST follow the separate artifact-identity rules in §8.1.2; a live-state badge or value MUST NOT be copied into that artifact as an unqualified timeless claim when it can become false after publication.

#### A. Development / Unpublished

When no version is externally published and resolvable through an approved distribution channel, show:

* `Status = Development`;
* PHP from the declared Composer constraint;
* License from repository-local truth;
* PHPStan Level Max only when proven; and
* Maatify Ecosystem.

Do not show registry version, downloads, distribution, install, or Stable badges. Do not show dynamic Packagist PHP/License badges for an unpublished package.

#### B. Published pre-release without a Published Stable release

When an exact pre-release is externally published and resolvable but no Stable release exists:

* use `Status = Release Candidate` only for an exact `-rc.*` identifier, otherwise `Status = Pre-Release`;
* show the exact published pre-release version, never as `Latest Version`;
* show PHP and License from repository-local truth;
* show PHPStan only when proven;
* show the actual Registry/Distribution link and available monthly/total download metrics;
* show Maatify Ecosystem; and
* use an exact published pre-release install command or badge.

Do not present a prepared Stable target as published or use a generic install command that can resolve a different version.

#### C. Published Stable

After a Stable version is externally published and resolvable, show:

* `Status = Stable`;
* Latest Version from the actual distribution source;
* PHP from current `composer.json`;
* License from current repository/Composer contract;
* PHPStan only when proven;
* Maatify Ecosystem;
* Registry/Distribution, download metrics, and Install only when the actual registry provides them; and
* the applicable documentation links.

If the package does not use Packagist, do not use Packagist badges; use an equivalent authoritative distribution surface only when it exists.

#### D. Existing Stable with a future pre-release

When a Stable release already exists and a future pre-release is published, the default-branch README MUST retain Stable status, the latest published Stable version, Stable download semantics, and the generic Stable install command. The future pre-release belongs in release-specific documentation and MUST NOT replace the default Stable presentation.

### 8.1.1 Orthogonal Exact-Target Release Preparation

`Release Target` is the exact SemVer version to which the changes being prepared are allocated. `Release Preparation` is work on the release-facing state for that exact target. It MAY be active for any exact SemVer target, including Alpha, Beta, RC, or Stable targets, whether it is the first release or a later patch-, minor-, or major-version release. It is a preparation state alongside the Publication State above; it is not a Publication State and does not change the public default-branch presentation from actual published truth.

An exact Release Target MAY exist and be prepared before Publication. Target existence does not establish the existence or state of a Published release.

Release Preparation MUST NOT by itself establish publication, a release-qualified SHA, Stable support, or Release Authorization. The live/default-branch README MUST represent the latest actually published state. When external Publication changes a static current/live claim on the default branch, the affected presentation MUST be synchronized so that the default branch does not knowingly remain stale. Synchronization MAY be omitted only when no static current/live claim became stale, for example because an authoritative dynamic surface already reports the new state correctly or no affected live/current claim is present. An unpublished target MUST NOT be presented as Latest, Published, Stable, or Supported merely because preparation is active.

For example, this is a valid state:

```text
Release Target: 1.0.0-rc.1
Release Preparation: active
Publication State: unpublished
Release Qualification: not yet established
Stable Support: none
```

Publication is determined only by the exact package identity/version being externally resolvable and installable through an approved distribution source, as defined in Section 14. A target section in CHANGELOG, tag, GitHub Release, CI result, or documentation claim alone does not establish publication.

### 8.1.2 Release Artifact Identity and Live Publication Presentation

Three facts MUST remain distinct:

1. **Release Target:** the exact SemVer version selected for preparation.
2. **Release Artifact Identity / State:** the exact immutable package tree prepared to receive that version's tag, including whether it represents an Alpha, Beta, RC, or Stable lifecycle target.
3. **Live Publication State:** whether that exact package name and version is currently externally resolvable and installable through an approved Composer distribution source.

Selecting a target or preparing its artifact does not claim Publication. A tag or GitHub Release alone does not establish Publication; the Publication State Definition remains the source of truth.

The Release Artifact README and the live default-branch README have different temporal roles, even when the same file serves both roles on the release-qualified `main` SHA. In that case, the README MUST label the exact artifact target and its Publication State **as of qualification** as separate facts. A release-qualified tree for target `X` MUST identify itself as the artifact for `X` and MUST NOT identify `X-1` as the identity of the artifact being installed. It MUST NOT present `X-1` as the unqualified current or latest Published version in an immutable artifact when that mutable live claim can become false after `X` is Published. If the README retains an earlier version as a live/current Publication fact, it MUST explicitly time-scope that fact, such as `Published state at qualification: X-1`, so it remains historically true; this does not reclassify ordinary chronological CHANGELOG entries as live/current claims. The live default-branch README MAY report the actual current/latest Published version under §8.1. An artifact MAY identify `X` as a Release Candidate, Stable target, or other applicable lifecycle state before publication, provided it does not claim external availability or support that has not been established; it MAY state `Publication State at qualification: unpublished`.

A genuinely dynamic external display of live Publication State MAY appear in a release artifact only when it resolves actual live state from an authoritative source, keeps the artifact identity fixed at `X`, does not depend on a later source-tree commit, and cannot fall back to a false static package identity if the external source is unavailable. Dynamic badges or displays are not required. A static or dynamic display MUST NOT cause consumers to mistake an earlier Published version for the identity of artifact `X`.

For a release-qualified artifact, the README MUST show the exact installation target (for example, `composer require {COMPOSER_PACKAGE_NAME}:{EXACT_RELEASE_TARGET}`) and explain that the command resolves through the approved distribution source only after that exact version is published. Before Publication, it MUST NOT say that `X` is published, latest, installable from that source, or externally supported solely because the target tree exists.

The live default-branch presentation MUST continue to report actual Publication State. The same `SECURITY.md` MUST label release-artifact lifecycle identity separately from currently published support lines; an RC target MUST NOT be confused with an established Stable support line. After publication, when the Publication changes a static current/live default-branch claim, the affected claim MUST be synchronized to the actual state (including applicable latest-version wording, registry/distribution wording, badge values, actual publication date, live release links, and current/support wording). Synchronization MAY be omitted when no static current/live claim requires mutation, including when an authoritative dynamic surface already represents the new state correctly. This live-state freshness obligation MUST NOT be applied to the immutable tagged artifact: synchronization is NEVER required to make that artifact correct and MUST NOT repair or rewrite it.

The release-qualified SHA MUST already be a complete, internally self-consistent representation of its exact target before it is eligible for a tag. Post-publication synchronization MUST NOT be required to make the immutable tagged Composer artifact correct. If it would be required, release qualification failed.

### 8.2 Source of Truth

| Badge | Canonical truth |
|---|---|
| Status | Actual publication/lifecycle state |
| Version | Live/default branch: actual externally published and resolvable version. Immutable Release Artifact: exact artifact target; any earlier Publication fact is explicitly historical/as-of qualification. |
| PHP | Current `composer.json` constraint |
| License | Current repository/Composer license contract |
| PHPStan | Actual configured and proven quality gate |
| Downloads | Actual distribution registry |
| Install | Actual externally resolvable package identity/version |
| Documentation | Actual current repository or Artifact Root path |

PHP and License badges MUST use repository-local truth, not Packagist dynamic endpoints. Dynamic badges MUST NOT expose `not found` or a value that does not represent the actual artifact state. Semantic duplicates of the same fact from competing sources are prohibited.

### 8.3 Canonical Badge Rows

When a README uses a badge area, keep this order whenever a group has applicable items:

```text
Row 1 — Status / Version / PHP / License / PHPStan
Row 2 — Registry / Monthly Downloads / Total Downloads / Maatify Ecosystem / Install
Row 3 — Documentation links for the artifact scope
```

State-based omission removes an inapplicable item; it does not justify replacing it with an optional duplicate.

### 8.4 Documentation Links and Artifact Scope

For a standalone reusable Package, the documentation row MUST cover Usage Guide, Examples, Package Reference, Changelog, Security Policy, and Contributing Guide when those files apply.

For an embedded Base Artifact Root, the artifact-local row MUST cover Usage Guide, Examples, Package Reference, and Changelog. Host-level Security, Contributing, and other governance files MUST NOT be copied into every Base Artifact merely through inheritance.

When `std-public-contract-evolution` §8.1 requires an Artifact Root `DEPRECATIONS.md`, the artifact's existing consumer-facing documentation MUST link directly to it from the artifact-local README or canonical Package Reference. The registry owns the retirement inventory. The Package Reference remains the public API and behavior reference, and the CHANGELOG remains release history.

### 8.5 Badge Accuracy and Style

Every badge MUST refer to the current artifact, use the correct Composer identity and repository slug, link to the correct local file or actual external page, avoid other libraries, and claim only a proven quality or publication state. README badge groups MUST use the default/small Shields style unless an intentional non-default exception is documented; governance-specific `for-the-badge` exceptions remain limited to Section 13.

## 9. Canonical Badge Templates

Templates are state-specific, not one universal badge set. The following examples show the required source boundaries and deliberately omit `style=for-the-badge`:

### Unpublished Package
```markdown
[![Status](https://img.shields.io/badge/Status-Development-blue)](README.md)
[![PHP](https://img.shields.io/badge/PHP-{MINIMUM_PHP_VERSION}-8892BF)](composer.json)
[![License](https://img.shields.io/badge/License-{LICENSE}-green)](LICENSE)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify-ltd)
```

### Live Published Distribution README
```markdown
[![Status](https://img.shields.io/badge/Status-{PUBLICATION_STATUS}-blue)](CHANGELOG.md)
[![Version](https://img.shields.io/badge/Version-{PUBLISHED_VERSION}-blue)]({DISTRIBUTION_URL})
[![PHP](https://img.shields.io/badge/PHP-{MINIMUM_PHP_VERSION}-8892BF)](composer.json)
[![License](https://img.shields.io/badge/License-{LICENSE}-green)](LICENSE)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify-ltd)
```

Registry, download, and Install badges are appended only when the selected registry actually supports them and the publication-state matrix permits them. The exact package identity and version MUST be used in any install command.

## 10. README Section Architecture

The general default order of sections is:
1. Header and Identity
2. Package Summary
3. Key Features
4. Requirements
5. Installation
6. Quick Usage / Usage
7. Public Runtime API
8. Critical Runtime Behavior
9. Architecture Guarantees
10. Exception and Error Propagation
11. Security and Trust Boundaries
12. Examples
13. Documentation
14. Quality Status
15. Development and Testing
16. License
17. Author
18. Maatify Footer

### Required Sections
Any release-ready library MUST include:
* Package Summary.
* Key Features.
* Requirements.
* Installation.
* Usage or Quick Usage.
* Public Runtime API overview.
* Documentation.
* Quality Status.
* License.
* Author.
* Maatify Footer.

### Conditional Sections
These sections are added only when they apply:
* Critical Runtime Behavior.
* Architecture Guarantees.
* Exception and Error Propagation.
* Security and Trust Boundaries.
* Examples.
* Schema.
* Integration Testing.
* Migration Guide.
* Upgrade Notes.

Do not create empty sections merely to satisfy a template.

### 10.1 Public Runtime API README Contract

For every reusable standalone Package and every applicable Base Module Artifact Root, the README MUST present the artifact's Public Runtime API.

The existence, design, and technical contract of that Public Runtime API remain governed by `PACKAGE_BUILDING_STANDARD.md`; this Standard governs only its consumer-facing README presentation.

The README MUST include a clearly labeled `Public Runtime API` section for the applicable artifact. The section MUST provide a useful consumer-facing overview that is proportionate to the library's nature, size, and actual capabilities. This requirement does not impose a fixed template or a fixed number of interfaces, classes, services, or other entries.

The overview MUST accurately represent the actual Public Runtime API, MUST link to the canonical Package Reference for the complete Public Runtime API inventory and stable public/runtime/behavioral contract, and MUST NOT duplicate the full Package Reference.

### 10.2 Usage Guide and Examples Contract

For every reusable standalone Package and every applicable Base Module Artifact Root:

* `docs/guides/USAGE_GUIDE.md` and root `examples/` are consumer-facing artifacts; no fixed number of examples is required.
* The Guide MUST explain fit, requirements, non-goals, primary calls, inputs, outputs, and boundaries before long walkthroughs.
* A capability map MUST connect each advertised consumer capability to a walkthrough and an example. It MUST cover material capabilities without copying the full Package Reference.
* Each walkthrough MUST make the path clear as `Input → Public Call → Result → Boundary` and MUST consume the Public Contract rather than inventing a technical contract.
* Examples MUST use the Public API and production autoload. Consumers MUST NOT be directed to tests or source code as the primary way to learn usage.
* Standalone runnable examples MUST be smoke-executable. Standalone database/service examples MUST use the repository-owned Integration infrastructure when available.
* A Host-dependent example MAY be excluded from smoke execution only when its non-standalone boundary and prerequisites are explicit; it still requires syntax/static validation.

The CI Workflow Standard owns execution and enforcement of syntax, static, and smoke validation. The Package Building Standard owns the technical workflow contract; this Standard owns artifact presence, presentation, navigation, and discoverability.

### 10.3 AI Consumer Discovery / Navigation Contract

For every reusable standalone Package and every applicable extractable Base Module Artifact Root, `llms.txt` MUST be a plain Markdown AI Consumer Discovery / Navigation Layer at the actual Artifact Root. It is written for an Agent acting as a consumer of the artifact, not for an Agent contributing to or modifying the repository.

`llms.txt` MUST NOT be treated as any of the following:

* the canonical Public Contract;
* a replacement for the Package Reference, Usage Guide, README, or examples;
* `AGENTS.md` or contributor instructions; or
* a source of new Runtime Contract.

The following consumer documentation/navigation hierarchy is limited to the artifact's consumer-facing documentation and navigation sources. It is not a universal source-of-truth hierarchy and does not change, replace, or claim ownership of canonical contracts or artifacts outside this scope.

The consumer documentation/navigation hierarchy is:

| Source | Consumer role and authority |
|---|---|
| Package Reference | Canonical stable public/runtime/behavioral package contract and complete Public Runtime API inventory within its existing scope. |
| Usage Guide | Consumer integration and workflow guidance. |
| `examples/` | Maintained consumer examples using the Public API; not an independent normative contract. |
| README | Package overview, installation, Quick Usage, and Public Runtime API overview. |
| `CHANGELOG` | Release history and deltas. |
| `SECURITY` | Supported versions and security policy. |
| `llms.txt` | Navigation and interpretation only. |

Exact Composer manifest facts remain owned by `composer.json` under `COMPOSER_PACKAGE_STANDARD.md`, including package identity, PHP and extension requirements, dependencies, constraints, production autoloading, Composer configuration, and distribution facts. This consumer documentation/navigation hierarchy does not redefine that ownership. The Package Reference retains ownership of the stable public/runtime/behavioral package contract and Public Runtime API within its existing scope.

The `llms.txt` consumer-context guidance MUST make the consumer/contributor boundary explicit. An AI consumer MUST NOT treat the following as a Supported Consumer Contract merely because they are visible in the repository:

* `src/` internals;
* private or internal classes;
* undocumented public classes or methods;
* tests or fixtures;
* implementation details;
* contributor-only files;
* `AGENTS.md`; or
* internal Engineering Standards.

The governing rule is:

```text
Visible in repository != supported consumer contract
```

If canonical consumer documentation does not support a claim, the Agent MUST NOT invent that contract from implementation internals. This boundary does not prevent technical debugging or inspection; it prevents treating those materials as the artifact's supported Public Contract.

#### Required `llms.txt` structure

The file MUST follow this order and shape:

1. Exactly one H1 naming the artifact or package.
2. A short, accurate blockquote summary.
3. Brief consumer-context guidance, with no additional headings before the link sections.
4. H2 sections containing Markdown file or directory link lists.

Every link-list item MUST be a Markdown link with a concise, useful description. The primary consumer navigation MUST include, when applicable and present for the artifact:

* `README.md` for package overview, installation, Quick Usage, and Public Runtime API overview;
* the actual `{PACKAGE_REFERENCE_FILE}` for the canonical stable public/runtime/behavioral contract and complete Public Runtime API inventory;
* `docs/guides/USAGE_GUIDE.md` for integration workflows and boundaries; and
* `examples/` for maintained examples that consume the documented Public API.

`CHANGELOG.md` and `SECURITY.md` MAY be included under a release/support section when they exist and are applicable. `CONTRIBUTING.md` or another secondary source MAY be included under `## Optional` only when it gives a consumer a clear secondary value. `AGENTS.md`, standards snapshots, tests, and source directories MUST NOT be primary consumer documentation links.

Each link MUST resolve from the location of `llms.txt`, point to the current artifact, and avoid stale paths or foreign-package links. Repository-relative Markdown links are preferred for repository-local sources. A public documentation URL MAY be used only when it exists and is the canonical current source.

When the same canonical source is available in multiple representations, `llms.txt` SHOULD prefer Markdown or another LLM-friendly representation suitable for direct consumption, provided that the selected representation remains current and canonical. This preference MUST NOT require creating a website, a separate Markdown mirror, a per-page Markdown mirror, or any duplicate documentation representation solely to satisfy `llms.txt` compliance.

`llms.txt` MUST remain concise enough to work as entry context. It is a map, not the territory: it MUST NOT copy the Package Reference, Public API inventory, Usage Guide, examples, architecture explanations, or release history. The artifact's canonical documentation remains behind the links.

The navigation MUST be refreshed when the artifact identity, summary, canonical Package Reference path, Usage Guide path, examples path, release/support links, or consumer source hierarchy becomes stale. A Runtime behavior change does not by itself require an `llms.txt` change when its navigation and summary remain accurate; `llms.txt` MUST NOT become a changelog.

For interoperability, the structural shape MAY follow `llms.txt proposal v2 — 2026-08-10`. That external proposal is not the Maatify canonical contract owner; this Standard remains the canonical owner inside Maatify.

This Standard does not adopt or require `llms-full.txt`, any generated full-context file, or a README badge or README link to `llms.txt`.

### 10.4 `llms.txt` Verification Checklist

At Applicability, Library Presentation compliance MUST NOT be accepted until the following checks pass for the actual artifact root:

* [ ] `llms.txt` exists at the correct Artifact Root.
* [ ] It has exactly one correct H1 and an accurate concise summary.
* [ ] Its consumer/contributor boundary is explicit.
* [ ] The Package Reference is identified as the canonical stable public/runtime/behavioral contract and complete Public Runtime API inventory.
* [ ] The README, Usage Guide, and examples are linked and described with their correct ownership roles.
* [ ] Every included link resolves from `llms.txt` and points to the current artifact.
* [ ] No foreign-package link or stale path is present.
* [ ] No undocumented internal API claim is presented as supported consumer behavior.
* [ ] The file does not duplicate full documentation and remains concise.
* [ ] No `llms-full.txt` or README-link requirement has been introduced by inference.

This checklist is a documentation and review check. It does not require new executable tooling, a generator, or a CI gate.

## 11. Heading and Emoji Rules

* Emoji MAY be used.
* If used, they MUST be consistent across parallel headings.
* Do not use emoji randomly in some sections while leaving similar sections unstyled.
* The heading hierarchy MUST be semantically correct.
* There MUST be one logical `#` main heading.
* Separators `---` MAY be used between major groups, but without excess.
* The presentation MUST NOT become cluttered or overly decorative at the expense of clarity.

## 12. Runtime and Documentation Accuracy

The Presentation Standard does not allow altering technical facts.
The README MUST:
* State the actual runtime requirements.
* State the actual dependencies.
* Describe the actually supported databases.
* Clarify concurrency, transaction, or error propagation when public contracts require it.
* Distinguish between package-defined exceptions and external throwables.
* Not omit critical contracts for the sake of appearance.
* Not copy runtime claims from another library.
* Link to the reference documentation for lengthy details.

## 13. Governance Document Identity Badges

Standard identity badges MUST be present in specific locations.

### 13.1 `CODE_OF_CONDUCT.md`
Defaults to starting with:
```markdown
# Code of Conduct — {COMPOSER_PACKAGE_NAME}

[![Maatify {PACKAGE_BADGE_NAME}](https://img.shields.io/badge/Maatify-{PACKAGE_BADGE_TOKEN}-blue?style=for-the-badge)]({REPOSITORY_URL})
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify-ltd)
```

### 13.2 `SECURITY.md`
Defaults to starting with:
```markdown
# Security Policy

[![Maatify {PACKAGE_BADGE_NAME}](https://img.shields.io/badge/Maatify-{PACKAGE_BADGE_TOKEN}-blue?style=for-the-badge)]({REPOSITORY_URL})
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify-ltd)
```

### Mandatory Rules
* `style=for-the-badge` MUST be used in these two locations.
* The first badge MUST point to the current canonical repository URL. Replace `{REPOSITORY_URL}` with that full URL.
* The Ecosystem badge MUST point to the Maatify Ltd. GitHub organization at `https://github.com/Maatify-ltd`.
* The name or repository link of another library MUST NOT be used.
* A third badge MUST NOT be added without a documented decision.
* The large Maatify logo MUST NOT be added inside these files.
* Do not copy errors or links from an older reference file.

### Approved Locations
Identity badges are required by default in:
* `CODE_OF_CONDUCT.md`
* `SECURITY.md`

They are NOT automatically added to:
* `CHANGELOG.md`
* `CONTRIBUTING.md`
* `LICENSE`
Unless a subsequent decision alters this Standard.

## 14. First Stable Release Lifecycle and SECURITY Presentation States

**Publication State Definition:** For this Standard and cross-Standard use, a package/version is Published only when that exact package identity and exact version are externally resolvable and installable by an external consumer through an actual recognized Composer distribution source. Packagist MAY be such a source, but it is not the only possible source. A branch, commit, tag, GitHub Release, Draft PR, successful CI run, local path repository, documentation claim, or Git tag without evidence of external Composer resolution and installation does not by itself establish Published state. Use this definition for actual Publication in either first-Stable path under Section 14.1.

### 14.1 First Stable Release Gate

This gate applies only to the first Stable target of a package that has never published a Stable release. RC releases and later Stable releases follow their own applicable release controls. The package MUST satisfy the default path or a valid Owner-authorized exception path for first-Stable eligibility. Both paths remain subject to Section 14.1.4.

#### 14.1.1 Default Path

```text
Published exact SemVer RC for the intended Stable target
→ Consumer Verification Harness on that published RC
→ Real Host Validation on that same RC in 2+ independent real projects/Hosts
→ First-Stable gate satisfied
```

The exact RC MUST be a SemVer pre-release of the intended Stable version, such as `1.0.0-rc.1`, and MUST be Published and externally resolvable and installable through the package's approved Composer distribution source. An unpublished RC target MAY be prepared. A branch, Draft PR, successful CI run, documentation state, local path repository, or unpublished tag MUST NOT satisfy the RC step.

The Consumer Verification Harness MUST pass on that exact RC after Publication. Real Host Validation MUST then use that same RC in at least two independent real projects/Hosts. A Harness run, fixture, or additional environment of the same Host MUST NOT be counted as a separate real Host. Retain verifiable evidence identifying the exact RC, the Harness run evidence, and each independent Host validation.

#### 14.1.2 Owner-Authorized Exception Path

For one exact first-Stable target, the Owner MAY expressly except the requirement for a published RC, part or all of the Real Host Validation requirement, or both. An engineering or business rationale and an evidence-based risk assessment are required. Impossibility of publication or of obtaining real projects need not be shown. An approval that expressly covers the full Host requirement MAY allow zero validated Hosts.

The remaining RC and Host requirements MUST be met on their original terms. With no published RC, validation on a published RC cannot be claimed. The decision MUST explicitly cover every unmet RC or Host requirement, including any Host validation that cannot be completed because the RC is absent. An exception for one requirement does not extend to the other. Without valid approval for each unmet requirement, the first-Stable gate remains blocked unless the default path is satisfied.

If Harness proof on the exact published RC is unavailable under this path, the Harness MUST prove consumption of the exact Stable candidate as a Composer dependency from a controlled local or temporary path, VCS, or isolated artifact source permitted by the applicable contracts. The evidence MUST identify the package, target, candidate SHA, and installed content and prove that the consumed content matches the candidate. This proves candidate consumption only. External Publication, published-RC installation, and Published Artifact Verification require their own evidence. A failed mandatory Harness or an unresolved material defect still blocks acceptance.

#### 14.1.3 Exception Record and Evidence

The exception MUST be expressly approved by the Owner and recorded in an `ACTIVE` library-local Decision Record under `docs/decisions/`, with a matching entry in `docs/decisions/DECISIONS_INDEX.md` under `std-decision-governance`. The approval and record MUST be in place before Final Release Acceptance and before release qualification. The record MUST identify:

- the package identity and Artifact Scope, and the exact first-Stable target;
- the specific RC or Host requirements excepted and the validation actually completed;
- the reason, the Lead's impact and residual-risk assessment, the available evidence, and the candidate verification plan with any applicable constraints or mitigations;
- the Owner approval date and verifiable approval reference.

Use authorized, access-controlled evidence locations for sensitive Host details. A pending request, a `PROPOSED` decision, or a blanket, undocumented, or mismatched approval cannot open this path.

Release Acceptance and qualification evidence MUST tie the applicable approved record to the exact candidate state for the target. Use an immutable reference to the approved record and the exact candidate SHA in the evidence. The SHA need not appear in a record committed within that same tree. Changes to the target, candidate content, exception scope, or material risk require reassessment, repetition of affected verification and review, and renewed Owner approval where the approved basis is affected. Approval is specific to the documented case and does not carry forward automatically.

Release review MUST identify each unmet default step, the approved exception that covers it, and the evidence for the remaining requirements. An excepted step MUST NOT be reported as passed or treated as inherently inapplicable. The Lead MAY record `PASS` for compliance with the exception path only after all remaining requirements are met. Release review verdicts remain governed by `std-ai-collaboration-workflow` §13.1.2.

#### 14.1.4 Controls Required in Both Paths

Both paths MUST pass the Consumer Verification Harness under [Testing Standard §3.4](../testing/TESTING_STANDARD.md#34-consumer-verification-harness) and [CI Workflow Standard §2.2](CI_WORKFLOW_STANDARD.md#22-consumer-verification-harness-gate). Full Applicable CI, including the applicable Harness gate, MUST run on the final candidate. Evidence from an RC or earlier candidate MAY support review only where its continued validity for the exact state is demonstrated under the applicable evidence contract. Required verification on the final candidate still applies.

Mandatory Pre-Release Strict Lead Acceptance under `std-ai-collaboration-workflow` §13.1.2 and all applicable engineering quality gates MUST pass, including applicable Unit, Integration, System/E2E, Regression, Security, and dependency verification. A material `FAIL` or `UNRESOLVED` remains blocking. CI or Harness success alone does not authorize release.

Both paths remain subject to exact-target qualification under [CI Workflow Standard §§2.4–2.6](CI_WORKFLOW_STANDARD.md#24-release-sha-qualification) and release controls in Section 20.1. Full Applicable CI and Release Artifact Verification MUST pass on the exact candidate main SHA before Owner Release Authorization and any Tag, Release, or Publish action. Published Artifact Verification and applicable live Publication State reconciliation follow external Publication. The exception approval, Strict Lead Acceptance, and Owner Release Authorization are separate requirements.

Applicable public-contract, deprecation and retirement (including `DEPRECATIONS.md`), SemVer, and dependency stability requirements remain binding. This exception covers only first-Stable eligibility for the library itself. It grants no permission to use a pre-release dependency in a Stable consumer where the applicable dependency contract prohibits it. A library may use this path only when the canonical contract applicable to that library permits it. The exception cannot change a Standard's applicability, authorize an Adoption or Upgrade, alter pins or a manifest, or resolve structural invalidity.

### 14.2 Development State
When neither a SemVer RC nor a Stable release has been published, `SECURITY.md` MAY state that there is currently no supported Stable release line. When a SemVer RC has been published, Section 14.3 applies; an RC does not become a Stable release or a supported Stable line.

### 14.3 Published SemVer RC State
An exact SemVer RC target, such as `1.0.0-rc.1`, MAY be selected and prepared before Publication. A **Published SemVer RC State** exists only after that exact version is Published and available to external consumers through the approved distribution channel. Live default-branch `SECURITY.md` MUST describe actual publication/support state and MUST NOT present the target Stable version as already published. In a release artifact, `SECURITY.md` MAY state that it represents Release Candidate `X` and does not establish a Stable support line without claiming that `X` has already been published. An RC artifact MUST NOT identify `X-1` as the current RC identity of that artifact. A SemVer RC does not establish a new supported Stable line; existing published Stable support lines remain governed by the actual support policy.

### 14.4 First-Stable Gate-Specific Preparation State
General exact-target Release Preparation under Section 8.1.1 MAY begin before the first-Stable gate is satisfied, including preparation of the exact Stable candidate for verification. Once the requirements of the selected path in Sections 14.1.1–14.1.3 are met, the prepared target MAY be described as having satisfied first-Stable eligibility. Report qualification and authorization separately under Sections 14.1.4 and 20.1. This gate-specific state MUST NOT be called a Release Candidate. Until the Stable version satisfies the Publication State Definition, release-facing files and PR metadata MUST NOT claim that the target is Published, externally resolvable or installable as a Published package/version, or that it establishes Stable support. A tag, GitHub Release, or other visible source surface MAY be reported when it actually exists. Publication requires the external evidence defined above. A planned date MUST NOT be presented as an actual published release date. The target Stable support wording MUST be synchronized with the actual policy before publication.

### 14.5 Published Stable State
After the Stable tag is published and the version is available to consumers through the approved distribution channel, the Security Policy enters the Published Stable State. It MUST be prepared for the actual supported release lines.

For one supported major line, a `SECURITY.md` section MAY use:

```markdown
## Supported Versions

The actively supported release line is `{SUPPORTED_MAJOR_LINE}`.

Security fixes are provided in the latest stable release within the supported `{SUPPORTED_MAJOR_LINE}` line. Users should upgrade to the latest available `{SUPPORTED_MAJOR_LINE}` version before reporting a vulnerability.
```

A table MAY be used when it accurately represents the published support policy:

```markdown
| Version | Supported |
|---------|-----------|
| `{SUPPORTED_MAJOR_LINE}` | Yes |
| Older lines | No |
```

In the Published Stable State, `SECURITY.md` MUST:

- describe only release lines that are currently supported by published stable releases
- identify every supported major line when more than one major line is actively supported
- direct users to the latest stable release within each supported line before reporting a vulnerability
- remove any wording that describes the package as unreleased, pre-release, or awaiting publication
- remain synchronized with the actual support policy whenever a supported line is added, replaced, or retired

A future release line MUST NOT be presented as actively supported before its first Stable Tag exists. A SemVer RC or any Release Preparation state, including first-Stable gate-specific preparation, does not establish Stable support.

Publishing a patch or minor release within an already supported major line does not require a Security Policy change unless the file names an exact version, changes the support scope, or otherwise becomes inaccurate.

If support for a release line is withdrawn, `SECURITY.md` MUST be updated as part of the same owner-approved release or governance change that withdraws support.

### 14.6 Major Version Preservation

This rule applies to packages that have published a Stable release; it does not extend the first-Stable release gate in Section 14.1. A Stable package MUST preserve its current Major version whenever the intended change can reasonably be delivered compatibly.

A Major release MUST be used only for a genuine breaking public-contract change that cannot reasonably be contained through an additive API, a deprecation cycle, a compatibility adapter or shim, a migration path, or a staged replacement.

Internal refactors, implementation cleanup, documentation or presentation changes, naming normalization by itself, and compatible additive capabilities MUST NOT justify a Major version bump.

When a breaking public-contract change makes a Major release unavoidable, its compatibility impact and migration path MUST be explicit, reviewed decisions. The Major bump MUST NOT follow automatically from a modernization, refactor, cleanup, or naming change.

Compatibility classification, staged replacement, deprecation, and retirement of a Published Public/Consumer Contract are owned by `std-public-contract-evolution`, when that Standard applies to the Scope. This section remains the owner of Major version selection and does not duplicate that lifecycle.

## 15. CHANGELOG Presentation Standard

The CHANGELOG MUST follow these rules:
* Keep a Changelog is the baseline for CHANGELOG structure and change presentation. The presence and allocation semantics for `[Unreleased]` and exact-target Release Preparation are owned by this Standard and govern where they differ from external guidance, including whether an empty `[Unreleased]` section is present.
* Semantic Versioning.
* `[Unreleased]` represents changes shown in the CHANGELOG that have not yet been allocated to an exact release target. It MUST be present when such represented unallocated changes exist. When none exist, it is not required and MAY be omitted; retaining an empty section MUST NOT make an otherwise-compliant implementation non-compliant.
* For changes represented in the CHANGELOG, allocation determines placement: an unallocated change MUST appear under `[Unreleased]`; a represented change explicitly allocated to an exact target MUST NOT remain under `[Unreleased]` as though it were unallocated. During Release Preparation before Publication, an allocated change MAY be represented under its exact target section. Allocation alone does not require that a change be represented in the CHANGELOG or that an exact-target section be present.
* Before a SHA is release-qualified for exact target `X`, all implemented changes allocated to `X` MUST be moved out of `[Unreleased]` and the exact `X` section MUST be present in the release artifact. Those target changes MUST NOT remain only under `[Unreleased]` at the SHA receiving the tag. `[Unreleased]` remains at the top for implemented changes not allocated to `X`; it MAY be empty or omitted when none are represented.
* A target section does not establish Publication. An unpublished target section MUST NOT be assigned an actual release date or linked to a tag or Release that does not exist.
* The live default-branch CHANGELOG for every Published release MUST record its exact version and actual release date. The date becomes mandatory there when the exact target is externally Published and its actual date is established by the approved distribution/release record. Before then, including release preparation and tagging before distribution publication, no planned or inferred date may be presented as actual. The immutable release artifact MUST already contain its valid undated target section before Publication and MAY remain undated after publication; do not rewrite the tag to add a later live fact.
* Release links MUST be at the bottom of the file.
* Do not list features that do not exist.
* Do not describe future changes as already implemented.
* A target section MUST use its exact SemVer version, including any pre-release identifier such as `1.0.0-rc.1`.
* When `[Unreleased]` is used, its compare link MUST use the latest actual Published release as its base when one exists. If there is no prior Published release, do not invent a prior version or tag to complete the link template.
* After Publication is externally established and the actual date is known, the live/default-branch CHANGELOG or release history MUST be updated to record the exact published version and actual publication date, with links to an existing tag/Release where applicable. This is a live-state update: the immutable tagged artifact MAY retain its valid undated target section from before Publication and MUST NOT be rewritten or retagged solely to add these later publication facts.

### Preparation Template:
```markdown
## [Unreleased]

## [{EXACT_RELEASE_TARGET}]
```

The undated exact-target section is required in the release-qualified tree. When no represented changes remain unallocated, `[Unreleased]` MAY be omitted; retaining it as an empty section MUST NOT make an otherwise-compliant CHANGELOG non-compliant. Do not put a date on an unpublished target section. After publication, the live default branch MUST add the actual date and links once established; an immutable tag remains valid with its undated section and need not be rewritten to acquire a later publication fact.

### Published Release Template:
```markdown
## [{EXACT_RELEASE_TARGET}] - {ACTUAL_RELEASE_DATE}
```

### Links Template:
```markdown
[Unreleased]: https://github.com/Maatify/{REPOSITORY_SLUG}/compare/v{LATEST_ACTUAL_PUBLISHED_VERSION}...HEAD
[{PUBLISHED_RELEASE_VERSION}]: https://github.com/Maatify/{REPOSITORY_SLUG}/releases/tag/v{PUBLISHED_RELEASE_VERSION}
```

Include the `[Unreleased]` compare link only when `[Unreleased]` is present and an actual Published base exists. Include a version Release link only after that exact release/tag exists. A first-release preparation has no previous published base or release link to invent. After Publication, links in the live/default-branch CHANGELOG MUST represent actual published-release truth; this does not require rewriting an immutable tagged artifact to add links that became true only later.

## 16. CONTRIBUTING Presentation

The `CONTRIBUTING.md` file MUST clarify:
* Package identity.
* Package boundaries.
* Ways to contribute.
* Local verification commands.
* Test and Integration requirements.
* Pull Request expectations.
* Architecture discussion requirements.
* Security-reporting route.
* `composer.lock` policy (according to the library's nature).

Identity badges are NOT forced inside this file in the current version of the Standard.
The file MUST NOT preemptively enforce a GitHub Issue for every change unless this is an approved repository policy.

## 17. Canonical Author Block

The following Author block is approved for Maatify PHP libraries:

```markdown
## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)
```

### Formatting Rules
* Use `<br>` for mandatory line breaks.
* Do not use hidden trailing spaces as a line break mechanism.
* Do not alter the name.
* Do not alter the GitHub username.
* Do not alter the title.
* Do not alter the Maatify link without explicit permission.

If the library does not use Emoji in headings, it is acceptable to use:
```markdown
## Author
```
while keeping the content of the section unchanged.

## 18. Canonical Maatify Footer

The absolutely final element in the README MUST be:

```markdown
---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
```

No text may appear after the closing `</div>`.
Do not replace `Modern PHP Libraries` with any other generic wording within this Standard.

## 19. Package and Repository Metadata

This Standard covers presentation-facing consistency only.

### Composer Metadata

The canonical construction and validation rules for Composer metadata are defined by [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).

This section governs only presentation-facing consistency between Composer, README, Packagist, and GitHub metadata.

* `description` MUST remain accurate and consistent with the public package presentation.
* `keywords` MUST remain relevant and MUST NOT contradict GitHub Topics.
* `homepage` MUST point to the current repository.
* Author metadata MUST match the approved Maatify identity.
* License metadata MUST match `LICENSE`.

Dependency declarations, constraints, autoloading, scripts, configuration, stability, and lock-file policy are governed exclusively by [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).

### GitHub Metadata
The following MUST be reviewed when they belong to the actual artifact/repository being presented:
* Repository description.
* Website.
* Topics.
* Releases visibility.
* Packages visibility (when applicable).
* Deployments visibility (only when a real deployment lifecycle exists).

Releases MUST be shown only when GitHub Releases are an actual consumer-facing and managed channel. Packages MUST be shown only when GitHub Packages is actually used; Packagist publication does not imply GitHub Packages. Deployments MUST be shown only when a real deployment lifecycle exists. Empty or misleading GitHub home-page sections MUST NOT be enabled merely because GitHub supports them. Topics or descriptions MUST NOT claim features that do not exist.

For an embedded Base Artifact, GitHub Description, Topics, Releases, Deployments, and Packages remain Host/repository surfaces unless that artifact itself is the represented repository. The Host MUST NOT claim the Base Artifact's distribution state.

## 20. Pull Request Presentation Metadata

Any PR performing SemVer Release Preparation for an exact target MUST contain:
* A Title identifying the exact SemVer target, actual preparation state, and actual Publication State.
* A Body describing the actual changes.
* A Scope confirmation.
* A release-control statement confirming that no Merge, Tag, Release, or distribution publication occurs without owner approval.
* Accurate wording that distinguishes the exact target, actual preparation state, and actual Publication State. It MUST NOT claim that a target is Published before the Publication State Definition is met.

PR metadata MUST NOT describe a prepared target version as already published. At the first-Stable boundary under Section 14.1, the PR body MUST identify the exact Stable target, state that it is unpublished, and state that publication requires separate Owner Release Authorization. It MUST identify the selected path and the actual RC and Host validation status, including no published RC or zero validated Hosts where applicable.

* Default path: the verifiable references to the exact published RC, Harness, and both independent Host validations identify the path and document those stages.
* Exception path: identify the path explicitly and reference the approved local decision and the evidence for the requirements that were satisfied.

Reference the applicable Harness, Lead review, and CI evidence as it becomes available. Existing PR reviews and linked checks can provide those review and CI references. Where details are sensitive, keep the verifiable evidence in an authorized location and keep any public summary non-disclosing. Version and date wording MUST match what is known. An unknown date MUST NOT be represented as a committed release date.

If the PR scope changes, the Title and Body MUST be updated to remain accurate.

### 20.1 Release Integrity and Curated Release Notes

Tag and Release presentation MUST consume the exact SHA that became release-qualified only after Full Applicable CI and Release Artifact Verification both passed on that same SHA under the [CI Workflow Standard](CI_WORKFLOW_STANDARD.md). The required release path is:

```text
approved integration
→ Owner merge to main
→ actual main SHA
→ candidate release SHA for exact target X (the same commit SHA)
→ Full Applicable CI on the same SHA
→ Release Artifact Verification on the same SHA
→ that same SHA becomes the release-qualified SHA
→ explicit Owner Release Authorization for that exact target and SHA
→ Tag and Release targeting that exact SHA
→ external exact-version publication verification
→ two independent post-publication obligations:
   ├── Published Artifact Verification
   └── live/default-branch publication-state freshness
       when a static current/live claim became stale
```

Publication State and Published Artifact Verification are separate facts. The current Publication State is determined only by the Section 14 definition: whether the exact package identity and version are currently externally resolvable and installable through an approved Composer distribution source. Published Artifact Verification (PAV) records integrity evidence about the published artifact. PAV PASS or FAIL alone MUST NOT establish, erase, reverse, or redefine Publication State. PAV may observe external availability evidence, but it does not own the result.

When PAV fails on artifact integrity while current external evidence still proves exact `X` resolvable and installable, `X` remains Published and publication integrity is NOT PASS. When PAV exposes credible evidence that exact `X` no longer resolves or installs, or otherwise contradicts the current Publication State, canonical Publication-State re-verification under this Section MUST be triggered. The operational determination is `RE-VERIFY` until that canonical check establishes the actual current state; this is a pending verification condition, not a second Publication-State authority or an inference that X is Published or Unpublished. The live/default-branch presentation MUST represent the state established by canonical re-verification. A PAV failure alone MUST NOT suppress synchronization when a static live claim is stale; when no static live claim became stale, no content synchronization is required solely for freshness.

If the live default-branch Package Reference states a publication status, it MUST report the verified status of the exact version it names. After publication, update any stale preparation status on the default branch. In an immutable release artifact, a preparation status is allowed when expressly dated or scoped to qualification.

Historical release facts, including that `X` was actually published on a particular date, remain historical facts if current availability later changes. A later Publication-State change MUST NOT rewrite release history to imply `X` was never released or alter its immutable tag. A tracked artifact correction requires a new exact release identity.

Presentation MUST NOT make a tag or Release appear qualified by CI that ran on an older SHA. A later commit intended for the same release requires new qualification. The tag/release surface is a consumer-facing projection of the qualified commit; it does not own CI evidence or create release authorization.

Release Artifact Verification MUST prove that the target README identity/install target, CHANGELOG allocation and `[Unreleased]` boundary, SECURITY lifecycle/support wording, Composer manifest, required distributed consumer/release files, and actual archive/distribution behavior are consistent on the release-qualified SHA. It MUST rule out previous-release-as-current artifact claims and false pre-publication claims. When §8.4 applies to `DEPRECATIONS.md`, the required file set includes that registry, and Release Artifact Verification MUST check that the documentation link resolves in the exact target artifact. The registry's publication-safety criteria and verification of its distributed contents are owned by `std-public-contract-evolution` §11.2. Deterministic structural checks MAY be automated; claims whose truth depends on context require semantic review under the Documentation Lifecycle Standard. CI execution and local command parity are owned by `CI_WORKFLOW_STANDARD.md`.

After external publication, Published Artifact Verification MUST install the exact version into a clean external Composer root and prove that Composer resolves that exact version, the installed package corresponds to the intended tagged release, required files are present, and its release-facing content is self-consistent with that version and manifest. A passing runtime Consumer Verification Harness does not substitute for this distribution-identity/content evidence.

The two obligations triggered by external Publication are logically independent: PAV evaluates artifact integrity, while live/default-branch freshness evaluates whether publication made a static current/live claim stale. A PAV failure MUST NOT delay or suppress synchronization required by a stale live claim. If no static current/live claim became stale, no content synchronization commit is required solely for freshness, regardless of the PAV result.

Once an exact version is externally Published, its tag and immutable release identity MUST NOT be moved or silently replaced. If distributed tracked contents require correction, publish a new exact version under the applicable package lifecycle. Post-publication synchronization is limited to live default-branch/repository facts and MUST NOT repair the immutable artifact.

Curated GitHub Release Notes are an independent consumer-facing artifact. When applicable they MUST contain the exact version, a concise theme when material, a curated Overview, only the relevant Added/Changed/Fixed/Removed/Deprecated material, important runtime guarantees or links, compatibility impact, and upgrade/migration notes when needed. Empty mandatory headings are not required.

Generated GitHub Release Notes are optional supplementary material and MUST NOT replace curated notes by default. CHANGELOG and PR bodies remain separate artifacts, and Phase/Work Unit/branch/executor history MUST NOT become the primary consumer-facing Release Notes content.

## 21. Visual Review Rules

Before presentation is considered ready, the following MUST be verified:
1. Review the GitHub-rendered README.
2. Ensure badges are not clustered messily.
3. Verify badge size consistency.
4. Check all links.
5. Verify the Composer name and Repository slug in every Badge.
6. Ensure the logo renders correctly.
7. Verify the heading hierarchy.
8. Verify the Author line breaks (`<br>`).
9. Verify the Footer is the very last element.
10. Ensure a final newline exists.
11. Ensure there are no names or links belonging to other libraries.
12. Ensure no Runtime contracts were deleted during the visual polish.

## 22. Anti-Copy and Repository Isolation Rules

When using another library as a visual reference, the implementer MUST NOT copy:
* Package names.
* Repository URLs.
* Composer names.
* Badge labels.
* Security links.
* Author variants.
* Runtime claims.
* Dependencies.
* Database support.
* Release version.
* Release date.

An explicit search for reference repository names MUST be conducted before submission.

## 23. Release Presentation and First Stable Readiness Checklist

* [ ] The first-Stable gate applies to the first Stable target only.
* [ ] Default path, when used: the exact published RC, Harness on that RC, and two independent real Host validations on the same RC are evidenced under Section 14.1.1.
* [ ] Exception path, when used: a valid indexed local Owner decision covers each unmet RC or Host requirement for the exact target. Remaining requirements are satisfied and recorded under Sections 14.1.2–14.1.3.
* [ ] Harness evidence meets the applicable Testing and CI contracts. When required by Section 14.1.2, controlled Composer consumption proves the exact Stable candidate with no claim of Publication or Published Artifact Verification.
* [ ] Both first-Stable paths require Strict Lead Acceptance, Full Applicable CI and Release Artifact Verification on the exact candidate main SHA, and separate Owner Release Authorization under Section 14.1.4.
* [ ] Release Target, Release Preparation, Publication State, Release Qualification, and Stable Support are represented as separate facts.
* [ ] The release-qualified SHA already presents the exact target as its immutable artifact identity without claiming pre-publication availability.
* [ ] The immutable artifact README fixes its exact artifact identity and time-scopes every static Publication State, including an unpublished-at-qualification or prior-version fact; it contains no unqualified static Publication/current/latest claim that could become false after the target is published. Any dynamic live-state display satisfies Section 8.1.2 and cannot replace or falsify artifact identity.
* [ ] Release Artifact Verification passes on the exact SHA before Tag/Release authorization; post-publication Published Artifact Verification confirms exact Composer-distributed identity/content independently of runtime Consumer Verification. PAV alone does not establish Publication State: an integrity failure with current availability still proven leaves the version Published with integrity NOT PASS, while credible contradictory availability evidence triggers canonical Publication-State re-verification before the live presentation is updated.
* [ ] First-Stable gate-specific preparation reflects the actual status of the selected path under Section 14.4 and is described as preparation for a Stable target.
* [ ] Before the Stable tag and release exist, README, CHANGELOG, SECURITY, badges, and PR metadata do not claim a published or supported Stable version.
* [ ] README header and Maatify identity are present.
* [ ] `docs/guides/USAGE_GUIDE.md`, root `examples/`, Package Reference, and Quick Usage links are present for every applicable reusable Package/Base Artifact.
* [ ] The `llms.txt` verification checklist in Section 10.4 passes for every applicable reusable Package/Base Artifact.
* [ ] The applicable retirement registry is discoverable through the artifact's existing documentation. Where Composer delivery applies, the exact release artifact contains the registry and its contents and links pass the publication-safety checks owned by `std-public-contract-evolution` §11.2 (Sections 8.4 and 20.1).
* [ ] Usage Guide fit/requirements/non-goals, capability map, walkthrough boundaries, and Public Contract links are accurate; no fixed example count is assumed.
* [ ] Every applicable reusable Package/Base Artifact README contains a clearly labeled `Public Runtime API` overview proportionate to the artifact's actual capabilities; it is not treated as a conditional section.
* [ ] The `Public Runtime API` overview is accurate, consumer-facing, and linked to the canonical Package Reference for the complete Public Runtime API inventory and stable public/runtime/behavioral contract without duplicating the full reference.
* [ ] Required badges exist and point to the current package.
* [ ] Badge selection matches the actual publication-state matrix, uses repository-local PHP/License truth, contains no semantic duplicates, and does not use `for-the-badge` as the README default.
* [ ] Packagist or other registry badges appear only when the artifact is actually published and resolvable through that channel.
* [ ] Host repositories do not present themselves as embedded Base Artifacts.
* [ ] README sections match the package's actual behavior.
* [ ] Critical runtime contracts remain documented.
* [ ] CODE_OF_CONDUCT identity badges are correct.
* [ ] SECURITY identity badges are correct.
* [ ] SECURITY supported release line is correct.
* [ ] In the CHANGELOG, represented unallocated changes appear under `[Unreleased]`; a represented change allocated to an exact target does not remain there as unallocated. An empty `[Unreleased]` section is allowed.
* [ ] Allocated changes represented during Release Preparation may appear under their exact target section; allocation alone does not require a CHANGELOG entry or target section. An unpublished target section has no actual release date or nonexistent Release/Tag link, and its presence does not establish Publication.
* [ ] For every externally Published release, the live/default-branch CHANGELOG or release history records the exact published version, actual publication date, and applicable existing tag/Release links. An immutable tagged artifact MAY retain its valid undated exact-target section and is not stale or non-compliant solely because this later date is absent; first-release preparation invents no previous release/version/tag.
* [ ] `[Unreleased]` compare links use an actual Published base when one exists, and Release links target existing releases in the current repository.
* [ ] Tag and Release target the exact CI release-qualified SHA, and curated Release Notes are distinct from CHANGELOG and PR metadata.
* [ ] CONTRIBUTING reflects actual local verification.
* [ ] Author block uses visible `<br>` line breaks.
* [ ] The canonical PHP-library footer is the final README element.
* [ ] Composer and GitHub metadata are accurate.
* [ ] PR title and body identify the exact target, actual preparation state, and actual Publication State. At the first-Stable boundary, the PR records the path, actual RC and Host evidence, and applicable references under Section 20.
* [ ] No foreign package names or URLs remain.
* [ ] No `composer.lock` was introduced when the library does not track it.
* [ ] CI Gate is successful.
* [ ] No Merge, Tag, Release, or Packagist action occurred without owner approval.

## 24. Non-Goals

This Standard explicitly does NOT force:
* The exact same Runtime sections on every library.
* The exact same number of examples.
* The exact same number of Documentation links.
* The exact literal Emoji set.
* `for-the-badge` as the default README badge style (the intentional governance-document exceptions remain governed by Section 13).
* Packagist badges on a library not utilizing Packagist.
* Database or framework claims.
* Empty sections.
* Verbatim README copying from another library.
* Runtime changes during presentation work.
* `llms-full.txt`, generated full-context files, or a README badge/link requirement for `llms.txt`.

## 25. License Presentation

For new Maatify-owned PHP libraries, the default is the Maatify Proprietary License with written authorization available at no charge. A different license requires an explicit Owner decision for that repository. Existing approved licenses remain in force until an explicit repository license decision changes them.

For libraries using the Maatify Proprietary License model, the root `LICENSE` MUST contain the approved terms, including:

* The copyright holder’s confirmed legal name, applicable copyright year, and proprietary status. Public source visibility does not grant an open-source license.
* The requirement to obtain written authorization before use, the applicable restrictions, and any permitted exceptions in the approved terms.
* The no-charge license request process and the licensing contact.
* The license-holder register and the use of the designated contact details for license administration, security advisories, and critical update notices.
* The approved warranty disclaimer and limitation of liability.

Use **Maatify Ltd.** for the copyright holder and licensor wherever Maatify Ltd. holds the rights. Use that legal name consistently in the license terms and in any README text that identifies the holder or licensor. Confirm ownership separately from the repository’s GitHub organization. Apply any correction on the default branch for the next release and preserve the LICENSE distributed with each published version.

For libraries using the Maatify Proprietary License model, the README MUST link the `Proprietary` license badge to the relative path `LICENSE`. Its short `License` section MUST state that written authorization is required before use and available free of charge, provide the request contact, explain what contact details to provide and why, and link to `LICENSE` for the terms. Composer metadata follows [the Composer Package Standard](COMPOSER_PACKAGE_STANDARD.md#104-license).

Example README section:

```markdown
## License

This package is proprietary. Obtain written authorization before use by
requesting a free license from [support@maatify.dev](mailto:support@maatify.dev).
Include your organization name, if applicable, and an email address for the person who should
receive security advisories and critical update notices. Maatify Ltd. uses those
details to administer licenses and send those notices. See [LICENSE](LICENSE)
for the terms.
```

## Version History

### `7.0.0`

- Issue #209 (`DEC-056`): require discovery and release-artifact inclusion of the registry when required by `std-public-contract-evolution`. A previously compliant artifact can lack the registry link or omit the file. `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major).
- Owner-approved Mutable Candidate `7.0.0` from Frozen Baseline `6.0.0`, artifact blob `778a1706cdd1a079ca284c3c266250e0fd4a7cce` at completed VALID Upgrade Adoption Commit `1f560795397f24bddcc3d09b982ff93a77f7154e`. The cumulative delta includes the later §14.6 pointer (Patch class). [Owner Version Finalization on 2026-11-24](https://github.com/Maatify-ltd/php-engineering-standards/pull/210#issuecomment-7127402843).

- Issue #211 (`DEC-057`): add a bounded, Owner-authorized first-Stable eligibility path for the RC publication and Real Host requirements while retaining the default path and mandatory quality and distribution gates. `NORMATIVE / BACKWARD_COMPATIBLE_CONTRACT_CHANGE` (Minor) for this change alone. The Owner finalized the cumulative Mutable Candidate at `7.0.0` from the same Frozen Baseline `6.0.0` because the earlier retirement-registry requirement has Major impact. The candidate remains mutable pending a completed VALID Adoption or Upgrade. [Owner approval on 2026-11-24](https://github.com/Maatify-ltd/php-engineering-standards/pull/212#issuecomment-7128818272).

### `6.0.0`

- Add a one-sentence pointer in §14.6 to `std-public-contract-evolution` for the contract-evolution lifecycle. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class): no obligation or permission changes; absorbed in the existing Mutable Candidate calculated from the Frozen Version Baseline. No Version Finalization, Freeze, release, or Adoption is claimed.

- `DEC-051` and related presentation updates: require the current Ecosystem badge destination, clarify publication status in the live Package Reference, and require confirmed legal identity in license presentation. The updated badge destination is a breaking presentation requirement. The highest cumulative impact remains Major from Frozen Baseline `5.0.1`.

- `DEC-050`: `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major). Establish the proprietary no-charge authorization default for new Maatify-owned PHP libraries and require the corresponding LICENSE and README content when this model applies. Existing approved licenses remain in force.
- Mutable Candidate `6.0.0`, calculated from Frozen Version Baseline `5.0.1`, artifact blob `cc936b98c6d591b3750e63b56162701ad0764d03` at completed VALID Adoption Commit `c5d70cbe005fc26c38a7218967efe991194e76d6` (consumer identity withheld per `DEC-042`).

### `5.0.1`

- `CLARIFICATION / NO_CONTRACT_CHANGE`: update the canonical README header template to select the Maatify logo for the reader's color scheme while preserving the rest of the header contract.

### `5.0.0`

- Frozen baseline `4.0.0`, artifact blob `25d4d75d7f23934b3e204a28f1dbc7d06ca63c04` (a consumer repository's completed VALID adoption, identity withheld per `DEC-042`; upstream commit `5f872d3ef7da847cba3f82fee124a19c22f1c5c4`).
- Issue #168 (`DEC-042`): consumer-identity neutralization of the preceding frozen-baseline evidence line only; `EDITORIAL / NO_CONTRACT_CHANGE` (Patch), absorbed in the existing Mutable Candidate calculated from the Frozen Version Baseline; no additional bump, freeze, release, or adoption. The artifact blob and commit hashes are unchanged.
- `NORMATIVE / BREAKING_CONTRACT_CHANGE`: require exact-target release-facing self-consistency before qualification, separate immutable Release Artifact identity from live Publication State, require target allocation in CHANGELOG before tagging, and prohibit retagging a published version as remediation; existing package release workflows may otherwise qualify a stale artifact.
