# Maatify Composer Package Standard

**Maatify Standalone PHP Composer Library Standard**

## Standard Metadata

- **Standard ID:** `std-composer-package`
- **Standard Version:** `8.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`

This document defines the canonical Composer manifest contract represented by `composer.json` for standalone, reusable PHP libraries in the Maatify ecosystem.

It MUST be read together with:

- [PACKAGE_BUILDING_STANDARD.md](PACKAGE_BUILDING_STANDARD.md) for runtime architecture and package structure.
- [CI_WORKFLOW_STANDARD.md](CI_WORKFLOW_STANDARD.md) for automated Composer verification.
- [LIBRARY_PRESENTATION_STANDARD.md](LIBRARY_PRESENTATION_STANDARD.md) for README, badges, governance identity, and presentation-facing consistency.

---

## 1. Normative Language

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in RFC 2119.

- **MUST / MUST NOT / REQUIRED**: Binding rules.
- **SHOULD / SHOULD NOT**: The default expected behavior. A deviation requires a documented technical reason.
- **MAY / OPTIONAL**: A permitted choice that depends on the library's actual requirements.

A rule in this Standard does not become optional merely because Composer itself permits another shape. This document defines the stricter Maatify reusable-library baseline.

---

## 2. Scope and Ownership

This Standard governs the construction and review of `composer.json` for Maatify standalone PHP libraries.

It owns rules for:

- Package identity.
- Package metadata.
- Top-level field ordering.
- PHP and extension requirements.
- Runtime and development dependencies.
- Dependency constraints.
- Production and development autoloading.
- Composer scripts.
- Composer configuration.
- Dependency policy and security-sensitive configuration.
- Stability policy.
- Optional package-link fields.
- Custom repository restrictions.
- Package archive safety.
- Presence and identity integrity of the Composer-distributed artifact, including contractually required consumer/release documentation.
- Reusable-library lock-file policy.
- Composer validation and review.

It does **not** govern:

- Runtime architecture.
- Public PHP Runtime API inventory.
- Domain behavior and package behavioral guarantees.
- Exception semantics beyond Composer-facing declaration consistency.
- Package architectural boundaries and non-goals.
- DTO, repository, command, or service design.
- Database schema or SQL behavior.
- Exception hierarchy.
- Test architecture.
- GitHub Actions implementation.
- README visual layout.
- Badges.
- Governance-document formatting.
- Release publication.

### 2.1 Relationship to Other Standards

- `PACKAGE_BUILDING_STANDARD.md` governs package boundaries, namespaces, source structure, runtime design, schema, exceptions, DTOs, repositories, and tests.
- `COMPOSER_PACKAGE_STANDARD.md` governs `composer.json` as the canonical Composer manifest contract for package metadata, dependencies, autoloading, scripts, configuration, and distribution.
- `CI_WORKFLOW_STANDARD.md` governs how Composer contracts are verified through strict validation, dependency resolution, platform checks, audit, and quality gates.
- `LIBRARY_PRESENTATION_STANDARD.md` governs public presentation and consistency between Composer metadata, README, Packagist, and GitHub.

The public Runtime API inventory, domain behavior, package behavioral guarantees, exception semantics, and package architectural boundaries remain owned by `PACKAGE_BUILDING_STANDARD.md` and the canonical root Package Reference. `composer.json` does not become their source of truth.

The definition and evidence for a package/version being Published are owned by [`LIBRARY_PRESENTATION_STANDARD.md` Section 14](LIBRARY_PRESENTATION_STANDARD.md#14-first-stable-release-lifecycle-and-security-presentation-states). This Standard consumes that publication state when applying package identity rules and MUST NOT establish a conflicting publication source or definition.

No Standard SHOULD duplicate the detailed rules owned by another Standard. Cross-references MUST be used instead.

---

## 3. Applicability

This version applies exclusively to:

> **Maatify standalone reusable PHP Composer libraries**

It does not automatically apply to:

- Applications.
- Framework host projects.
- Composer plugins.
- Metapackages.
- Project templates.
- PHP extensions written in C.
- JavaScript, Rust, or other language ecosystems.

Those package types require a separate approved Standard or a formal Profile manifest.

For an extractable Base Module, this Standard applies to the Module's Artifact Root even while it is located inside a Host repository. The Artifact Root MUST contain its own `composer.json`; a Host root `composer.json` MAY provide in-project autoloading but MUST NOT replace the Artifact Root's Composer contract.

### 3.1 Root-Package Context

Some Composer fields are root-only, including `require-dev`, `autoload-dev`, `repositories`, `config`, `scripts`, `minimum-stability`, and `prefer-stable`.

Rules for those fields govern the library repository while it is being developed, tested, or released as the root package. They MUST NOT be presented as runtime behavior imposed on consumers when the library is installed as a dependency.

---

## 4. Core Composer Contract Principles

*Note: Composer technically allows other forms for many of these configurations, but Maatify adopts a stricter reusable-package contract to ensure consistency and reliability across the ecosystem.*

1. `composer.json` is part of the package's public Composer manifest contract, not an internal installation note.
2. Every directly used mandatory runtime dependency MUST be declared directly in `require`. Omission from `require` is permitted only for an eligible Optional Runtime Capability Dependency under §15.4, with explicit consumer-facing disclosure.
3. A package MUST NOT rely on a transitive dependency as though it were direct.
4. Development tools MUST NOT be placed in `require`.
5. Mandatory runtime dependencies MUST NOT be placed only in `require-dev`. Repository verification of an eligible optional prerequisite follows §16 and MUST NOT replace its consumer-facing disclosure.
6. Metadata MUST be accurate, current, and non-misleading.
7. The PHP constraint defines public installation eligibility as specified in §15.1.
8. The production autoload mapping is part of the public runtime contract.
9. Composer scripts MUST map to real, maintained commands.
10. Published versions MUST come from VCS tags; as a **Maatify Internal Policy**, the `version` field MUST NOT be committed.
11. As a **Maatify Internal Policy**, reusable Maatify libraries MUST NOT commit `composer.lock`.
12. A release-ready package MUST pass `composer validate --strict`.
13. Package metadata MUST match the repository, runtime, documentation, and CI claims.
14. Composer configuration MUST NOT hide unsupported platform assumptions.
15. Installing the library as a dependency MUST NOT require consumer-side mutations or manual setup scripts.
16. Empty, unused, speculative, or copied fields MUST be removed.
17. `composer.json` MUST remain valid JSON without comments or trailing commas.

---

## 5. Canonical Placeholders

Templates in this Standard use the following placeholders:

- `{PACKAGE_SLUG}`: The Composer package name segment after `maatify/`.
- `{REPOSITORY_SLUG}`: The repository name inside the Maatify organization.
- `{PACKAGE_DISPLAY_NAME}`: The human-readable library name.
- `{PACKAGE_DESCRIPTION}`: A concise and technically accurate package description.
- `{ROOT_NAMESPACE}`: The PSR-4 root namespace without the final separator.
- `{TEST_NAMESPACE}`: The test namespace root without the final separator; normally `{ROOT_NAMESPACE}\Tests`.
- `{MINIMUM_PHP_VERSION}`: The minimum supported PHP minor, such as `8.4`.
- `{PHP_REQUIRE_CONSTRAINT}`: The complete `require.php` constraint resolved under §15.1, such as `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0` for a package with a PHP 8 forward range.
- `{MINIMUM_PHP_PATCH_VERSION}`: The lowest PHP patch version admitted by `{PHP_REQUIRE_CONSTRAINT}` (the security patch floor of the lowest supported minor under §15.1.1), such as `8.4.27`.
- `{LICENSE_VALUE}`: The approved Composer license value, including `proprietary` for an approved proprietary license.
- `{PRIMARY_DOMAIN_KEYWORD}`: The main searchable domain term for the library.
- `{RUNTIME_EXTENSION_NAME}`: A directly used runtime PHP extension name without the `ext-` prefix.
- `{RUNTIME_PACKAGE_NAME}`: A direct runtime package in `vendor/package` form.
- `{RUNTIME_PACKAGE_CONSTRAINT}`: The approved stable constraint for a runtime package.
- `{OPTIONAL_RUNTIME_EXTENSION_NAME}`: An eligible optional capability's PHP extension name without the `ext-` prefix.
- `{OPTIONAL_RUNTIME_PACKAGE_NAME}`: An eligible optional capability's prerequisite package in `vendor/package` form.
- `{OPTIONAL_RUNTIME_PACKAGE_CONSTRAINT}`: The approved supported stable constraint for that optional prerequisite package.
- `{OPTIONAL_EXTENSION_CAPABILITY}`: The exact independently selectable built-in capability requiring the optional extension.
- `{OPTIONAL_PACKAGE_CAPABILITY}`: The exact independently selectable built-in capability requiring the optional package.
- `{PHPUNIT_CONSTRAINT}`: The latest stable PHPUnit constraint compatible with the supported PHP range, used only in PHPUnit-specific illustrative examples.
- `{PHPSTAN_CONSTRAINT}`: The latest stable PHPStan constraint.
- `{CS_FIXER_CONSTRAINT}`: The approved PHP CS Fixer constraint.
- `{README_FILE}`: A non-default README path when the package intentionally does not use `README.md`.
- `{SECURITY_POLICY_URL}`: A stable absolute URL to the package security policy when supplied in Composer support metadata.

Every placeholder used by a template MUST be defined in this section. Every placeholder MUST be replaced before a real `composer.json` is committed.

---

## 6. Canonical Top-Level Field Order

When fields are present, the canonical order is:

1. `name`
2. `description`
3. `keywords`
4. `homepage`
5. `readme`
6. `type`
7. `license`
8. `authors`
9. `support`
10. `funding`
11. `autoload`
12. `autoload-dev`
13. `require`
14. `require-dev`
15. `conflict`
16. `provide`
17. `replace`
18. `suggest`
19. `bin`
20. `scripts`
21. `config`
22. `extra`
23. `archive`
24. `minimum-stability`
25. `prefer-stable`
26. `abandoned`

Rules:

- An unused field MUST be omitted.
- Empty arrays and empty objects MUST NOT be retained merely to preserve the order.
- `require`, `require-dev`, and other package maps MUST be alphabetically sorted.
- `config.sort-packages` MUST be enabled so future package additions remain sorted.
- Optional fields MUST be placed in their canonical position.
- Field ordering is a readability and review rule; it does not change Composer semantics.

---

## 7. Package Identity

### 7.1 Package Name

The canonical form is:

```json
"name": "maatify/{PACKAGE_SLUG}"
```

Rules:

- The vendor MUST be `maatify`.
- The complete name MUST be lowercase.
- The format MUST be `vendor/package`.
- Multi-word slugs MUST use `kebab-case`.
- Spaces and uppercase characters are forbidden.
- Underscores SHOULD NOT be used even though Composer may accept them.
- The slug MUST reflect the library's actual responsibility.

For package-identity applicability, a Pre-Stable package identity is a `Published Pre-Stable identity` when at least one exact Pre-Stable version under the same Composer package name being assessed has previously attained Published state as defined by [LIBRARY_PRESENTATION_STANDARD.md Section 14](LIBRARY_PRESENTATION_STANDARD.md#14-first-stable-release-lifecycle-and-security-presentation-states). The version currently under development, including the version represented by repository `HEAD`, does not itself need to be Published. Publication under Section 14 remains specific to the exact package identity and version; this section uses the existence of at least one such previously Published version under the same Composer package name to determine identity-level applicability.

For every new Maatify PHP package and every Pre-Stable package identity that is not a Published Pre-Stable identity under this definition, the current naming policy below applies in full:

Here, `{domain}` is the package's lowercase `kebab-case` domain slug under the rules above.

- The Composer package name MUST be exactly `maatify/php-{domain}`.
- When the package has its own repository, its repository slug MUST be exactly `php-{domain}`.
- The same lowercase `kebab-case` `{domain}` slug MUST be used in both identities, so the Composer package slug MUST match the dedicated repository slug exactly.
- `php-` MUST prefix the repository slug and the package slug after `maatify/`; alternative placement, distribution exceptions, and documented deviations are not permitted.

For a Published Pre-Stable identity, its current Composer package name and repository identity remain subject to assessment against the current naming requirements in this section, including `maatify/php-{domain}` and the matching `php-{domain}` repository slug when applicable. Publication alone MUST NOT make an identity compliant, grandfathered, or permanently exempt. Published state changes only the decision process for a proposed identity migration or rename; it MUST NOT alter the current-compliance assessment. Publication also MUST NOT create an automatic preservation entitlement for an identity that does not meet current policy.

When a Published Pre-Stable identity does not meet current naming policy, this Standard MUST NOT trigger an automatic breaking rename solely to correct that mismatch. Before any rename affecting its Composer package name or repository identity, the required path is:

```text
Published Pre-Stable Identity Detected
→ Downstream Impact Review
→ Owner Decision
→ Approved Action
```

The downstream impact review MUST identify, where applicable, whether the current identity is actually externally consumable; what a Composer package identity change and a repository identity change could affect; any consumer, distribution, or install-contract references that could break; and the evidence the Owner will use to decide whether to retain, migrate, or rename the identity. This review does not prescribe an alias, `replace` rule, deprecation timeline, migration release, redirect, or consumer-specific action. The Standard does not predetermine the Owner's decision.

A claim that a package violated a naming requirement when it was historically created or published MUST be supported by authoritative evidence that the requirement existed, was authoritative, and applied to that artifact at that time. Without that evidence, there MUST be `NO RETROACTIVE VIOLATION CLAIM`. This does not make an identity compliant with current policy.

When the current decision can be made from the package's current state, publication state, downstream impact, and current policy, historical archaeology is not required. If the decision actually depends on an unproven historical fact and cannot be resolved without it, the result is `OWNER DECISION REQUIRED`; historical facts MUST NOT be inferred.

A Stable published PHP package and its repository MUST retain their existing identities unless a separate migration, compatibility, and distribution decision approves a rename. This Standard MUST NOT trigger an automatic rename of a Stable published package.

### 7.2 Package Display Name

`{PACKAGE_DISPLAY_NAME}` is used in human-facing documentation, not as a replacement for the canonical Composer name.

The display name MAY use title case and spaces. It MUST NOT change the Composer identity.

---

## 8. Description

The canonical field is:

```json
"description": "{PACKAGE_DESCRIPTION}"
```

The description:

- MUST be one concise sentence or sentence fragment.
- MUST describe the library's primary, currently implemented purpose.
- MUST mention a core technology only when it materially defines the package.
- MUST NOT claim the package is standalone, framework-agnostic, secure, audited, or production-ready unless those claims are proven.
- MUST NOT include a version number.
- MUST NOT contain temporary wording such as `coming soon`, `work in progress`, `unreleased`, or `experimental` in a stable release.
- MUST NOT use exaggerated marketing language.
- SHOULD be semantically consistent with the GitHub repository description.
- MAY differ from the GitHub description in length or wording.
- MUST remain accurate after the package scope changes.

---

## 9. Keywords

The canonical minimum set is:

```json
"keywords": [
  "{PRIMARY_DOMAIN_KEYWORD}",
  "php",
  "maatify"
]
```

### 9.1 Required Rules

- Every keyword MUST be a string.
- Keywords MUST be lowercase.
- Multi-word keywords MUST use `kebab-case`.
- Duplicate keywords are forbidden.
- Every keyword MUST correspond to an implemented domain, technology, protocol, database, or stable public behavior.
- A keyword MUST NOT claim a feature that does not exist.
- Framework names MUST NOT be used by a framework-agnostic package.
- `php` MUST be present.
- `maatify` MUST be present.
- `{PRIMARY_DOMAIN_KEYWORD}` MUST be present.
- A database keyword MUST appear only when that database is part of the documented package runtime/domain contract or verified behavior.
- An implementation behavior MAY be a keyword only when it is a stable, meaningful package characteristic.
- Useful search synonyms MAY be included when they remain accurate.
- Keywords with Composer-special discovery meaning, such as `dev`, `testing`, or `static analysis`, MUST be added only when that classification is genuinely intended.

### 9.2 Recommended Grouping

Keywords SHOULD be ordered logically:

1. Package or domain identity.
2. Runtime technology.
3. Database, protocol, or infrastructure.
4. Primary feature terms.
5. Search synonyms.
6. PHP ecosystem identity.
7. Maatify identity.

### 9.3 Recommended Size

- A focused set SHOULD normally contain 6–15 keywords.
- Keyword stuffing is forbidden.
- Generic words such as `code`, `tool`, `software`, or `utility` SHOULD NOT be used without clear discovery value.
- GitHub Topics MAY be derived from Composer keywords but do not have to be identical.

---

## 10. Homepage, README, Type, and License

### 10.1 Homepage

The canonical form is:

```json
"homepage": "https://github.com/Maatify/{REPOSITORY_SLUG}"
```

Rules:

- The URL MUST point to the current package repository.
- It MUST NOT point to another library.
- It MUST NOT be a temporary branch or task URL.
- The Maatify corporate website belongs in author metadata, not in place of the package repository homepage under this package contract.

### 10.2 README

The `readme` field SHOULD be omitted when the canonical file is `README.md`.

It MAY be declared only when the package intentionally uses a different valid path:

```json
"readme": "{README_FILE}"
```

The field MUST point to an existing file and MUST NOT be used to hide a missing root README.

### 10.3 Type

For libraries governed by this Standard:

```json
"type": "library"
```

Rules:

- The explicit `library` type MUST be used for Maatify consistency.
- `project`, `composer-plugin`, `metapackage`, and custom installer types are outside this package contract.
- A different type requires a separate approved Standard or formal Profile manifest.

### 10.4 License

The canonical field is:

```json
"license": "{LICENSE_VALUE}"
```

Rules:

- The value MUST be a valid approved SPDX identifier, valid Composer license expression, or Composer’s `proprietary` identifier for an approved proprietary license.
- It MUST match the repository `LICENSE` file.
- For published packages, Packagist license metadata MUST match the package license. GitHub license metadata MUST agree where GitHub identifies a license. GitHub may leave a custom license unclassified.
- License selection and consumer-facing presentation follow [the Library Presentation Standard](LIBRARY_PRESENTATION_STANDARD.md#25-license-presentation).
- A license change requires an explicit legal and repository decision.

---

## 11. Authors and Support Metadata

### 11.1 Canonical Author Metadata

The canonical organization author entry is:

```json
"authors": [
  {
    "name": "Maatify",
    "email": "support@maatify.dev",
    "homepage": "https://maatify.dev"
  }
]
```

Rules:

- The canonical Maatify author entry MUST be present.
- The `Maatify` casing MUST NOT be changed.
- The support email MUST NOT be changed without approval.
- The author homepage MUST NOT be changed without approval.
- Additional individuals MAY be listed only under an approved attribution policy.
- README author presentation is governed by `LIBRARY_PRESENTATION_STANDARD.md`.

### 11.2 Canonical Support Metadata

The canonical minimum block is:

```json
"support": {
  "issues": "https://github.com/Maatify/{REPOSITORY_SLUG}/issues",
  "source": "https://github.com/Maatify/{REPOSITORY_SLUG}"
}
```

Rules:

- `issues` MUST point to the current repository issue tracker.
- `source` MUST point to the current repository.
- Repository casing MUST use `Maatify`.
- Public issue tracking MUST NOT be presented as the channel for private vulnerability disclosure.
- A `security` URL SHOULD be added when a stable absolute policy URL exists:

```json
"security": "{SECURITY_POLICY_URL}"
```

- Optional support fields such as `docs`, `email`, `forum`, or `chat` MAY be added only when the endpoint is official, stable, and maintained.

---

## 12. Version Source and Release-Derived Fields

As a **Maatify Internal Policy**, the following field MUST NOT be committed:

```json
"version": "1.0.0"
```

For VCS-distributed Maatify libraries:

- Git tags are the version source.
- Packagist derives versions from VCS.
- A manually maintained `version` field can become stale or conflict with tags.
- `time` SHOULD be omitted because release time is derived from the release source.
- Branch aliases in `extra` MAY be used only when a real branch-version requirement exists and the distribution behavior is fully understood.
- Release numbers MUST NOT be encoded into descriptions, keywords, or autoload paths.

---

## 13. Production Autoload

The canonical production mapping is:

```json
"autoload": {
  "psr-4": {
    "{ROOT_NAMESPACE}\\": "src/"
  }
}
```

Rules:

- Production PHP code MUST live under `src/`.
- `{ROOT_NAMESPACE}` MUST follow `PACKAGE_BUILDING_STANDARD.md`.
- PSR-4 MUST be the default autoload strategy.
- The namespace prefix MUST end with `\\`.
- The path MUST end with `/`.
- Host-application namespaces are forbidden.
- Tests, fixtures, and examples MUST NOT be included in production autoloading.
- A fallback empty namespace prefix MUST NOT be used.
- PSR-0 MUST NOT be used for new packages.
- `classmap` MUST NOT replace valid PSR-4 design without a documented legacy reason.
- `autoload.files` SHOULD NOT be used.
- Global functions, automatic side effects, and mandatory helper includes are forbidden by default.
- `exclude-from-classmap` MUST NOT be used to conceal incorrect package structure.

After an autoload change, the repository MUST verify the mapping using:

```bash
composer dump-autoload --optimize --strict-psr
```

The strict check applies to the current package mapping and requires optimized autoload generation.

---

## 14. Development Autoload

When namespaced test classes exist, the canonical form is:

```json
"autoload-dev": {
  "psr-4": {
    "{TEST_NAMESPACE}\\": "tests/"
  }
}
```

The normal value of `{TEST_NAMESPACE}` is `{ROOT_NAMESPACE}\Tests`.

Rules:

- Test classes MUST NOT be included in production autoloading.
- Development autoload MUST point only to development-owned paths.
- Runtime code MUST NOT depend on classes available only through `autoload-dev`.
- The field MUST be omitted if the repository has no development classes requiring autoloading.
- Bootstrap files and fixtures SHOULD NOT be placed in `autoload-dev.files`.
- The development namespace MUST remain package-specific and MUST NOT use host namespaces.

---

## 15. Runtime Requirements

The `require` field MUST contain only direct runtime contracts.

Every dependency needed by the core runtime, default workflow, baseline package capability, or unconditional runtime behavior MUST be declared as a direct runtime requirement in `require`. Such a mandatory dependency MUST NOT be moved to `suggest`, `require-dev` only, documentation only, or implicit Host responsibility.

A directly used runtime prerequisite MAY be omitted from `require` only through the Optional Runtime Capability Dependency contract in §15.4. Keeping an eligible optional prerequisite in `require` remains permitted; it then becomes an unconditional installation requirement.

### 15.1 PHP Constraint

The canonical form is:

```json
"php": "{PHP_REQUIRE_CONSTRAINT}"
```

For a new package supporting PHP 8.4 and 8.5 with forward Composer
eligibility inside PHP 8, the current floors allow this form:

```json
"php": ">=8.4.27 <8.5.0 || >=8.5.12 <9.0.0"
```

Rules:

- For new Maatify PHP libraries and modules, the oldest supported minor MUST be genuinely supported and MUST be PHP 8.4 or later.
- For each admitted minor with a floor in §15.1.1, the constraint MUST reject every patch below that floor. Use separate ranges where necessary to preserve those exclusions. Forms such as `^8.4`, `^8.4.27`, or `>=8.4` do not satisfy the current floors.
- A package MAY declare forward Composer eligibility within a supported PHP major. A minor's absence from the floor table MUST NOT, on its own, require an upper bound at that minor. A package MAY retain a narrower range for a demonstrated incompatibility or a documented compatibility scope. Forward eligibility does not cross a PHP major boundary without an explicit package compatibility decision.
- Composer eligibility is the public install-time range. It does not establish security qualification or tested support for a future minor. Once a forward-eligible minor reaches Stable, any subsequent package release admitting it MUST verify it under the CI Workflow Standard. A package MUST verify a minor before claiming it as supported. A PHP prerelease MUST NOT count as production Stable qualification.
- Existing published packages MUST retain their declared compatibility until their own approved release boundary authorizes a change. This Standard does not rewrite published artifacts or require any particular constraint syntax.
- Outside security floors, the minimum MUST NOT be raised merely because a developer uses a newer local interpreter. Any bound MUST reflect the package's documented compatibility scope.
- README and CI MUST distinguish the Composer eligibility range from the minors verified as supported. The constraint governs PHP interpreter eligibility. Host runtime, SAPI, server, and `php.ini` configuration are outside the Composer manifest contract.

#### 15.1.1 PHP Security Patch Floors

This section is the single canonical source of the per-minor PHP security patch floors for `std-composer-package` and for `std-ci-workflow`, which verifies them. Other Standards and documents MUST reference it and MUST NOT restate a separate minimum.

| PHP minor | Minimum accepted patch | Rejected |
|---|---|---|
| 8.4 | 8.4.27 | 8.4.0 through 8.4.26 |
| 8.5 | 8.5.12 | 8.5.0 through 8.5.11 |

Basis: the official `php.net` security releases PHP 8.4.27 and PHP 8.5.12 published on 21 October 2026. A later advisory that is not accompanied by a released corrected patch does not create a floor and MUST NOT be encoded as one.

A floor changes only by a revision of this Standard backed by an official
`php.net` release. A minor absent from the table has no canonical numeric
floor. Its Stable releases may be eligible under §15.1. Before qualifying a
release on such a minor, verify the actual Stable patch against applicable
official PHP security advisories and corrected releases for that branch, then
run the applicable compatibility checks. Record the PHP version and official
evidence. A higher version number alone does not establish coverage of fixes
made in an earlier branch. Package evidence does not create or override a
canonical floor.

### 15.2 PHP Extensions

The canonical form is:

```json
"ext-{RUNTIME_EXTENSION_NAME}": "*"
```

Rules:

- Every extension directly required by the core runtime, default workflow, baseline package capability, or unconditional runtime behavior MUST be declared in `require`.
- An extension required only by an eligible Optional Runtime Capability MAY use the optional-capability declaration path in §15.4 instead. Runtime use in a built-in adapter alone does not prove eligibility.
- `*` is permitted for `ext-*` platform packages.
- Mandatory runtime extensions MUST NOT be hidden in `require-dev`; optional-prerequisite verification and consumer disclosure follow §16 and §15.4 respectively.
- An extension MUST NOT be declared if the runtime never uses it.
- The package MUST NOT assume that a common extension exists on every PHP installation.
- Extension polyfills do not remove the need to model the actual runtime contract accurately.

### 15.3 Runtime Packages

The canonical form is:

```json
"{RUNTIME_PACKAGE_NAME}": "{RUNTIME_PACKAGE_CONSTRAINT}"
```

Rules:

- Every package directly referenced by the core runtime, default workflow, baseline package capability, or unconditional runtime behavior MUST be declared directly in `require`.
- A package required only by an eligible Optional Runtime Capability MAY use §15.4 instead. Shipping an optional adapter that uses it does not, by itself, make it a mandatory consumer runtime requirement.
- The package MUST NOT rely on the Host application to supply an undeclared mandatory dependency. An eligible optional prerequisite MUST be disclosed explicitly before the consumer selects the capability.
- Maatify shared contracts MUST use the official shared package instead of local duplication.
- A framework dependency MUST NOT be introduced into a framework-agnostic package.
- Stable tagged constraints MUST be used for release-ready libraries.
- `dev-main`, branch names, inline aliases, and stability flags are forbidden in a stable release unless an explicit temporary exception is approved.
- `*` MUST NOT be used for ordinary runtime packages.
- Exact pins SHOULD NOT be used without a documented compatibility reason.
- Caret constraints SHOULD be the default for a stable supported major line.
- Every runtime dependency MUST have a clear package-owned reason.

### 15.4 Optional Runtime Capability Dependencies

An **Optional Runtime Capability Dependency** is a technology-specific prerequisite used only by an independently optional built-in runtime capability or implementation. This classification permits optional Composer declaration only after the capability's architecture proves eligibility; a Composer field or installation preference does not establish optionality.

The governing order is:

```text
Architecture proves the capability is optional
→ package-owned, technology-neutral, replaceable contract for infrastructure implementations using this permission
→ concrete implementation owns the technology-specific prerequisite
→ Composer may represent that prerequisite as optional
```

A package MUST NOT classify a dependency as optional merely to reduce installation requirements.

#### 15.4.1 Eligibility

For the purposes of this optional-dependency permission, a persistence, repository, storage, backend-client, backend-adapter, or equivalent infrastructure implementation MUST be treated as infrastructure-substitutable when its technology-specific prerequisite is omitted from `require`, even when the package currently ships only one built-in implementation. The replaceable contract is required from the first such implementation; having only one implementation today, or claiming that substitution is not currently intended, MUST NOT justify coupling the package contract to that implementation.

This treatment is limited to eligibility for omitting a technology-specific runtime prerequisite from `require` under §15.4. It does not require an interface for every class or impose a general runtime architecture rule on packages that do not use this permission; their other canonical contracts continue to apply.

Omission of a directly used runtime prerequisite from `require` is permitted only when **all applicable conditions** below are proven:

1. **Independent optionality:** The capability MUST be explicitly optional and independently selectable. Absence of the prerequisite MUST NOT prevent package installation, core autoload, default package use, or unrelated capabilities.
2. **Architecture before Composer:** An infrastructure implementation using this permission MUST sit behind a package-owned, technology-neutral, replaceable semantic contract/interface from its first implementation, under the treatment defined above. Eligibility MUST be assessed against [PACKAGE_BUILDING_STANDARD.md](PACKAGE_BUILDING_STANDARD.md), including its infrastructure-substitution contract requirement in §23 and the construction/integration contract in §18 and §25. That Standard owns runtime architecture and contract placement.
3. **No concrete backend coupling:** At such a substitutable boundary, Services/orchestration MUST consume the package semantic contract rather than a concrete connection, client, framework backend, or built-in backend implementation. The concrete implementation alone owns its technology-specific prerequisite.
4. **No technology leakage:** The neutral contract MUST represent package-owned semantics and MUST NOT expose backend-specific operations, connection/client objects, or API types that are merely implementation details and make the optional prerequisite part of that contract. A method returning a concrete backend connection is not a technology-neutral substitution boundary. Technology that genuinely belongs to the package semantic contract is assessed under that actual contract, not treated as an implementation detail; having only one built-in implementation MUST NOT establish that semantic ownership, and any prerequisite needed by a mandatory runtime path remains in `require` under §15.
5. **Host replaceability:** For an infrastructure implementation using this permission, the Host MUST be able to supply its own implementation of the package contract without modifying package source, subclassing the built-in concrete implementation, depending on the built-in backend technology, or depending on an unrelated backend implementation.
6. **No eager requirement:** Missing prerequisites MUST NOT break package bootstrap, Composer autoload of core/unrelated paths, the default construction path, or unrelated capabilities. An optional implementation MUST NOT be implicitly constructed or used as a default requirement while its prerequisite is represented as optional.
7. **Explicit unavailable state:** Selecting the built-in optional capability without its prerequisite MUST produce an explicit, intentional, fail-closed capability-unavailable failure documented by the package's runtime contract. Silent fallback, undefined functions, accidental class-not-found errors, unexplained fatal errors, or behavior changes in another capability do not satisfy eligibility. Failure semantics and exception hierarchy remain owned by [PACKAGE_BUILDING_STANDARD.md §7](PACKAGE_BUILDING_STANDARD.md#7-exception-rules) and the canonical root Package Reference; this Standard requires eligibility evidence and disclosure, not a particular exception class or construction design.
8. **Semantic growth:** For infrastructure implementations using this permission, eligibility MUST preserve the same package semantic contract across additional backends. A new backend providing the same semantics MUST NOT require backend-specific methods in the neutral contract. A genuinely new semantic capability belongs in an appropriate separate contract under the runtime architecture owner.

Rare use, a config or feature flag, confinement to one class or code path, dependency size, or inconvenience to some consumers is insufficient by itself. The capability architecture MUST prove optionality. A prerequisite also needed by any mandatory runtime path remains a direct `require` dependency under §15, regardless of its optional uses.

#### 15.4.2 Consumer-Facing Declaration

For every eligible prerequisite omitted from `require`, the package MUST use both disclosure surfaces:

1. The canonical root Package Reference under [PACKAGE_BUILDING_STANDARD.md §3](PACKAGE_BUILDING_STANDARD.md#3-required-files) MUST identify the exact built-in optional capability/implementation and exact prerequisite package or extension, supported prerequisite versions where relevant, and the selection/unavailable-state and Host/replacement contract.
2. `composer.json` MUST contain a corresponding `suggest` entry for Composer-native discovery. Each omitted prerequisite MUST have its own entry identifying that exact package or extension and the exact built-in optional capability/implementation that needs it, including when one capability needs multiple omitted prerequisites.

The Package Reference owns runtime meaning; `suggest` supplies informational discovery under §18.4. They MUST agree with actual runtime behavior. A `suggest` entry does not install the prerequisite, enforce supported versions or availability, or replace the Package Reference contract. An eligible prerequisite retained in `require` is an unconditional installation requirement and is not subject to this omission-specific `suggest` rule.

The consumer must supply the disclosed prerequisite before selecting that built-in capability. A Host-provided implementation of a replaceable contract MUST NOT inherit the unrelated built-in implementation's prerequisite. `require-dev` may provision repository-owned verification under §16, but MUST NOT be the only consumer disclosure.

---

## 16. Development Requirements

The `require-dev` field is reserved for direct repository development and verification requirements: development, analysis, formatting, and testing tools, plus eligible optional prerequisites needed to verify the package's built-in optional implementations.

Common categories include:

The following is an illustrative dependency set for a repository that actually uses PHPUnit and installs it through Composer. It is not a universal tool list; each repository MUST declare only the tools it actually uses.

```json
"require-dev": {
  "friendsofphp/php-cs-fixer": "{CS_FIXER_CONSTRAINT}",
  "phpstan/phpstan": "{PHPSTAN_CONSTRAINT}",
  "phpunit/phpunit": "{PHPUNIT_CONSTRAINT}"
}
```

Rules:

- Every test runner, framework, or other tool executed directly by repository scripts or CI MUST be declared as a direct development dependency when Composer is the means by which the repository installs it.
- A tool that is not installed through Composer MUST NOT be given a fictitious Composer dependency; its provisioning, versioning, and execution MUST instead be deterministic and repository-owned under the CI contract.
- The repository MUST NOT rely on a transitive installation of a directly executed tool.
- Development tools MUST NOT be placed in `require`.
- Mandatory runtime dependencies MUST NOT be placed only in `require-dev`.
- A prerequisite eligible under §15.4 MAY also be declared in `require-dev` when tests, static analysis, or other repository-owned verification of the built-in optional implementation need it. Root development installation does not make it a consumer runtime requirement.
- For every §15.4 prerequisite omitted from `require`, `require-dev` MUST NOT be the sole consumer disclosure: both the canonical Package Reference contract and an exact `suggest` entry are REQUIRED under §15.4.2. `require-dev` remains conditional on actual repository-owned verification needs. This permission MUST NOT hide a mandatory runtime dependency.
- Tool constraints MUST remain compatible with the minimum supported PHP version when the tool runs there.
- An unused tool or a tool with no maintained configuration MUST be removed.
- A repository with testable behavior or maintained tests MUST maintain a reproducible test-execution strategy; its declared dependencies and maintained configuration MUST match the runner and tooling actually used.
- PHPUnit MAY be selected as the repository's test runner. When selected and installed through Composer, it MUST be declared directly in `require-dev` with a constraint compatible with the repository's declared PHP contract. PHPUnit is not universally required.
- PHPStan is REQUIRED by the reusable package quality baseline/contract and MUST use the latest stable version compatible with the repository's declared PHP contract. Its execution in CI is governed by `CI_WORKFLOW_STANDARD.md` when that Standard applies.
- `dg/bypass-finals` MAY be used as a development-only test tool when a repository has a legitimate documented need to test/mock concrete final classes and that choice is consistent with its test architecture. When used, it belongs in `require-dev`.
- A code-style tool is REQUIRED when formatting is an enforced repository check.
- Tool constraints MUST NOT hardcode patch releases as permanent policy.
- Tool major-version upgrades require a compatibility review.
- Development requirement maps, including optional-prerequisite verification entries, MUST be alphabetically sorted.

---

## 17. Dependency Constraint Policy

| Dependency type | Default Maatify policy |
|---|---|
| PHP | Minimum-supported constraint backed by CI |
| `ext-*` | `*` |
| Stable runtime package | Caret constraint on an approved supported major |
| Stable development tool | Compatible stable constraint |
| Dev branch | Forbidden in a stable release |
| Inline alias | Forbidden in a stable release |
| Stability flag | Forbidden unless explicitly approved |
| Exact pin | Allowed only for a documented compatibility reason |
| Wildcard package constraint | Forbidden outside platform extensions |
| Unbounded unstable constraint | Forbidden |

Additional rules:

- Latest-compatible dependency resolution MUST succeed under `CI_WORKFLOW_STANDARD.md`.
- Lowest-supported dependency resolution MUST succeed under `CI_WORKFLOW_STANDARD.md`.
- Constraints MUST NOT advertise support broader than verification.
- Constraints MUST NOT be narrowed without a technical or compatibility reason.
- A dependency MUST NOT be retained after its direct use is removed.
- An abandoned dependency blocks release readiness unless an approved migration decision exists.
- Security advisories MUST NOT be bypassed by disabling audit or hiding the dependency.
- Temporary dependency workarounds MUST have an owner, reason, and removal condition.

---

## 18. Optional Package-Link Fields

Unused package-link fields MUST be omitted.

### 18.1 `conflict`

`conflict` MAY be used only for a demonstrated incompatibility.

Rules:

- Constraints MUST be precise.
- Broad ecosystem blocking is forbidden.
- Compound ranges MUST use correct logical operators.
- A conflict MUST NOT substitute for fixing an invalid dependency constraint.

### 18.2 `provide`

`provide` MAY be used for a real virtual package or capability contract.

Rules:

- The provided capability MUST be genuinely implemented.
- Virtual package names SHOULD use established ecosystem conventions.
- Providing the name of an actual package is forbidden unless the package truly ships that package's contract and behavior.

### 18.3 `replace`

`replace` is high risk.

It MUST NOT be used unless:

- The library genuinely replaces another package, or
- An approved aggregate package replaces its exact subpackages.

Rules:

- It MUST NOT be used to bypass dependency resolution.
- It MUST NOT be used to hide duplicate code.
- An aggregate replacement SHOULD use `self.version` where appropriate.
- Every use requires explicit architectural approval.

### 18.4 `suggest`

`suggest` MAY describe a genuinely optional enhancement. When an eligible Optional Runtime Capability Dependency is omitted from `require` under §15.4, a corresponding `suggest` entry is REQUIRED for each omitted prerequisite.

`suggest` provides discovery/disclosure. It does not install the prerequisite and does not enforce dependency availability or compatibility. Its values are explanatory text, not dependency constraints.

Rules:

- It MUST NOT hide a required runtime dependency.
- Every suggestion MUST identify the exact optional capability and exact prerequisite package or extension without implying a requirement for unrelated package use.
- Suggested packages/extensions MUST be real and maintained.
- A suggestion MUST NOT imply automatic installation or dependency enforcement. An Optional Runtime Capability Dependency suggestion MUST also agree with the package's consumer-facing prerequisite and unavailable-state contract in §15.4.2.

---

## 19. Optional Metadata and Distribution Fields

### 19.1 `funding`

`funding` MAY be added only with approved official funding destinations.

Personal, temporary, or unverified funding links are forbidden.

### 19.2 `bin`

`bin` MAY be added only when the library provides a real public CLI executable.

Rules:

- Every listed file MUST exist.
- The executable MUST be documented and tested.
- Internal convenience scripts MUST NOT be published as package binaries.
- Executables MUST NOT contain embedded credentials.

### 19.3 `extra`

`extra` MAY contain data consumed by Composer, an approved plugin, or a documented integration.

Rules:

- It MUST NOT be used as an arbitrary configuration store.
- Framework auto-discovery metadata is forbidden in a framework-agnostic library.
- Branch aliases require an actual distribution need.
- Every key MUST have a documented consumer.

### 19.4 `archive`

`archive` MAY be used only when package archive behavior requires explicit control.

Rules:

- `LICENSE`, `README.md`, `CHANGELOG.md`, `SECURITY.md`, `composer.json`, the canonical Package Reference, and any consumer-facing Usage Guide, examples, or `llms.txt` required by `LIBRARY_PRESENTATION_STANDARD.md` MUST NOT be excluded from the Composer-distributed artifact.
- When required by `std-public-contract-evolution` §8.1, the Artifact Root `DEPRECATIONS.md` MUST NOT be excluded from the Composer-distributed artifact.
- Runtime source MUST NOT be excluded.
- Secrets and development artifacts MUST NOT enter the archive.
- Exclusions MUST be reviewed against the actual Composer-distributed package contents.

### 19.5 `abandoned`

`abandoned` MUST be omitted for an actively supported package.

It MAY be set only after an explicit abandonment decision. When a maintained replacement exists, the replacement package or URL SHOULD be provided.

### 19.6 `_comment`

`_comment` SHOULD NOT be used for architecture or policy.

Long-lived decisions belong in documentation. A comment MAY be used only for a narrow temporary maintenance note with a removal condition.

---

## 20. Custom Repositories

The `repositories` field MUST NOT appear in a published reusable library under the normal Maatify package policy.

Forbidden entries include:

- Local `path` repositories.
- Developer workstation paths.
- Private task repositories.
- Temporary forks.
- Credential-bearing URLs.
- Environment-specific mirrors.
- Inline `package` repositories used to avoid publishing a dependency correctly.

An exception requires:

- An explicit distribution decision.
- A stable and approved source.
- No embedded credentials.
- Documentation of the effect on contributors and Packagist consumers.
- A removal or maintenance owner when the repository is temporary.

Repository declarations are root-package resolution configuration and are not inherited recursively by consumers. They MUST NOT be used as a substitute for publishing dependencies through an approved Composer repository.

---

## 21. Composer Scripts

Canonical script names, when the corresponding capability exists, are:

- `analyse`
- `format`
- `test`
- `test:unit`
- `test:regression`
- `test:integration`

### 21.1 Canonical Semantics

- `analyse` runs the repository's complete static analysis.
- `format` runs the mutating formatter.
- `test` runs the complete test suite.
- `test:unit` runs the Unit suite.
- `test:regression` runs the Regression suite.
- When an Integration suite exists, `test:integration` is the focused canonical developer entry point for that suite.
- When that Integration suite needs real infrastructure, `test:integration` MUST delegate to the repository-owned Integration orchestration defined by [CI_WORKFLOW_STANDARD.md](CI_WORKFLOW_STANDARD.md) §11; this Standard defines the public script name and meaning only.
- `test` remains the complete-suite entry. If the complete suite includes Integration coverage, it MUST use the same canonical Integration orchestration used by `test:integration`; it MUST NOT define a second lifecycle or parallel orchestration. Lifecycle and infrastructure details remain owned by [CI_WORKFLOW_STANDARD.md](CI_WORKFLOW_STANDARD.md) §11. Unit and Regression portions that do not need real infrastructure remain runnable without Docker.
- Packages whose Integration suite does not need real infrastructure MAY invoke their maintained raw runner directly. The existence of `test:integration` does not, by itself, impose infrastructure on a suite that does not need it.

For an infrastructure-requiring Integration suite, the public script meanings are:

```text
composer test:integration
→ focused Integration suite entry

composer test
→ complete test suite
→ the same canonical Integration orchestration when Integration is included
```

Repository-specific orchestration details are governed by [CI_WORKFLOW_STANDARD.md](CI_WORKFLOW_STANDARD.md) §11. Credentials MUST NOT be embedded in Composer scripts.

Example for a repository whose actual test runner is PHPUnit; other maintained runner commands MUST be represented by the repository's actual scripts and configuration:

```json
"scripts": {
  "analyse": "phpstan analyse src tests --level=max",
  "format": "php-cs-fixer fix",
  "test": "phpunit",
  "test:unit": "phpunit --testsuite unit",
  "test:regression": "phpunit --testsuite regression",
  "test:integration": "phpunit --testsuite integration"
}
```

The direct PHPUnit values above are illustrative only for a repository whose Integration suite does not require real infrastructure. When it does require a Database or service, the script values MUST instead point to the repository's canonical orchestration while retaining the canonical script names; this Standard does not prescribe an internal command or path.

Rules:

- A script MUST invoke a declared command or installed binary.
- Script names MUST be lowercase.
- `:` SHOULD be used for logical grouping.
- A script MUST NOT be added when the corresponding command or suite does not exist.
- `test` MUST represent the full test suite.
- An Integration script MAY require an external service, but that requirement MUST be documented.
- Scripts MUST propagate failures.
- `|| true`, silent fallbacks, and hidden skips are forbidden.
- Scripts MUST NOT contain credentials.
- Scripts SHOULD NOT download tools from the network during normal execution.
- A non-mutating `format:check` MAY be added when adopted by the repository.
- Detailed CI invocation remains governed by `CI_WORKFLOW_STANDARD.md`.

### 21.2 Root-Only Behavior

Composer scripts are root-package behavior.

They are intended for development, verification, and release work when the library repository is the root package. The library MUST NOT require consumers to execute its root scripts after installation.

---

## 22. Lifecycle Script Safety

Lifecycle hooks such as the following SHOULD NOT be used by reusable libraries:

- `pre-install-cmd`
- `post-install-cmd`
- `pre-update-cmd`
- `post-update-cmd`
- `post-autoload-dump`

When an approved root-development hook exists, it MUST NOT:

- Modify a host application.
- Create or alter a database schema.
- Run migrations.
- Perform network downloads.
- Download or execute unverified binaries.
- Request credentials.
- Read production secrets.
- Clear a host application's cache.
- Require interactive input.
- Write outside the library repository.
- Hide failures.
- Become a required consumer installation step.

A reusable library's consumer installation MUST remain free of package-owned setup procedures.

---

## 23. Composer Configuration

The canonical configuration profile is:

```json
"config": {
  "optimize-autoloader": true,
  "sort-packages": true,
  "platform": {
    "php": "{MINIMUM_PHP_PATCH_VERSION}"
  }
}
```

### 23.1 `sort-packages`

```json
"sort-packages": true
```

This setting is REQUIRED.

It keeps package maps consistently ordered when Composer modifies them.

### 23.2 `optimize-autoloader`

```json
"optimize-autoloader": true
```

This setting SHOULD be enabled for the canonical Maatify reusable-library contract.

It is a root repository autoload-generation preference. It is not a substitute for consumer deployment optimization and does not change the package's PSR-4 contract.

### 23.3 `platform.php`

The canonical form is:

```json
"platform": {
  "php": "{MINIMUM_PHP_PATCH_VERSION}"
}
```

Rules:

- The value MUST be the lowest PHP patch version admitted by the declared `require.php` constraint (for example `8.4.27` for a package supporting 8.4 and 8.5 under §15.1.1), so that root dependency resolution emulates the lowest supported, security-patched interpreter.
- It MUST be a single concrete version. It MUST NOT be a range or a disjunction, and it MUST NOT be below a §15.1.1 floor for a package governed by that section.
- It MUST NOT be higher than the lowest admitted version, and MUST NOT be used to hide an incompatibility.
- It is a root dependency-resolution setting only. It is not the consumer-facing eligibility contract, which is `require.php` under §15.1, and it does not select, certify, or configure the PHP executing in any environment.
- Fake extension entries SHOULD NOT be added.
- If an extension must be hidden to test portability, that decision belongs to explicit CI configuration.
- Because the platform setting can emulate a version different from the executing environment, real platform requirements and the actual interpreter patch MUST still be verified under `CI_WORKFLOW_STANDARD.md`, including its §7.1.
- The setting governs root dependency resolution; it does not force a consumer's PHP runtime.

### 23.4 `allow-plugins`

- If no Composer plugins are used, `allow-plugins` MAY be omitted or explicitly set to `false`.
- Every required plugin MUST be individually allowlisted.
- Allowing all plugins globally is forbidden.
- Wildcard organization approval requires a documented security reason.
- Adding a Composer plugin requires a security and necessity review.

Example:

```json
"allow-plugins": {
  "{RUNTIME_PACKAGE_NAME}": true
}
```

The placeholder MUST refer to an actual approved Composer plugin, not an ordinary package.

### 23.5 Security-Sensitive Configuration and Dependency Policy

This section owns the Composer manifest and configuration contract for dependency-policy behavior. Required execution and CI override protection are owned by [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md) §9.

#### 23.5.1 Current dependency-policy model

For Composer 2.10, `config.policy` is the canonical unified dependency-policy configuration. Its built-in policies are:

- `advisories`.
- `malware`.
- `abandoned`.

`config.policy` MUST NOT be `false`. Dependency-policy enforcement MUST remain enabled; disabling it MUST NOT be used to make a package compliant or release-ready.

#### 23.5.2 Security advisories

- The normal Maatify policy MUST retain `policy.advisories.block=true` and `policy.advisories.audit=fail`.
- Advisory blocking MUST remain enabled.
- Advisory audit behavior MUST remain fail-closed.
- `block=false` and `audit=ignore` or `audit=report` MUST NOT be used to bypass compliance.
- Advisory, package, and severity ignore mechanisms MUST NOT hide a finding from required verification unless an applicable, approved exception or decision already exists and explicitly covers that finding. This section does not create a new exception.

#### 23.5.3 Malware

- The normal Maatify policy MUST retain `policy.malware.block=true`, `policy.malware.block-scope=all`, and `policy.malware.audit=fail`.
- Malware blocking MUST remain enabled.
- `policy.malware.block-scope` MUST remain `all` for the normal Maatify security policy and MUST cover the operations represented by the current policy.
- Malware audit behavior MUST remain fail-closed.
- Malware ignores and ignored sources MUST NOT be used to bypass required security verification without an existing approved exception or decision that explicitly covers them.

#### 23.5.4 Abandoned dependencies

An abandoned dependency blocks release readiness unless an approved migration decision exists.

- The normal Maatify policy MUST retain `policy.abandoned.audit=fail`; `policy.abandoned.block=false` is permitted and is not, by itself, a violation.
- Abandoned audit MUST fail by default.
- `policy.abandoned.block=true` is not required by this Standard. `block=false` alone is not a violation because abandoned resolution blocking is not a new Maatify requirement.
- An abandoned-dependency ignore is permitted only when it is consistent with an existing approved migration decision, is limited to the decision's scope, and states the reason. It MUST NOT hide an abandoned dependency without that decision.

#### 23.5.5 Custom dependency policies

Custom dependency policies are OPTIONAL. A repository MUST NOT add one solely to satisfy this Standard. When a repository uses a custom dependency policy as security or compliance enforcement:

- It MUST NOT weaken a built-in policy.
- Its enforcement MUST be fail-closed for the operations it protects.
- `block` and `audit` settings MUST NOT turn a finding into silent success.
- Any policy source MUST be approved, use HTTPS, and contain no credentials.
- When enforcement depends on a remote policy source, `ignore-unreachable` MUST NOT be configured in a way that silently bypasses the protected operation when that source is unavailable.

#### 23.5.6 Legacy audit configuration

`config.audit` is a deprecated compatibility fallback, not the preferred current model and not the canonical Maatify policy. New or modified configuration MUST use `config.policy` when it is supported. `policy.*` and legacy `audit.*` MUST NOT be mixed incorrectly for the same built-in policy.

- `secure-http` MUST NOT be disabled.
- `allow-missing-requirements` MUST NOT be enabled.
- `platform-check` MUST NOT be disabled without an explicit compatibility reason.
- `process-timeout` MUST NOT be inflated to conceal a hanging command.
- `vendor-dir` and `bin-dir` SHOULD retain Composer defaults.
- Authentication tokens, credentials, and private repository secrets MUST NOT be stored in `composer.json`.

---

## 24. Stability Policy

The canonical stable profile is:

```json
"minimum-stability": "stable",
"prefer-stable": true
```

Rules:

As a **Maatify Internal Policy**, release-ready libraries MUST explicitly enforce the following two rules together:
- `minimum-stability` MUST be `stable`.
- `prefer-stable` MUST be enabled (`true`).

Additional rules:
- `dev`, `alpha`, `beta`, or `RC` minimum stability is forbidden for a stable release.
- Per-package flags such as `@dev`, `@alpha`, `@beta`, or `@RC` are forbidden without a documented temporary exception.
- `prefer-stable` MUST NOT be treated as permission to retain unstable requirements.
- Stability settings MUST NOT be weakened to work around an incorrect constraint.
- Pre-release dependency work requires a separate approved release plan.

---

## 25. Composer Lock Policy

For reusable libraries governed by this Standard:

> **As a Maatify Internal Policy, `composer.lock` MUST NOT be committed.**

Rules:

- A lock file MAY be generated temporarily during local or CI dependency resolution.
- It MUST be removed before delivery.
- A Pull Request MUST NOT add it.
- Repository integrity checks SHOULD detect it.
- The package's ignore policy SHOULD prevent accidental tracking.
- Applications have a different lock-file policy and are outside this Standard.
- Not committing the lock file does not remove dependency verification requirements.
- CI MUST verify latest-compatible and lowest-supported resolutions according to `CI_WORKFLOW_STANDARD.md`.

---

## 26. Package Distribution and Archive Safety

- `vendor/` MUST NOT be committed or shipped as package-owned source.
- `.env` files, credentials, tokens, private keys, and machine-specific configuration MUST NOT be published.
- IDE metadata and local task artifacts MUST NOT be part of the package distribution.
- `composer.lock` MUST NOT be present in the reusable-library source distribution.
- Runtime source, `composer.json`, `README.md`, `LICENSE`, `CHANGELOG.md`, `SECURITY.md`, the canonical Package Reference, and any applicable consumer-facing Usage Guide, examples, and `llms.txt` required by `LIBRARY_PRESENTATION_STANDARD.md` MUST remain available in the Composer-distributed artifact.
- `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md` are repository contribution/community surfaces; this Standard does not require them in every Composer archive unless a package explicitly makes them consumer documentation.
- Tests and documentation MAY remain in source distributions.
- The required file set is determined by the applicable Library Presentation contract; this Standard owns whether those files actually enter the Composer artifact. Every required file MUST be present in the distributed artifact and reflect the same exact Release Artifact Identity as the runtime and manifest.
- `archive.exclude`, generated archive behavior, VCS-based dist behavior, and `.gitattributes` `export-ignore` MUST be reviewed together where applicable. No particular `.gitattributes` mechanism is mandatory. An exclusion rule MUST NOT defeat the required file set. When the registry is required under §19.4, verify its root location, contents, and links in the resulting exact-target consumer-delivery artifact under the existing verification gates, including exposed `dist` where applicable. The publication-safety criteria for the registry and its evidence are owned by `std-public-contract-evolution` §11.2.
- Generated archives MUST be inspected at Release Artifact Verification when custom archive exclusions or export rules exist; checks MUST inspect the resulting artifact, not only the source repository tree.
- Package installation MUST NOT depend on files ignored by VCS or excluded from the distribution.
- Composer repository metadata MAY expose a `source` checkout, a `dist` archive, or both for a package version. These are distinct delivery representations and MUST NOT be assumed to contain identical files. The applicable required-file set MUST be present in each representation that the package's approved distribution policy identifies as a consumer-delivery artifact.
- When the approved Composer channel exposes `dist`, that published archive is the authoritative packaged artifact for consumers and its verification is mandatory; a `source` checkout MUST NOT substitute for verifying its contents. A source-only consumer-delivery mode is permitted only when the channel exposes no `dist` for that exact version and a valid pre-existing source-only declaration under §26.1 establishes source as intentional canonical consumer delivery. A failed `dist` download or fallback does not establish a source-only distribution, and a source-only declaration MUST NOT waive verification of an exposed `dist`.

### 26.1 Source-Only Canonical Consumer Delivery

When an approved Composer channel exposes no `dist` for an exact version, Published Artifact Verification MAY accept the observed source installation only when a package-owned, authoritative, durable decision already establishes source-only canonical consumer delivery before Release Artifact Verification qualifies that exact target. At the qualification boundary, the canonical evidence is an `ACTIVE`, applicable, Owner-approved package-specific Decision Record in the package repository's existing `docs/decisions/` mechanism, discoverable through its existing `docs/decisions/DECISIONS_INDEX.md`; the record MUST use the repository's existing decision governance and MUST NOT introduce a parallel exception registry or policy database. The Decision and Owner approval/effective state MUST pre-exist and be effective before Release Artifact Verification begins for the target, and therefore before qualification and Tag/Release/Publish authorization. Release Artifact Verification MUST verify and retain the qualification-time Decision and Index state and an immutable record reference under `CI_WORKFLOW_STANDARD.md` §2.5. The declaration MUST NOT be created or approved by the verification executor as part of verification.

The Decision Record MUST be approved by the package/repository Owner under that repository's existing decision authority and MUST identify:

- the exact Composer package identity;
- the approved Composer repository/channel, including its authoritative identity/URL;
- `delivery mode = source-only` and that source is the intentional canonical consumer-delivery representation;
- the exact version or bounded version line/applicability scope, including the assessed version;
- why `dist` is intentionally not offered/used for that scope;
- the approving authority and approval/effective date; and
- the responsible maintenance owner, when applicable.

The durable record and its Owner approval MUST predate Release Artifact Verification and apply at the assessed target's qualification boundary. PAV MUST use the same Decision ID and immutable record reference proven at that boundary and MUST prove the actual post-publication facts separately, including that `dist` is absent, `source` is exposed, the observed install mode is `source`, and the installed source reference/content corresponds to the intended release. PAV MUST verify the retained evidence that this exact Decision was `ACTIVE`, indexed, Owner-approved, and applicable when the target qualified, and MUST verify that the Decision remains historically discoverable through a coherent current Index and supersession chain. Its current status MAY be `ACTIVE` or legitimately `SUPERSEDED`; later legitimate supersession does not make that historical qualification invalid and does not make the superseded Decision current authority. A materially applicable superseding Decision that became authoritative before Tag/Release/Publish authorization requires the release to be re-evaluated under the current governance; qualification-time evidence MUST NOT bypass it. A Decision approved after qualification or Publication, including one whose stated version scope names the existing target, MUST NOT retroactively make that exact release compliant; it MAY govern a future target when effective before that target's qualification boundary. A README, Package Reference, `composer.json` custom field, PR/comment, executor report, or unapproved policy statement alone is not this declaration. If the repository's existing decision governance cannot establish the record's Owner approval, qualification-time active/indexed status, applicability, immutable reference, or coherent history, the declaration is invalid and verification MUST fail closed. If the approved channel exposes `dist` for the assessed version, this source-only declaration does not override the `dist` verification requirement.

---

## 27. Metadata Synchronization

The following contracts MUST remain synchronized:

- `name` matches the published package identity.
- `description` is semantically consistent with the GitHub description.
- `keywords` and GitHub Topics do not contradict each other.
- `homepage`, `support.source`, and `support.issues` target the current repository.
- `license` matches `LICENSE` and repository metadata.
- The PHP constraint matches README and CI support claims.
- Mandatory runtime dependencies in `require` match README requirements and actual mandatory runtime use.
- Eligible Optional Runtime Capability Dependencies are distinguished from mandatory requirements; for each prerequisite omitted from `require`, the canonical Package Reference disclosure, its exact `suggest` entry, and actual runtime behavior agree under §15.4. Repository-only entries in `require-dev` match actual verification needs under §16 when present.
- Composer scripts match maintained contributor commands.
- Packagist badges use the correct Composer package name.
- The Package Reference does not claim undeclared mandatory runtime dependencies, and identifies each eligible optional prerequisite and its selection/unavailable-state contract without competing with Composer manifest ownership.
- `authors` remains consistent with approved organization metadata.

`composer.json` is the source of truth for the Composer manifest contract: package identity, metadata, requirements, dependencies, autoloading, scripts, configuration, stability, and distribution declarations where applicable. Every claim inside it MUST be supported by runtime code, documentation, and verification.

Composer distribution facts remain separate from presentation semantics. The exact Release Artifact Identity and the required consumer/release-facing file set are owned by `LIBRARY_PRESENTATION_STANDARD.md`; this Standard requires the manifest/archive/VCS distribution behavior to preserve that identity and file set in the artifact Composer installs.

---

## 28. Canonical Composer Templates

### 28.1 Minimal Publishable Library

This template contains no empty fields and applies when PHP is the only mandatory runtime requirement. Add actual mandatory dependencies under §28.3 and eligible optional-capability declarations under §28.4 when applicable.

```json
{
  "name": "maatify/{PACKAGE_SLUG}",
  "description": "{PACKAGE_DESCRIPTION}",
  "keywords": [
    "{PRIMARY_DOMAIN_KEYWORD}",
    "php",
    "maatify"
  ],
  "homepage": "https://github.com/Maatify/{REPOSITORY_SLUG}",
  "type": "library",
  "license": "{LICENSE_VALUE}",
  "authors": [
    {
      "name": "Maatify",
      "email": "support@maatify.dev",
      "homepage": "https://maatify.dev"
    }
  ],
  "support": {
    "issues": "https://github.com/Maatify/{REPOSITORY_SLUG}/issues",
    "source": "https://github.com/Maatify/{REPOSITORY_SLUG}"
  },
  "autoload": {
    "psr-4": {
      "{ROOT_NAMESPACE}\\": "src/"
    }
  },
  "require": {
    "php": "{PHP_REQUIRE_CONSTRAINT}"
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true,
    "platform": {
      "php": "{MINIMUM_PHP_PATCH_VERSION}"
    }
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

### 28.2 Test-Enabled Extension

The following is an optional example for a repository that selects PHPUnit as its actual test runner and installs it through Composer. PHPUnit is not a universal requirement; when a repository uses different tooling, its direct dependencies and script commands MUST reflect that actual tooling. The direct `test:integration` value is suitable only when the Integration suite does not require real infrastructure; otherwise it MUST be replaced by the repository's canonical Integration orchestration as required by §21.

```json
{
  "autoload-dev": {
    "psr-4": {
      "{TEST_NAMESPACE}\\": "tests/"
    }
  },
  "require-dev": {
    "friendsofphp/php-cs-fixer": "{CS_FIXER_CONSTRAINT}",
    "phpstan/phpstan": "{PHPSTAN_CONSTRAINT}",
    "phpunit/phpunit": "{PHPUNIT_CONSTRAINT}"
  },
  "scripts": {
    "analyse": "phpstan analyse src tests --level=max",
    "format": "php-cs-fixer fix",
    "test": "phpunit",
    "test:unit": "phpunit --testsuite unit",
    "test:regression": "phpunit --testsuite regression",
    "test:integration": "phpunit --testsuite integration"
  }
}
```

### 28.3 Runtime Requirement Extension

Add direct mandatory runtime contracts. A prerequisite omitted from `require` is permitted only under §15.4 and is disclosed separately as illustrated in §28.4:

```json
{
  "require": {
    "ext-{RUNTIME_EXTENSION_NAME}": "*",
    "{RUNTIME_PACKAGE_NAME}": "{RUNTIME_PACKAGE_CONSTRAINT}",
    "php": "{PHP_REQUIRE_CONSTRAINT}"
  }
}
```

### 28.4 Optional Runtime Capability Declaration

For a package whose two independently selectable built-in capabilities satisfy §15.4 and whose prerequisites are omitted from `require`, the following fragment illustrates the REQUIRED `suggest` entry for each optional extension and package. Include the illustrated `require-dev` entries only when repository-owned tests, static analysis, or other verification actually need those prerequisites; consumers do not inherit them. The canonical Package Reference MUST also document each capability's runtime, selection, unavailable-state, and Host/replacement contract. Omit example entries for capabilities or verification needs the package does not have, but not a `suggest` entry for an omitted §15.4 prerequisite.

```json
{
  "require-dev": {
    "ext-{OPTIONAL_RUNTIME_EXTENSION_NAME}": "*",
    "{OPTIONAL_RUNTIME_PACKAGE_NAME}": "{OPTIONAL_RUNTIME_PACKAGE_CONSTRAINT}"
  },
  "suggest": {
    "ext-{OPTIONAL_RUNTIME_EXTENSION_NAME}": "Required only for {OPTIONAL_EXTENSION_CAPABILITY}; enable ext-{OPTIONAL_RUNTIME_EXTENSION_NAME} before selecting it.",
    "{OPTIONAL_RUNTIME_PACKAGE_NAME}": "Required only for {OPTIONAL_PACKAGE_CAPABILITY}; install {OPTIONAL_RUNTIME_PACKAGE_NAME} ({OPTIONAL_RUNTIME_PACKAGE_CONSTRAINT}) before selecting it."
  }
}
```

Each `suggest` key identifies the exact omitted prerequisite, and its explanatory value identifies the exact built-in capability that needs it; add a separate entry for every additional omitted prerequisite. Supported version text in `suggest` is informational, not a Composer-enforced constraint; supported versions where relevant belong in the Package Reference contract. This fragment MUST NOT be used to omit any dependency needed by a mandatory runtime path from `require`.

Template rules:

- These snippets MUST be merged into one valid final JSON object.
- Duplicate top-level keys are forbidden.
- Empty objects MUST NOT be retained.
- Unused fields MUST be omitted.
- Runtime extensions and packages MUST reflect actual use and the mandatory/eligible-optional classification in §15.
- Development requirements MUST reflect actual repository commands or optional-implementation verification under §16.
- The final file MUST contain no comments, placeholders, or trailing commas.

---

## 29. Validation Requirements

Every new or modified `composer.json` MUST pass:

```bash
composer validate --strict
```

When autoload mappings change, it MUST also pass:

```bash
composer dump-autoload --optimize --strict-psr
```

Review MUST verify:

- Valid JSON.
- Composer schema validity.
- Valid package name.
- Accurate description.
- Valid license expression.
- Valid and current support URLs.
- Correct PSR-4 mappings.
- Declared PHP compatibility.
- Direct `require` completeness for mandatory runtime dependencies; for every §15.4 prerequisite omitted from `require`, the canonical Package Reference contract and an exact matching `suggest` entry identifying the prerequisite and built-in capability, including every prerequisite of a capability with multiple omissions.
- Correct separation of mandatory runtime requirements, optional capability prerequisites, and repository development/verification requirements.
- Real script commands and suites.
- No committed `version` field.
- No committed `composer.lock`.
- No unapproved custom repositories.
- No unstable release constraints.
- No credentials or private environment paths.
- No unsafe lifecycle hooks.
- No copied package or repository identity.

Automated verification of latest dependencies, lowest dependencies, platform requirements, audits, abandoned packages, and quality gates belongs to `CI_WORKFLOW_STANDARD.md`.

---

## 30. Composer Review Checklist

### Identity and Metadata

- [ ] Package name uses the `maatify` vendor.
- [ ] Package name is lowercase and uses `kebab-case`.
- [ ] A new package, or a Pre-Stable package identity for which no exact Pre-Stable version under that same Composer package name has ever attained Published state, uses Composer name `maatify/php-{domain}` and, when it has its own repository, repository slug `php-{domain}`; no documented deviation is permitted. Version publication is determined by Library Presentation Standard §14.
- [ ] A Published Pre-Stable identity is assessed against current naming policy, is not compliant or preserved solely because it was published, and is not renamed before downstream impact review and an Owner Decision.
- [ ] A Stable published package and its repository retain their existing identities unless a separate migration, compatibility, and distribution decision approves a rename.
- [ ] Description accurately states the current package purpose.
- [ ] Keywords are focused, relevant, lowercase, and non-duplicated.
- [ ] `php` and `maatify` keywords are present.
- [ ] The primary domain keyword is present.
- [ ] Homepage points to the current repository.
- [ ] Type is `library`.
- [ ] License matches `LICENSE`.
- [ ] Canonical Maatify author metadata is present.
- [ ] Support URLs point to the current repository.
- [ ] Security reporting is not directed to public issues.
- [ ] As a Maatify Internal Policy, no `version` field is committed.

### Autoload

- [ ] Production autoload uses PSR-4.
- [ ] Production autoload points only to `src/`.
- [ ] Namespace and paths end with the required separators.
- [ ] No host namespace exists.
- [ ] Tests are excluded from production autoload.
- [ ] Development autoload points to `tests/` when applicable.
- [ ] Runtime code does not depend on development autoload.
- [ ] Strict PSR autoload validation succeeds after mapping changes.

### Requirements and Constraints

- [ ] PHP minimum matches README and CI.
- [ ] Every PHP extension needed by the core runtime, default workflow, baseline capability, or unconditional behavior is declared directly in `require`.
- [ ] Every package needed by those mandatory runtime paths is declared directly in `require`.
- [ ] Every runtime prerequisite omitted from `require` satisfies all applicable §15.4 conditions, with architecture/Host-replaceability evidence where applicable, no eager requirement, and an explicit unavailable-state contract.
- [ ] Every Optional Runtime Capability Dependency omitted from `require` is present in `suggest` and documented in the canonical Package Reference.
- [ ] Every such `suggest` entry identifies the exact omitted prerequisite and exact built-in optional capability/implementation that needs it; all prerequisites of a capability are covered.
- [ ] No mandatory runtime dependency is hidden in `suggest`, `require-dev` only, documentation only, or implicit Host responsibility.
- [ ] Optional-prerequisite entries in `require-dev` serve actual repository verification and are not the only consumer disclosure; the Package Reference and `suggest` are both present for §15.4 omissions.
- [ ] `suggest` entries make no installation or enforcement claim.
- [ ] No development tool is placed in `require`.
- [ ] No dependency relies accidentally on transitive installation.
- [ ] Package maps are alphabetically sorted.
- [ ] Stable constraints are used.
- [ ] No dev branches, inline aliases, or unstable flags remain.
- [ ] Exact pins have a documented reason.
- [ ] No unused dependency remains.
- [ ] Optional package-link fields are semantically correct.
- [ ] `replace` and `provide` have explicit architectural justification.

### Scripts and Configuration

- [ ] Composer scripts map to real declared commands.
- [ ] Suite scripts exist only for real suites.
- [ ] `test` runs the full test suite.
- [ ] `test:integration` is the focused canonical Integration entry when an Integration suite exists.
- [ ] Scripts propagate failures.
- [ ] Scripts contain no credentials.
- [ ] No unsafe lifecycle hooks exist.
- [ ] `sort-packages` is enabled.
- [ ] `platform.php` is a single concrete version equal to the lowest patch admitted by `require.php`, and is not presented as the consumer eligibility contract.
- [ ] For a new package, `require.php` rejects patches below every applicable §15.1.1 floor. Any forward Composer eligibility follows §15.1 and remains distinct from verified support.
- [ ] An existing published package's PHP constraint is changed only through its own approved compatibility boundary.
- [ ] `optimize-autoloader` follows the approved package configuration policy.
- [ ] Composer plugins are explicitly allowlisted when present.
- [ ] `config.policy` is used when supported and is not `false`.
- [ ] Built-in dependency policies and any configured custom policy satisfy the security and exception contract in §23.5.
- [ ] `config.audit` is treated only as a deprecated fallback and is not incorrectly mixed with `policy.*` for the same built-in policy.
- [ ] `secure-http` is not disabled.
- [ ] `allow-missing-requirements` is not enabled.
- [ ] Default vendor and binary directories are preserved unless justified.

### Stability, Distribution, and Validation

- [ ] As a Maatify Internal Policy, `minimum-stability` is `stable`.
- [ ] As a Maatify Internal Policy, `prefer-stable` is enabled.
- [ ] No custom repository exists without approval.
- [ ] No credentials, local paths, or private task URLs exist.
- [ ] `vendor/` is not committed.
- [ ] As a Maatify Internal Policy, `composer.lock` is not committed.
- [ ] Package archive rules preserve runtime source and required legal and consumer files. When required, the delivered `DEPRECATIONS.md` is present and its contents and links pass the existing publication-safety checks (§§19.4 and 26).
- [ ] `composer validate --strict` succeeds.
- [ ] Composer metadata matches README, package reference, GitHub metadata, and CI claims.
- [ ] Latest-compatible dependency verification succeeds in CI.
- [ ] Lowest-supported dependency verification succeeds in CI.
- [ ] Platform-requirement verification succeeds in CI.
- [ ] Composer dependency-policy audit and abandoned-package policy succeed in CI under the effective, fail-closed configuration.

---

## 31. Non-Goals

This Standard does not force:

- The same runtime dependencies on every library.
- The same keywords.
- The same development-tool versions.
- Test suites that do not apply.
- A framework dependency.
- A database dependency.
- Composer plugin behavior.
- Application lock-file policy.
- GitHub Actions implementation.
- README visual formatting.
- Package-specific runtime architecture.
- A custom repository.
- A binary executable.
- Verbatim copying of another library's `composer.json`.

---

## 32. Reference Basis

This Standard is intentionally stricter than Composer's general schema where Maatify requires consistent reusable-library behavior.

Primary Composer references:

- Composer schema: `https://getcomposer.org/doc/04-schema.md`
- Composer basic usage and lock files: `https://getcomposer.org/doc/01-basic-usage.md`
- Composer CLI and strict PSR validation: `https://getcomposer.org/doc/03-cli.md`
- Composer configuration: `https://getcomposer.org/doc/06-config.md`

Official Composer documentation remains authoritative for Composer mechanics. This Standard remains authoritative for Maatify package policy.

## Version History

### `8.0.0`

- Issue #209 (`DEC-056`): require Composer archive and VCS distribution to preserve the applicable Artifact Root registry. A previously compliant exclusion could omit it. `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major).
- Owner-approved Mutable Candidate `8.0.0` from Frozen Baseline `7.0.0`, artifact blob `25038a44917a3cd387238e38a8c59105c2952488` at completed VALID Upgrade Adoption Commit `1f560795397f24bddcc3d09b982ff93a77f7154e`. [Owner Version Finalization on 2026-11-24](https://github.com/Maatify-ltd/php-engineering-standards/pull/210#issuecomment-7127402843).

### `5.0.0`

- Frozen baseline `4.1.0`, exact artifact blob `d020e3f4b09830338b4a1647851048e02c64252e`, proven by the completed VALID consumer upgrade in a consumer repository (PR #10; identity withheld per `DEC-042`). Upstream Adoption Commit: `2ea426aee0e6a5265f0c30ced667f21bb7b1d302`; consumer integration commit: `28b790455685b3f7052688d7d20d4b939fd13795`; the consumer-pinned artifact blob is `d020e3f4b09830338b4a1647851048e02c64252e` (exact blob equality PASS).
- Issue #168 (`DEC-042`): consumer-identity neutralization of the preceding frozen-baseline evidence line only; `EDITORIAL / NO_CONTRACT_CHANGE` (Patch), absorbed in the existing Mutable Candidate calculated from the Frozen Version Baseline; no additional bump, freeze, release, or adoption. The artifact blob and commit hashes are unchanged.
- `NORMATIVE / BREAKING_CONTRACT_CHANGE`: require the Composer-distributed archive to preserve the complete applicable consumer/release-facing artifact set and its exact release identity, including VCS export behavior and resulting archive inspection; existing archive/export exclusions may otherwise omit required documents. Calculated candidate from frozen baseline `4.1.0`: `5.0.0`.

### `7.0.0`

- `DEC-053` changes the Composer eligibility and release-verification contract (`NORMATIVE / BREAKING_CONTRACT_CHANGE`). Keep existing per-minor security floors and allow forward eligibility within a supported major under §15.1. Calculate the Major candidate from the frozen `6.1.0` artifact blob `1605e1f69513477d80ff40041a0f4ecb512d2f89`, proven by completed VALID adoption at `d3dd98b8d82c8bcdd65614fe4ed80853bdcdcc73`.

### `6.1.0`

- `DEC-050`: `NORMATIVE / BACKWARD_COMPATIBLE_CONTRACT_CHANGE` (Minor). Explicitly allow Composer’s `proprietary` identifier, allow GitHub to leave a custom license unclassified, and reference the separate presentation contract. Previously valid license identifiers and expressions remain accepted.
- Mutable Candidate `6.1.0`, calculated from Frozen Version Baseline `6.0.0`, artifact blob `87e17351519855ee5438524acfb3f7f972d3a606` at completed VALID Adoption Commit `c5d70cbe005fc26c38a7218967efe991194e76d6` (consumer identity withheld per `DEC-042`).

### `6.0.0`

- Frozen Version Baseline `5.0.0`, exact artifact blob `64ce9bc38a87034c5a57df0265f551d083658b32`, proven by completed VALID consumer Adoption (identity withheld per `DEC-042`; upstream Adoption Commit `334c9d5888fd46f9b4624c57e131440c37b840ce`; evidence recorded in Issue #179). The calculation below is made once from this baseline, not from a previous candidate.
- Cumulative changes since the baseline: (1) Issue #168 (`DEC-042`) consumer-identity neutralization of a Version History evidence line, `EDITORIAL / NO_CONTRACT_CHANGE` (Patch); (2) Issue #181, below. The earlier `5.0.0` entry is retained as historical evidence; the Issue #179 reconciliation below corrects its frozen-status inference.
- Issue #181 `NORMATIVE / BREAKING_CONTRACT_CHANGE`: new-package `require.php` must exclude, per supported minor, every PHP patch below the §15.1.1 security floor (8.4.27, 8.5.12) and any minor without a floor, replacing the previously canonical `^8.4`; `config.platform.php` is defined as the lowest admitted patch and not the consumer eligibility contract; templates, placeholders, and checklist updated. Previously compliant new-package constraints such as `^8.4` become non-compliant. Existing published packages' declared compatibility is not amended.
- Version Class: Major (highest impact in the cumulative set) → calculated Mutable Candidate `6.0.0` from Frozen Baseline `5.0.0`; not chained through `5.0.1`. This is a candidate only: no Freeze, Owner Version Finalization, release, or adoption is claimed.

- Issue #179 factual reconciliation (2026-11-04): the historical `5.0.0` entry's statement that Issue #168's editorial edit was absorbed in an existing Mutable Candidate was inaccurate because the qualified `5.0.0` snapshot above was already frozen. The original entry is preserved; neither the neutralization policy nor the frozen artifact is rewritten. This evidence correction is `EDITORIAL / NO_CONTRACT_CHANGE` (Patch class), aggregated with the already-recorded breaking Issue #181 change from Frozen `5.0.0`; the highest cumulative impact remains Major and the Mutable Candidate remains `6.0.0`. The earlier editorial-only proposal `5.0.1` is no longer the cumulative result, and no `6.0.1`, new Freeze, Version Finalization, release, or Adoption is created by this reconciliation.
