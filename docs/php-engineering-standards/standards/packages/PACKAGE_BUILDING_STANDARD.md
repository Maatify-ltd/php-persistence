# PACKAGE_BUILDING_STANDARD

**Maatify Standalone Composer Package Building Standard**

## Standard Metadata

- **Standard ID:** `std-package-building`
- **Standard Version:** `6.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`

This Standard defines the architecture and runtime contract for reusable Maatify Composer packages.

---

## 1. The Package Contract

This Standard applies to standalone reusable Maatify PHP/Composer packages and to an extractable Base Module's Artifact Root where the Base Module Standard applies the generic Package contract.

Every package must be:

- **Standalone** — runs isolated from host applications (depends only on explicit Composer/runtime dependencies) with no knowledge of the host project internals
- **Installable** — packaged as a `composer require` library
- **Host-agnostic** — never FKs or JOINs on host tables. Host provides IDs; package trusts them. Host applications wire dependencies themselves.
- **PHPStan max** — zero errors at level max before the package is considered done

### PHP Compatibility Baseline

New Packages and applicable Base Artifacts MUST follow the PHP support, Composer eligibility, and security floor rules in [Composer Package Standard §15.1](COMPOSER_PACKAGE_STANDARD.md#151-php-constraint). An already published Package MUST retain its declared compatibility contract until raising the minimum PHP version is permitted by its compatibility/versioning policy.

### Persistence Conditional Applicability

PDO, SQL, SQL schema and migrations, shared PDO Ordering/Pagination, and PDO hydration rules apply only when the Package owns SQL persistence or SQL database behavior. General transaction ownership, concurrency and persistence verification rules apply to the behavior and backend the Package actually owns, including non-SQL backends under the next section.

A package without persistence or database behavior is not required to use PDO, provide `schema/`, define migrations, or implement database-specific architecture or tests. When a package does own persistence or database behavior, all applicable persistence requirements in this Standard remain mandatory. For SQL persistence, these requirements include direct PDO usage, no ORM, and no external query builder. Small internal SQL fragment builders are allowed only for repeated package-local query logic.

### Default SQL Persistence Profile

The default SQL persistence profile is MySQL/MariaDB-compatible and uses direct PDO.

A package MAY define a different or additional persistence backend, including non-SQL storage, as part of its documented persistence contract. A claimed backend MUST be explicitly documented, actually implemented, and protected by real persistence verification/tests applicable to that backend.

Additional backends are package-specific and MUST NOT be required of any package.

PDO driver availability alone does not establish database support.

### Scope and Ownership

- This file owns runtime architecture, package structure, and package-specific testing applicability.
- [`TESTING_STANDARD.md`](../testing/TESTING_STANDARD.md) owns the general testing strategy, observable-behavior evidence, regression-protection model, and Consumer Verification Harness contract; this Standard defines Package-readiness applicability and MUST NOT duplicate the Harness's detailed requirements.
- [`COMPOSER_PACKAGE_STANDARD.md`](COMPOSER_PACKAGE_STANDARD.md) owns the canonical Composer manifest contract represented by `composer.json`, including dependency declarations and version constraints.
- [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md) owns workflow and check execution.
- [`LIBRARY_PRESENTATION_STANDARD.md`](LIBRARY_PRESENTATION_STANDARD.md) owns README structure, badges, release-facing files, and the consumer-facing Usage Guide/examples artifact requirements.
- [`DOCUMENTATION_LIFECYCLE_STANDARD_AR.md`](../governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md) owns document roles, current-versus-historical semantics, freshness, retention, and documentation-reference hygiene.
- [`PHP_SOURCE_DOCUMENTATION_STANDARD.md`](../php/PHP_SOURCE_DOCUMENTATION_STANDARD.md) owns general PHP source documentation. [`PHP_CODING_STYLE_STANDARD.md`](../php/PHP_CODING_STYLE_STANDARD.md) owns PHP coding style.

These standards MUST use cross-references and MUST NOT duplicate each other's detailed rules.

---

## 2. Required Maatify Runtime Dependencies

To maintain standalone Composer package boundaries and a framework-agnostic architecture while ensuring ecosystem consistency, packages must rely on the provided Maatify shared packages rather than defining package-local duplicates.

- **Exceptions:** When a package defines package-owned exception classes, they MUST use the appropriate stable hierarchy from `maatify/exceptions`, available from `v1.0.0`.
  - `MaatifyException` is the abstract root base class.
  - `ApiAwareExceptionInterface` is the general public contract.
  - The package marker interface remains package-owned and extends `\Throwable`.
  Repository: https://github.com/Maatify/exceptions

- **Clock/Date-Time:** A package that requires a clock abstraction MUST depend on `maatify/shared-common` and consume `ClockInterface`, available from `v1.0.0`. `SystemClock` is a production implementation available from `v1.0.0`, but it is not mandatory for every consumer. No shared Frozen/Test Clock is claimed by this Standard.
  Repository: https://github.com/Maatify/SharedCommon

- **Persistence Utilities:** Packages that require reusable PDO row-position/display-order management or pagination capabilities MUST use the eligible public API of `maatify/php-persistence` under Section 11.
  Repository: https://github.com/Maatify-ltd/php-persistence

**Rule:** Packages MUST NOT define package-local duplicates of a capability available through an eligible public API in a Maatify shared package. Section 11 defines the release eligibility and non-duplication rules for the shared PDO capabilities.

A stable dependency constraint MUST start at the first compatible stable release that exposes every shared API the Package uses. Section 11 defines the approved Stable Major 1 line, publication gate, and compatibility range for Persistence. Proposed architecture documents, unreleased branches, commits, and implementation contracts are not consumable public APIs and MUST NOT be copied into consumer packages.

The [Composer Package Standard](COMPOSER_PACKAGE_STANDARD.md) owns dependency declarations and constraints, including the eligibility and disclosure rules in §15.4 for an independently optional runtime prerequisite. Optional declaration does not waive shared API or release eligibility requirements.

This ensures:
- Explicit Composer/runtime dependencies only
- Public API stability
- Backward compatibility as much as possible
- One authoritative implementation for shared ecosystem capabilities

---

## 3. Required Files

Repository presentation, governance-document identity, release-facing metadata, and visual consistency MUST follow [LIBRARY_PRESENTATION_STANDARD.md](LIBRARY_PRESENTATION_STANDARD.md).
Composer package metadata, dependency declarations, autoloading, scripts, configuration, stability, and lock-file policy MUST follow [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).


Every Package MUST provide the applicable files and directories below. The Library Presentation Standard defines the complete applicable release-facing and consumer-facing artifact set. Use the repository root for a standalone Package and the Artifact Root defined by `MODULE_BUILDING_STANDARD.md` for an in-project Base Module.

```
├── README.md                          ← installation, quick examples, what it does / does not
├── CHANGELOG.md                       ← Keep a Changelog; release-preparation and CHANGELOG presentation semantics are owned by LIBRARY_PRESENTATION_STANDARD.md
├── {PACKAGE_NAME}_PACKAGE_REFERENCE.md ← canonical stable public/runtime package contract (e.g. EXAMPLE_PACKAGE_REFERENCE.md)
├── composer.json                      ← governed by COMPOSER_PACKAGE_STANDARD.md
├── phpstan.neon                       ← governed by Section 21
├── src/                               ← all PHP source code
├── tests/                             ← if applicable
├── schema/                            ← when the Package owns SQL schema, migration, or install assets
└── docs/                              ← detailed architecture, integration, roadmap, and audit documents
```

### Canonical Package Reference Location

Every Package covered by this Standard MUST maintain exactly one canonical Package Reference at its Package root.

The canonical path is:

```text
/{PACKAGE_NAME}_PACKAGE_REFERENCE.md
```

The root Package Reference owns the stable public/runtime/behavioral package contract:

- the public Runtime API inventory
- stable behavior and exception guarantees
- package boundaries and non-goals
- links to detailed supporting documentation

Composer-facing identity, metadata, requirements, dependencies, autoloading, scripts, configuration, stability, and distribution declarations are not canonical Package Reference declarations. They are owned by `composer.json` under [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).

The Package Reference MAY explain runtime implications, reference dependency expectations, and document supported integration behavior, but it MUST NOT override or compete with `composer.json` for Composer manifest declarations.

Detailed architecture decisions, proposed implementation contracts, integration guides, roadmaps, deferred scope, and audits belong under `docs/`.

Supporting documents under `docs/` MAY provide deeper detail, but they MUST NOT become a second competing Package Reference. They SHOULD link back to the root Package Reference when they define or explain part of the stable public/runtime/behavioral package contract.

The root Package Reference SHOULD index the relevant detailed documents under `docs/`.

A multi-domain package still has one canonical root Package Reference unless a domain is extracted into a separate Composer package with its own repository and public/runtime/behavioral package contract.

---

## 4. Namespace

Pattern: `Maatify\{PackageName}\`

```
Maatify\LibraryExample\
Maatify\{NextPackage}\
```

*Note: Host app namespaces such as `App\`, `HostApp`, or `OtherApp` are strictly forbidden.*

### Type Naming Convention

Public and internal type names MUST make the declared artifact type immediately clear to implementers and reviewers.

The required suffixes are:

- every interface MUST end with `Interface`
- every enum MUST end with `Enum`
- every Data Transfer Object MUST end with `DTO`
- every exception class MUST end with `Exception`

Exception marker interfaces are not exempt from the interface rule. A new package marker MUST therefore use:

```text
{PackageName}ExceptionInterface
```

The PHP filename MUST match the declared type name.

New packages and unpublished APIs MUST NOT introduce alternative names that omit the required suffix. Package-specific compatibility exceptions are permitted only under the legacy-public-API rule in Section 7.

---

## 5. Directory Structure Inside `src/`

The canonical organizing law is:

```text
Domain → Capability → Responsibility
```

`Responsibility` is a semantic placement owner, not a generic architectural layer. The Package Reference MUST declare exactly one source topology, and that declaration governs placement:

```text
Source Topology: Single Capability
```

```text
Source Topology: Multi Capability

Capabilities:
- ...
```

```text
Source Topology: Multi Domain

Domains:
- ...
```

The only canonical source topologies are:

### A. Single Capability

```text
src/
└── {Responsibility}/
```

### B. Multiple Capabilities

```text
src/
└── {Capability}/
    └── {Responsibility}/
```

### C. Multiple Real Domains

```text
src/
└── {Domain}/
    └── {Capability}/
        └── {Responsibility}/
```

Do not introduce a redundant Domain or Capability directory when the Package itself already represents that boundary. A Responsibility or Capability directory MUST appear only when the artifact owns a real responsibility or capability; empty and ceremonial folders are forbidden.

### Canonical Responsibility Vocabulary

The following names are canonical placement directories when the corresponding responsibility exists. They are not fifteen equal layers and MUST NOT be materialized as a flat tree by default:

```text
Command/
Criteria/
DTO/
Service/
Repository/
Contract/
Adapter/
Exception/
Enum/
ValueObject/
Config/
Factory/
Builder/
Mapper/
Facade/
```

Their meanings are:

- `Command/` — mutation or action intent.
- `Criteria/` — query, search, or filter input.
- `DTO/` — result or data snapshots.
- `Service/` — business or application orchestration.
- `Repository/` — persistence/query boundaries and their implementations.
- `Contract/` — general runtime or boundary contracts only when no more specific responsibility owns the contract.
- `Adapter/` — external, provider, or backend adaptation other than repository persistence.
- `Exception/` — Package- or capability-owned exceptions.
- `Enum/` — Package- or capability-owned enums.
- `ValueObject/` — immutable domain values that are not Commands, Criteria, or DTOs.
- `Config/` — explicit configuration contracts.
- `Factory/` — construction.
- `Builder/` — progressive or domain construction.
- `Mapper/` — explicit transformation or mapping.
- `Facade/` — a thin public discovery or access surface when needed.

### Interface Placement

An interface MUST be placed with the responsibility it describes when that responsibility has a more specific owner. Do not place every interface in `Contract/`.

```text
Repository/
├── RedirectRepositoryInterface.php
└── Pdo/
    └── PdoRedirectRepository.php
```

```text
Builder/
├── JsonLdBuilderInterface.php
├── ProductJsonLdBuilder.php
└── OfferJsonLdBuilder.php
```

Use `Contract/` only for a general boundary that is not more clearly owned by another responsibility, such as a broad Host, provider, or transport abstraction.

### Technology Placement

Technology is not an architecture root for new code. The canonical order is:

```text
Capability → Responsibility → Technology
```

For example:

```text
Repository/Pdo/
Adapter/Redis/
Adapter/Mongo/
Adapter/Smtp/
```

New code MUST NOT introduce technology-rooted alternatives such as `src/Pdo/`, `src/Redis/`, `src/MySQL/`, or `src/Mongo/` merely because an implementation uses that technology. If a technology is intrinsic to the identity of an entire Package, it MUST NOT be repeated as a directory without a placement responsibility.

### Common and Package-Wide Responsibilities

`Common/` is permitted only for genuinely shared, framework-neutral primitives or contracts used by more than one Capability or Domain and not owned more clearly by one of them. It is not a dumping ground. `Shared/`, `Helpers/`, `Utils/`, and `Internal/` are not canonical architecture directories for new responsibilities.

A responsibility that genuinely spans multiple Capabilities MAY be placed directly under `src/`, for example:

```text
src/
└── Exception/
    └── {PackageName}ExceptionInterface.php
```

A capability-specific exception belongs under `{Capability}/Exception/`. The same rule applies to package-wide `Factory/`, `Facade/`, and other responsibilities: root placement is allowed only when the ownership is genuinely package-wide.

### Management and Consumer Use Cases

When Section 15 requires distinct Management and Consumer use cases, the split MUST remain within the capability that owns them. For a single-capability Package, the Package root namespace under `src/` already represents that capability. For multi-capability and multi-domain Packages, use the actual capability path from the declared topology.

```text
{owning capability path}/
├── Management/
│   ├── Command/
│   ├── Criteria/
│   ├── DTO/
│   └── Service/
└── Consumer/
    ├── Criteria/
    ├── DTO/
    └── Service/
```

Do not create root `Admin/` or `Customer/` taxonomies merely for actor labels. Infrastructure Packages MUST use their real domain and capability boundaries.

### Compatibility and Legacy Placement

The canonical structure remains mandatory. Standards Adoption or a Standards Upgrade MUST NOT, by itself, force a compatibility-breaking release solely to normalize source topology. A topology change is governed by the following deterministic decision model.

This model owns source-topology deferral only. It does not define how a Published Public/Consumer Contract is classified, replaced, deprecated, or retired; that lifecycle is owned by `std-public-contract-evolution` when that Standard applies to the Scope, and a Deferred Structural Obligation is not a synonym for any Deprecation.

#### New or Pre-Stable Packages

A new or unpublished Package, and a Package that has not yet reached its first Stable release, MUST remediate a non-compliant `src/` topology before that first Stable release. A Package MUST NOT enter its first Stable release with a knowingly non-compliant source topology when no previously published compatibility contract requires that layout. No legacy compatibility exception may be created from a structure that has not yet been published.

#### Published Packages: Non-Breaking Remediation

Publication alone is not a reason to defer remediation. When the canonical topology can be applied without breaking any existing compatibility boundary, the Package MUST apply it in the current work or the nearest appropriate approved scope. The review MUST consider, as applicable:

- public namespaces and published FQCNs;
- the Composer autoload contract;
- the public Runtime API;
- documented extension, integration, construction, or import paths;
- consumer-visible class and interface names; and
- any other documented compatibility boundary.

If the move does not cross a proven compatibility boundary, Compatibility MUST NOT be used to postpone the canonical placement.

#### Published Packages: Breaking Remediation

If an actual review proves that applying the canonical topology would break an existing compatibility boundary, the current release line MAY retain the existing placement. The required topology migration then becomes a **Deferred Structural Obligation**. The Package MUST NOT create a Breaking Release solely to normalize source topology, and this deferral MUST NOT be treated as cancellation of the canonical requirement.

A Deferred Structural Obligation becomes mandatory at the next Owner-approved release boundary that independently permits the compatibility break required by the migration. The trigger is the approved boundary that actually permits the required contract break, not a major-number increase by itself. When that boundary occurs, every applicable deferred topology obligation MUST be re-evaluated and closed when migration is safe. It MUST NOT be carried automatically to a later Breaking or Major line without a documented reason or an explicit Standards-Adopting Repository exception under this section.

#### Existing Legacy Structure and New Code

The following limits apply while a Deferred Structural Obligation exists:

1. Existing published paths may remain only within the explicitly identified deferred structural scope needed to preserve Compatibility.
2. New responsibilities and new code MUST follow the canonical `Domain → Capability → Responsibility` topology whenever this can be done without breaking an existing contract, creating duplicate competing ownership, or creating an unsound hybrid topology because of the deferred boundary.
3. If placing new code directly in the canonical path would create competing or hybrid ownership because the same deferred namespace boundary has not yet migrated, that code MAY remain temporarily inside the same explicitly bounded and documented legacy structural envelope until the migration trigger.
4. This allowance MUST NOT create new legacy roots or taxonomies for convenience. The legacy envelope MUST be the existing one identified by the proven compatibility boundary.
5. The allowance MUST NOT extend to Domains, Capabilities, Responsibilities, paths, or namespaces that are not affected by the proven compatibility boundary. An independent new capability MUST use the canonical topology.

Compatibility preservation, supported by specific contract or architecture evidence, MUST be the actual reason for retaining legacy placement. Labels such as `legacy project`, `existing structure`, `too much work`, `not worth changing`, or `keep current src` are not sufficient by themselves.

#### Standards-Adopting Repository Exception

The Consumer Repository adopting this Standard MAY request continued deviation after the migration trigger only through the existing `Explicit Exceptions/Overrides` mechanism in `STANDARDS_ADOPTION_STANDARD_AR.md`. This section does not create a second exception system. The exception MUST be Owner-approved, explicit, adopting-repository-specific, scope-bounded, documented, and auditable, and the Consumer Repository MUST record it in:

```text
docs/php-engineering-standards/STANDARDS_MANIFEST.md
→ Explicit Exceptions/Overrides
```

The Consumer Repository MUST also retain durable repository-owned rationale in the appropriate existing document, such as its Package Reference or an ADR, without creating a new document when an existing canonical owner is sufficient. The exception record MUST identify, at minimum:

- the Standard ID and Section;
- affected paths and namespaces;
- the affected release line;
- the exact deviation allowed;
- the compatibility or architectural evidence and reason;
- the scope boundary;
- the Owner approval; and
- the migration or review trigger.

An unscoped entry such as `legacy structure — exempt` is invalid. A generic legacy label, project age, implementation effort, or organizational preference is not sufficient evidence.

An approved exception MAY permit the specified deviation to continue after its migration trigger, but only within the exact recorded scope. It is not a permanent global waiver, does not renew automatically, does not extend to new paths or namespaces, does not transfer to another Package or release line, and does not exempt unrelated independent responsibilities from canonical placement. It does not make the Package compliant outside the recorded scope and cannot cure Structural Invalidity in Standards Adoption. The exception MUST be re-evaluated at its recorded review or migration trigger; continued deviation after that trigger requires the same still-valid exception or a new or updated Owner decision. There is no automatic renewal or inheritance.

A **Deferred Structural Obligation** is the automatic result of a proven compatibility-breaking migration. An approved adopting-repository exception is a separate, explicit Owner decision that permits a recorded deviation. Neither term may be used as a synonym for the other, and a non-breaking topology remediation MUST NOT be deferred under the exception mechanism.

---

## 6. Schema Rules

These rules, including `schema/`, database structure, database tests, and migrations, are conditionally applicable **only** when the package has SQL persistence or SQL database behavior.

Apply the table and migration rules to assets owned by the Package. If a SQL utility operates on caller-supplied tables, describe the required table and engine characteristics in its Package Reference and verify the behavior against those characteristics under Sections 21 and 25. The caller retains schema ownership.

- Table prefix: `maa_{package_short_name}_` (e.g. `maa_library_example_`)
- Every table needs: `PRIMARY KEY (id)`, proper indexes, meaningful COMMENTs on columns.
- All policies (soft delete, display order, FK behavior, uniqueness) documented in the SQL header.
- The canonical location for SQL, schema, migration, and install assets is the Package-root `/schema/` directory, outside `src/`.
- `schema/README.md` MAY index the SQL and install assets stored under `/schema/`.
- PHP persistence and query runtime code remains under the applicable source topology, normally under `Repository/` or another responsibility that owns it.
- `src/{Domain}/Database/` is not an alternate canonical location for SQL or schema assets.
- **No generic shared `logs` or `event_logs` tables**; use strict domain-isolated tables.
- No FK constraints or JOINs to host app tables — use `COMMENT 'Host-provided ID. No FK.'`.

---

## 7. Exception Rules

### Standard Exception Types

1. Every package-defined exception MUST use the appropriate `maatify/exceptions` hierarchy.
2. Every package MUST expose a package marker interface extending `\Throwable`.
3. Stable, package-owned failure classifications SHOULD use named package exceptions.
4. A known domain or storage condition MAY be converted to a named package exception when the package owns a stable semantic classification.
5. Unknown or external `PDOException` / `Throwable` instances MAY propagate unchanged when preserving the original diagnostic contract is intentional.
6. A package MUST document whether it wraps or propagates infrastructure errors.
7. Blind catch-all wrapping is forbidden.
8. Swallowing errors is forbidden.
9. When wrapping, preserve the original throwable as `previous` where supported.
10. Transaction catch blocks MUST attempt rollback only for an owned transaction that is still active and preserve the original throwable under the Transaction Pattern below.
11. Rethrowing the original throwable after rollback is valid and is not a violation of named-exception rules.
12. Packages MUST NOT be universally required to convert every external infrastructure failure into a package-defined exception. The chosen wrapping or propagation contract must follow the package-owned semantic boundary and be documented.

### Interface

```php
interface SomethingExceptionInterface extends \Throwable
{
}
```

The canonical package marker location is:

```text
Maatify\{PackageName}\Exception\{PackageName}ExceptionInterface
```

Every package-defined exception MUST implement this marker directly or indirectly. External `PDOException` or other propagated infrastructure throwables MUST NOT be forced to implement the package marker.

### Legacy Published Marker Names

A package that already published a stable marker interface without the required `Interface` suffix MAY retain that name when renaming it would require an otherwise unnecessary major release.

This exception:

- MUST be documented explicitly in the root Package Reference
- MUST identify the affected major release line
- MUST remain internally consistent throughout that major line
- MUST require every package-defined exception added during that major line to implement the retained marker directly or indirectly
- MUST NOT introduce a parallel marker solely to normalize naming
- MUST remain package-specific and MUST NOT be copied into new packages
- MUST be reconsidered only during a separately approved, meaningful future major release

Release-version selection, including whether a legacy marker rename warrants a Major release, is governed by [`LIBRARY_PRESENTATION_STANDARD.md`](LIBRARY_PRESENTATION_STANDARD.md).

### Example: Package-Defined Storage Exception

```php
final class SomethingStorageConnectionException extends \Maatify\Exceptions\Exception\System\SystemMaatifyException implements SomethingExceptionInterface
{
    protected function defaultErrorCode(): \Maatify\Exceptions\Contracts\ErrorCodeInterface
    {
        return \Maatify\Exceptions\Enum\ErrorCodeEnum::DATABASE_CONNECTION_FAILED;
    }
}
```

Named constructors SHOULD be used when a stable semantic constructor exists.

Direct construction MAY be used when no suitable named constructor exists and the package's documented exception contract permits it.

Call sites MUST NOT construct generic or semantically misleading exceptions merely to avoid defining an appropriate package-owned classification.

### Fail-Open / Fail-Closed Behavior

Behavior must stay domain-specific:
- **Authoritative Domains** (e.g. `{AuthoritativeDomain}`) are fail-closed where required by the domain.
- **Non-Authoritative Domains** may fail-open only at an explicitly documented boundary.
- **Repositories and Read Queries** must never silently swallow storage failures.

### What the package catches and converts

- `PDOException::getCode()` represents the SQLSTATE, not a Driver-specific error code.
- SQLSTATE Class `23` is a broad category for Integrity Constraint Violations (e.g., Duplicate Key, Foreign Key, Explicit NULL), not just duplicates alone.
- It is forbidden to blindly map all `23xxx` errors to an `AlreadyExists` or Duplicate exception.
- Mapping to a duplicate-key exception requires documented driver-specific evidence of a duplicate key. Mapping to a field-specific conflict also requires evidence that the operation failed on that field's unique constraint.
- `PDOException::$errorInfo` might be `null`, so you must check for the existence of `[1]` safely before using it.
- The MySQL/MariaDB driver uses code `1062` to identify a Duplicate Key. Any other Driver or DBMS must use its own official documented code and must not blindly copy `1062`.
- Any error not explicitly classified by a documented evidence must propagate as-is.
- Retain the `previous` exception when your wrapping exception contract supports it, but do not invent a new named constructor signature solely for that purpose.

This MySQL/MariaDB example assumes the operation and schema establish that a duplicate-key failure can only be a conflict on `code`. If another constraint can also produce that failure, preserve the original exception unless the actual constraint can be identified under the documented mapping. The named constructor in this example supports retaining the cause.

```php
} catch (\PDOException $e) {
    $driverCode = $e->errorInfo[1] ?? null;

    // MySQL/MariaDB duplicate-key driver code.
    if (in_array($driverCode, [1062, '1062'], true)) {
        throw SomethingCodeAlreadyExistsException::withCode($command->code, previous: $e);
    }

    throw $e;
}
```

### Transaction Pattern

The example owns the transaction it starts. Use this pattern at the documented transaction boundary. Check the transaction-control return values even when a driver can also throw. The package-defined exception factories below illustrate how to report a start or commit failure signalled by `false`.

```php
if ($this->pdo->beginTransaction() === false) {
    throw SomethingDatabaseException::transactionStartFailed();
}

try {
    // ...
    if ($this->pdo->commit() === false) {
        throw SomethingDatabaseException::transactionCommitFailed();
    }
} catch (\Throwable $e) {
    try {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    } finally {
        // Preserve the primary failure even if cleanup fails.
        throw $e;
    }
}
```

Only package-owned transactions are rolled back by this pattern.
Rollback must be attempted only while the transaction is active.
The operations inside the boundary must be rollback-capable on the supported engine. Account for statements that can implicitly commit, such as MySQL DDL, before claiming that the whole operation is atomic.
The original throwable is rethrown unless an explicitly documented semantic conversion is performed, even if checking transaction activity or attempting rollback throws.
Swallowing the original throwable is forbidden.
If a semantic conversion wraps the original throwable, the original should be retained as `previous` where supported.

## 8. Command Rules

For public contracts accepting IDs as raw `int|string` input, accept only a canonical positive integer representation. Reject floats, booleans, null, signs, whitespace, leading zeroes, decimal notation, and scientific notation; check bounds before casting to `int`.

Validate at the entry point that receives the raw value, before a scalar parameter declaration or cast can coerce it. PHP's scalar strictness follows the calling file, so a package's `strict_types` declaration alone cannot enforce the raw input contract for all callers.

For strict, known-format date-only input, use `\DateTimeImmutable::createFromFormat()` and require an exact round-trip string match so normalization or overflow is rejected. Inspecting `getLastErrors()` MAY provide an additional check. This does not prohibit general free-form date-time parsing with `\DateTimeImmutable`.

Commands are self-validating value objects:

```php
final readonly class CreateSomethingCommand
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
        public ?string $notes,
    ) {
        if (trim($code) === '') {
            throw SomethingInvalidArgumentException::emptyField('code');
        }
        if (trim($name) === '') {
            throw SomethingInvalidArgumentException::emptyField('name');
        }
    }
}
```

Rules:
- `final readonly` — always
- A Command represents mutation or action intent. It validates its input contract in the constructor and MUST NOT perform business orchestration.
- Query, search, and list filters use a `Criteria` or another explicit query contract; result or data snapshots use DTOs. An object representing execution intent MUST NOT be named a DTO.
- A small operation with one or two typed inputs MAY use typed parameters directly when a Command would add no meaningful contract.
- Display-order inputs MUST follow Section 16; `CreateCommand` and `UpdateCommand` do not accept `display_order`
- Host IDs (e.g. `methodId`, `currencyId`) MUST follow the canonical positive-ID contract in this section.
- Image/media inputs MUST follow the conditional ownership and assignment contract in Section 17; this Standard does not require every domain to have an image field or a dedicated image operation.
- Monetary and fixed-precision decimal inputs MUST follow Section 19
- General/free-form date-time strings MUST be validated with `new \DateTimeImmutable($value)` in a try/catch. The Package contract determines whether empty values, relative expressions, or normalized dates are valid inputs. A successful parse alone does not establish that those inputs are allowed. Strict known-format date-only inputs follow the round-trip rule above.

---

## 9. DTO Rules

DTOs represent data snapshots or results and MUST NOT be used as substitutes for Commands or other execution-intent contracts. Query filters belong in a `Criteria` or another explicit query contract, not in a result DTO.

```php
final readonly class SomethingDTO implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
```

- `final readonly` — always
- Implements `\JsonSerializable`
- Collection DTOs implement `\IteratorAggregate` + `\JsonSerializable`:

```php
/** @implements \IteratorAggregate<int, SomethingDTO> */
final readonly class SomethingCollectionDTO implements \IteratorAggregate, \JsonSerializable
{
    /** @var list<SomethingDTO> */
    private array $items;

    /** @param list<SomethingDTO> $items */
    public function __construct(array $items)
    {
        $this->items = $items;
    }

    /** @return \ArrayIterator<int, SomethingDTO> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function jsonSerialize(): mixed
    {
        return $this->items;
    }
}
```

---

## 10. Repository Rules

### Command Repository Return Types

| Operation | Returns | Why |
|---|---|---|
| `create()` | `int` | Validated identifier of the created entity |
| `update()` | `bool` | Documented mutation outcome; changed rows alone do not establish target absence |
| `updateStatus()` | `bool` | Documented mutation outcome; changed rows alone do not establish target absence |
| `updateDisplayOrder()` | `bool` | delegated to `Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager` from `maatify/php-persistence` |
| `updateImage()` | `bool` | Documented mutation outcome; changed rows alone do not establish target absence |
| `softDelete()` | `bool` | Documented deletion/lifecycle outcome, including any already-deleted condition |
| `hardDelete()` | `bool` | Documented deletion outcome. If the contract reports whether a row was deleted, use the successful delete statement's result |
| `findById()` | `?DTO` | `null` if not found — Service decides whether to throw |

This table defines Repository-level return contracts. A Service MAY expose `updateDisplayOrder(...): void`, call the Repository operation, and convert `false` into the approved not-found exception only when the Repository contract establishes that `false` means the target is missing, as required below and in Section 13. The Repository and Service signatures belong to different layers and MUST NOT be treated as competing alternatives.

When using PDO `lastInsertId()`, verify that the result identifies the newly created row, is representable as a PHP `int`, and falls within the Package's documented identifier range before conversion. Follow the supported backend's ID retrieval and transaction timing. A failed statement or failed ID retrieval cannot provide a successful `create()` result.

### Mutation Outcomes and Missing Targets

A boolean mutation result MUST document whether it reports changed state, a matched target, or another domain-specific outcome. A changed-row contract MAY return `false` for an unchanged existing target; that result MUST NOT be interpreted as proof that the target is missing. Zero affected rows alone cannot distinguish an unchanged existing target from a missing target. A failed conditional update or compare-and-swap condition likewise MUST NOT automatically be classified as target absence.

A Service MAY convert a Repository `false` result to a not-found exception only when the documented Repository contract and its implementation establish that `false` means the target is missing. It MUST handle all other results according to the actual mutation contract. This does not mandate success for every no-op or redefine domain-specific lifecycle, conditional-update, or conflict outcomes.

The implementation's evidence of absence MUST remain valid within the operation's documented concurrency and transaction boundary. An unprotected existence precheck followed by a separate write is not sufficient proof of the write outcome. This Standard does not prescribe a universal SQL algorithm, connection option such as `ATTR_FOUND_ROWS`, or new public result type.

### `findById` Pattern

Return `null` only after a successful query establishes that no row was found. This example assumes the injected connection uses `PDO::ERRMODE_EXCEPTION`. If the package supports a PDO mode that reports errors through return values, it MUST distinguish failed preparation, execution, or fetch from a successful query with no matching row under Section 7. Keep the documented connection configuration and error contract at the integration boundary.

```php
/** Finds an item by ID. Returns null after a successful query with no matching row. */
public function findById(int $id): ?SomethingDTO
{
    $stmt = $this->pdo->prepare('SELECT id, name FROM maa_example_items WHERE id = :id LIMIT 1');
    if ($stmt === false || $stmt->execute(['id' => $id]) === false) {
        throw SomethingDatabaseException::readFailed();
    }

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    return $this->hydrateDetail($row);
}
```

### Hydration — never cast `mixed` directly

Validate the stored representation and range before use. This example expects a required integer column and a required boolean encoded as `0` or `1`. A missing or malformed required value is a hydration failure. Apply defaults only when the domain contract expressly defines them.

```php
$isActive = $row['is_active'] ?? null;
$displayOrder = $row['display_order'] ?? null;

if (!in_array($isActive, [0, 1, '0', '1'], true)) {
    throw SomethingHydrationException::invalidField('is_active');
}

$order = is_int($displayOrder) || is_string($displayOrder)
    ? filter_var($displayOrder, FILTER_VALIDATE_INT)
    : false;
if ($order === false) {
    throw SomethingHydrationException::invalidField('display_order');
}

return ['isActive' => $isActive === 1 || $isActive === '1', 'displayOrder' => $order];
```

---

## 11. Shared Ordering and Pagination Utilities

This section governs how standalone Maatify packages consume shared PDO ordering and pagination capabilities. It is a dependency and integration policy, not an implementation blueprint.

The authoritative owner is:

```text
Package:    maatify/php-persistence
Repository: https://github.com/Maatify-ltd/php-persistence
```

### Domain Query and Pagination Ownership

The Package owns its domain query semantics, including searchable fields, filters, matching and escaping behavior, count semantics, and the public result contract. `maatify/php-persistence` owns only the reusable pagination and ordering mechanics exposed by the eligible published public API defined below.

Management list/search collections that do not have a clearly bounded small maximum MUST provide pagination from the start. A Host MUST NOT load an unbounded result set and paginate it after retrieval. The Package owns the alignment of count and data queries and the meaning of each page; shared mechanics remain delegated to the eligible published `maatify/php-persistence` API.

### Non-Duplication Rule

A consumer package MUST NOT create a local replacement for ordering or pagination behavior provided by `maatify/php-persistence`.

Forbidden duplication includes:

- ordering managers, position-shifting algorithms, scope-locking logic, and ordering transaction behavior
- page and per-page normalization
- total and filtered count orchestration
- pagination offset calculation
- whitelist-based sorting and deterministic tie-breaker behavior
- canonical pagination result objects and metadata
- package-local copies of persistence exceptions or public value objects

A consumer package MUST NOT copy Runtime code, tests, internal algorithms, or documentation-only contracts from `maatify/php-persistence`.

### Capability Availability and Composer Dependency

The approved shared consumption line for `maatify/php-persistence` is Stable Major 1, from `1.0.0` inclusive to `2.0.0` exclusive. Every applicable dependency range MUST stay within this line. Section 11 applies to packages that need the shared capabilities defined above. A package outside that applicability has no Persistence dependency obligation under this section.

#### Publication and RC Transition

`1.0.0` is the designated first-Stable target and the central floor. Official Stable consumption starts only after actual publication of `1.0.0`. For the selected release, verify that the exact package identity and version are externally resolvable and installable through an approved Composer source, that the release and Composer metadata identify the same source revision, and that the Package Reference at that revision documents the required capability. Apply the Publication State definition in [Library Presentation Standard §14](LIBRARY_PRESENTATION_STANDARD.md#14-first-stable-release-lifecycle-and-security-presentation-states). An unreleased branch, commit, proposed contract, tag alone, or target version does not qualify as an available runtime API.

Retain evidence of RC trials previously authorized under central upstream `DEC-049` as historical evidence. Before first-Stable publication, any existing trial remains bounded by its prior authorization. This policy grants no new RC trial, extension, or RC dependency, including for RC.3. An RC cannot qualify a consumer's Stable release. Once `1.0.0` is actually published, RC trial eligibility under this Persistence contract ends. Reconcile affected consumers to an eligible Stable Major 1 release before adoption, upgrade, or qualification under this policy. Keep historical trial records and existing pins intact until a separately authorized reconciliation. Publication and this policy change leave the [existing Adoption HOLD (#208)](https://github.com/Maatify-ltd/php-engineering-standards/issues/208) in place. The Technical Lead reviews readiness and presents the reconciliation and HOLD-lift plan to the Owner for separate approval.

#### Dependency Declaration and Compatibility Range

Under the [Composer Package Standard](COMPOSER_PACKAGE_STANDARD.md) §15, Persistence is a direct `require` when any mandatory runtime path depends on it. If an independently selectable implementation qualifies under §15.4, keep the optional declaration in `suggest` and disclose the supported range in the Package Reference and Composer suggestion. Selection of that capability requires the same published Stable Major 1 line and the documented capability-unavailable failure when the prerequisite is absent, as specified by §15.4.

A consumer MUST set the lower bound of its declared or disclosed compatibility range at the lowest verified compatible Stable release that provides all required public APIs, behavior, guarantees, and fixes. Declare a caret range from that floor up to the end of Major 1. Use `"maatify/php-persistence": "^1.0"` when the contract is supported from `1.0.0`. For hypothetical later capabilities, `^1.2` has a floor of `1.2.0` and `^1.2.3` has a floor of `1.2.3`, for example when a correctness fix first appears there. Support claims MUST match the verified floor. A newer development or verification resolution alone does not justify raising it.

The lower bound defines the first supported version. A consumer MAY resolve and use any published Stable release within its eligible range, subject to the approved Major line, verified compatibility, the platform and dependency graph, and applicable quality and security gates. For example, a consumer with `^1.0` may use a compatible Stable `1.9.0` while keeping `1.0.0` as its floor.

An exact pin, patch-only or minor-only range, or internal upper bound requires a proven incompatibility and a temporary, narrowly scoped exception under Composer Package Standard §§17–18. Record the evidence, impact on Composer resolution with other consumers, any required Owner decision, the remediation in the owning package, and the condition for removing the restriction. Any such exception stays within Stable Major 1. Wildcard package constraints and floating refs are forbidden for this dependency.

Apply Composer Package Standard §24 with `minimum-stability: stable` and `prefer-stable: true`. Do not use stability flags, aliases, or branches to force a prerelease. Verify the version Composer actually resolves under the tested root configuration. A caret range alone does not establish Stable resolution in every root.

The declared range describes the consumer's compatibility. Composer selects one release of this package identity per root, subject to all dependency constraints and platform requirements in the graph. Independent projects may resolve different compatible releases. Under [CI Workflow Standard §6](CI_WORKFLOW_STANDARD.md#6-dependency-compatibility), verify the lowest-supported and latest-compatible resolutions and record the actual resolved Persistence version in each. The latest compatible selection depends on the graph and platform. Diagnose an unsupported bound or incompatibility in the contract or package that owns it.

A compatible Persistence Patch or Minor requires no consumer release while the existing range and support claims remain correct. Actual application dependency and lock upgrades follow the application's verification and upgrade plan. Reusable library lock files remain governed by Composer Package Standard §25.

#### Major Preservation and Future Line Changes

Persistence remains the source of truth for its public API and behavior. Preserve published contracts across Major 1 Patch and Minor releases, including compatible additions and staged replacement and deprecation under `std-public-contract-evolution`, central Decisions `DEC-054` and `DEC-056`, and [Library Presentation Standard §14.6](LIBRARY_PRESENTATION_STANDARD.md#146-major-version-preservation).

A different Major, including a dual-Major range such as `^1.0 || ^2.0`, requires a new explicit central architectural decision. Before changing the approved line, establish the architectural need and plan the migration for all affected direct and indirect consumers and their compatibility graphs. Include integration, publication order, verification, and removal of the prior line. Any authorized overlap needs an explicit scope, a deadline or closing condition, and evidence of conflict-free resolution under the existing quality gates. A single Composer root resolves only one version of the shared package. After approval and completion of the transition, the previous line ceases to qualify for architectural consumption. Preserve previously published releases.

Each affected Stable consumer MUST assess its published dependency contract under the existing SemVer and Major Preservation rules. A change that breaks that contract, such as replacing an existing published Major 1 range with an exclusive Major 2 range, requires the consumer's own appropriate Major release. Its Major number follows its own contract. Apply compatibility-preserving evolution where possible. Unaffected consumers and changes proven compatible carry no automatic Major bump. A Host follows SemVer only where it applies to that Host.

First-Stable eligibility, release authorization, and any Owner-authorized exception remain governed by the existing release contracts, including central Decisions `DEC-023`, `DEC-030`, and `DEC-057`.

### Ordering Integration

In this section, `Ordering` means reusable row-position and display-order management exposed by `Maatify\PhpPersistence\Ordering`. It does not include ordinary consumer-owned read-query sorting expressed through `ORDER BY`.

Reusable row-position and display-order operations covered by the eligible published `maatify/php-persistence` Ordering API MUST use:

```text
Maatify\PhpPersistence\Ordering
```

The consumer Repository / Service may:

- provide the PDO connection
- construct trusted ordering configuration from its own table and column identifiers
- pass domain ids, scope values, and requested positions
- translate the persistence result into its own existing public contract through a thin adapter

The consumer MUST NOT reproduce ordering SQL shifts, transaction ownership, scope locking, identifier validation, or persistence exception behavior locally.

**Integration Boundaries:**
- When concurrent position allocation matters, the caller MUST provide the transaction and serialization required by the selected API and backend. For a plain read that uses a transaction snapshot, acquire the serialization lock before that snapshot is established. A locking read of the last row alone cannot protect the first allocation in an empty scope.
- For moves, verify that the selected API, storage engine, indexes, and constraints support the required locking and rollback behavior. In particular, a scope/position uniqueness constraint can reject an intermediate write even if the final order is unique. If the selected API cannot preserve a required invariant, apply the Missing Capability rule below.
- The caller retains final commit and rollback ownership of its own transaction. An operation-local savepoint protects the move's writes while the caller's transaction remains active under the selected release's documented conditions. Preserve that boundary and the documented PDO failure contract through any adapter. Apply the missing-target rule in Section 10.

### Pagination Integration

Paginated Repository / QueryReader operations MUST delegate reusable pagination mechanics to the eligible published public API under:

```text
Maatify\PhpPersistence\Pagination
```

`PdoPaginator` can run inside a caller-owned transaction. The caller controls transaction start, completion, and isolation.

The count and data queries run separately. If the Package promises results from one snapshot, it MUST establish the backend and transaction/isolation conditions specified by the selected release for a consistent read. An active transaction by itself does not establish that guarantee.

The consumer remains responsible for domain-owned concerns:

- mandatory security, tenant, ownership, visibility, and soft-delete constraints
- optional search and filter construction
- JOINs and selected columns
- trusted SQL and matching parameter values
- semantic alignment between count and data queries
- row mapping into the consumer's array or DTO
- preserving its own existing public response contract

`maatify/php-persistence` owns the reusable mechanics exposed by its eligible published API, including normalization, count execution, deterministic sorting, limit/offset handling, mapper invocation, and canonical pagination metadata.

Adopting the shared paginator MUST NOT silently change an existing endpoint, DTO, or package return shape. A thin consumer-owned adapter MAY preserve an established public contract.

### Thin Adapters

A consumer-owned adapter is permitted only when it:

- translates domain inputs into the eligible published `maatify/php-persistence` API
- delegates the shared operation without reimplementing its algorithms
- maps the returned result into an already approved consumer contract
- adds no competing exception hierarchy, pagination engine, or ordering engine

An adapter MUST NOT become a fork of the shared capability.

### Missing Capability

When a required reusable behavior is absent from the eligible published public API, the default action is to propose and review the capability in `maatify/php-persistence`.

A package-local alternative is forbidden unless an explicit owner-approved architectural decision proves that the behavior is domain-specific and outside the shared package's scope.

Temporary copying of an unpublished persistence contract is not an acceptable workaround.

### Source of Truth

Consumer packages MUST follow the public API, package reference, and published integration documentation for the selected eligible release of `maatify/php-persistence`.

Exact class signatures, internal formulas, SQL assembly rules, exception classifications, and verification requirements belong in the owning repository. They MUST NOT be duplicated in this general package-building standard or independently redefined by consumer packages.

---

## 12. Translation Pattern

Translation support is **CONDITIONAL**, not a universal Package requirement. A Package MUST implement translation only when translation is part of its actual domain contract. Packages without a translation requirement MUST NOT add translation APIs, fields, persistence, joins, or fallback behavior solely to conform to this Standard.

### Domain-Owned Translation Contract

When translation is part of the domain, the Package Reference and Architecture for that Package are authoritative for:

- ownership of localized fields
- translation identity
- fallback semantics
- mutation semantics
- query contracts, including result cardinality, filtering, and search behavior

The general Package Standard must not force a package to invent localized base fields, fallback behavior, actor-specific query shapes, or mutation semantics that are not part of its domain contract.

Accordingly, this Standard does not require any particular translation implementation, including:

- a base localized value
- `listWithoutTranslation()`
- `language_id` specifically
- `COALESCE(translated, base)` fallback
- Upsert as the only mutation
- fixed Admin/Customer query shapes
- fixed search fields
- comparison with a base name

### Unified Same-Shape Content Pattern

Translation remains optional. When localized and non-localized values are the same concept with the same shape, a unified content relation with a nullable language code is preferred over separate relations with duplicate structure.

A stored `NULL` language code represents the exact NULL-language scope. In SQL, select that scope with `language_code IS NULL` or an equivalent NULL-safe predicate on the supported engine. Fallback applies only when the domain contract defines it. A separate translation relation remains appropriate when structure, lifecycle, identity, or cardinality materially differs. This preference MUST NOT create a translation capability in a domain that does not need one.

### Host-Owned Language Identity

If language or locale identity is owned by the Host, the Package MUST NOT create a foreign key to, or a JOIN with, Host tables, in accordance with the Package isolation rules. The Package must use only the identity and integration contract exposed to it without coupling its persistence to Host table structure.

### Translation Query Cardinality

Any query whose contract promises one row per entity MUST constrain the translation relation to one logical translation identity before joining, or avoid joining translations. An unrestricted JOIN that can return multiple translations for one entity is not permitted for a single-row-per-entity contract. A query may return multiple translations only when its contract explicitly defines a translation collection and its result shape supports that cardinality.

### Translation Persistence Invariants

If the Package owns persistence for translations:

- the logical translation identity MUST be documented in the Package Reference or Architecture
- the persistence schema MUST enforce that identity with uniqueness constraints appropriate to the domain contract, including the NULL-language scope when it participates in the identity. Apply the engine-specific verification in Section 24
- the mutation behavior MUST follow the domain contract; this Standard does not prescribe Upsert or any other single mutation strategy

---

## 13. Service Rules

Responsibility:
- **Business orchestration** lives in Services
- **Input validation** lives in Commands; query filters are validated by their `Criteria` or query contract
- **SQL** lives in Repositories or package-local/domain-local SQL support builders
- **Display formatting** does not belong in Repositories or query layers; those layers return raw domain/database values for the Host's presentation layer.

The mutation example below assumes a documented Repository contract in which `false` means a missing target and `true` includes an accepted unchanged update. The Repository implementation must establish that meaning under Section 10; a boolean meaning only "rows changed" does not satisfy this example's precondition. Other mutation contracts retain their own documented outcome handling.

```php
// Command service — Repository false is proven to mean a missing target.
public function update(UpdateSomethingCommand $command): void
{
    $targetFound = $this->commandRepo->update($command);
    if ($targetFound === false) {
        throw SomethingNotFoundException::withId($command->id);
    }
}

// Query service — throws NotFoundException when repo returns null
public function getById(int $id): SomethingDTO
{
    $dto = $this->queryReader->findById($id);
    if ($dto === null) {
        throw SomethingNotFoundException::withId($id);
    }
    return $dto;
}
```

Services MUST keep SQL and repository construction outside orchestration. Inject the required collaborators. A Service that directs an atomic workflow uses the documented transaction boundary under Sections 7 and 25. The transaction owner handles cleanup and the Service lets failures propagate.

---

## 14. Management and Consumer Separation

Apply the distinct-use-case test in Section 15 and the owning capability placement in Section 5. Infrastructure Packages organize their public API by the real domains and capabilities they own. Host-specific actor labels belong at the integration boundary.

---

## 15. Operational Read / Reporting and Admin Query API Rules

Operational Read / Reporting applicability is determined by package ownership, not by the mere use of SQL or a database. A Package that owns persisted domain or operational state — such as orders, balances, subscriptions, jobs, statuses, events, assignments, or equivalent package-owned records — is automatically **In Scope** for Operational Read / Reporting. Once a Package is In Scope, it MUST NOT classify this capability as Not Applicable. A Package that uses persistence only as an infrastructure mechanism and owns no persisted domain or operational semantics of its own is **Out of Scope** and is not required to invent metrics or a reporting API. The Package Reference or Architecture MUST document the classification and its reason.

Every In-Scope Package MUST expose stable, meaningful, framework-agnostic PHP Operational Read / Reporting contracts that let the Host understand, manage, or monitor the state owned by the Package without direct coupling to the Package's tables. The capability is domain-specific and may provide listing, search, status summaries, counts, aggregates, grouped results, time-window summaries, audit reads, or monitoring reads when those semantics are meaningful and stable for that domain. A generic `dashboard()` method, `ReportService`, `Dashboard/` namespace, or generic metrics DTO MUST NOT be added merely for compliance.

Operational Read / Reporting queries MUST be read-only: they MUST NOT change Package state or become an alternate mutation path. Query filters and result snapshots MUST use the canonical `Criteria/`, `DTO/`, `Service/`, `Repository/`, and `Contract/` responsibilities from this Standard only when those responsibilities genuinely apply. This requirement does not create a separate Reporting source taxonomy, shared Reporting Standard, reusable cross-package reporting abstraction, or universal metric/result shape.

When management and consumption are distinct domain use cases, the Package MUST expose a Management API and a Consumer API with separate contracts. Management APIs may support administrative search, filters, statuses, deleted-state visibility, and pagination. Consumer APIs expose only records valid under the Package's domain visibility invariants; the Host MUST NOT be left to reproduce those invariants through generic filters. If the two uses are not meaningfully distinct, a Package MUST NOT create duplicate APIs merely to satisfy this pattern.

Operational or management reporting that is not consumer-facing belongs on the management/operational read side. Existing Management or Consumer read/query contracts MUST be reused when they can clearly carry the capability; a third duplicate API layer MUST NOT be created merely because the capability is called reporting.

**Important:** This refers strictly to **PHP-level APIs** (e.g., PHP interfaces and DTOs), not HTTP APIs.

These contracts may cover, when meaningful and appropriate for the package's domain:
- Admin listing
- Search
- Status summaries, counts, aggregates, or grouped results
- Time-window, audit, or monitoring reads

The public read/reporting surface MUST be sufficient to prevent the Host from querying Package tables directly to reconstruct Package-owned semantics, reimplement Package invariants, or interpret Package-owned status/lifecycle from internal storage. This is not a blanket prohibition on persistence ownership outside this context.

Operational Read / Reporting inside a reusable Package is limited to data and meanings owned by that Package. It MUST NOT join Host tables, use Host foreign-key coupling, resolve Host actors or names, interpret Host-owned lifecycle/status, read another Package's internal storage instead of its public API, or duplicate reporting semantics owned by another Package. Cross-Package and Host-specific aggregation belongs to Project-Aware or Host scope.

**Explicitly forbidden inside the package:**
- HTTP controllers
- Routes
- Middleware
- Host actor permission infrastructure
- UI dashboards
- CSV/PDF/Excel exports
- Host-specific actor/name resolution
- JOINs/FKs on host tables

Transport, presentation, permissions, and export concerns remain at the Host/Slim/Project-Aware layer. `Dashboard` describes a consumer use case; it is not a canonical Package architecture name.

For an In-Scope Package, the Package Reference or Architecture MUST document, as applicable: persisted domain/operational ownership and the applicability reason; persisted concepts with operational/reporting value; stable public read/reporting contracts; meaningful aggregate, filter, or time-window semantics; ownership boundaries and non-goals; and intentionally unsupported reporting dimensions that matter to consumers. This requirement does not redefine the Documentation Lifecycle or create a second documentation owner.

When a reporting result depends on persisted state, the aggregate, count, filter, and time-window semantics that actually exist MUST have Integration evidence on the actual supported persistence engine. Unit mocks alone are insufficient to prove persisted result semantics. System/E2E applicability remains owned by `TESTING_STANDARD.md`, and provisioning and service lifecycle remain governed by `CI_WORKFLOW_STANDARD.md` §11.

---

## 16. display_order Rules

When SQL-persisted entities expose mutable row-position or display-order behavior, the Package MUST consume the public Ordering API from `maatify/php-persistence` under the applicability and release eligibility rules in Sections 1 and 11.

The package-level integration contract is:

- `display_order` MUST NOT be accepted by `CreateCommand` or `UpdateCommand`
- creation-time position assignment MUST delegate to that Ordering API when automatic assignment applies
- movement MUST be exposed through a dedicated package operation rather than a generic update command
- the Command Repository operation MUST return `bool` with documented semantics. When it exposes `moveWithinScope()` directly, `true` includes a successful no-op, and `false` can mean a missing target or a zero-row target update
- a Service MAY expose a `void` operation and convert a Repository `false` result into the approved not-found exception only when the Repository contract establishes that `false` means a missing target, under Section 10
- shifting, clamping, scope locking, transaction ownership, identifier validation, and persistence exception behavior MUST remain delegated to `maatify/php-persistence`
- a consumer MUST NOT reproduce or fork the Ordering engine locally

Exact class names, method signatures, transaction behavior, and Runtime semantics are owned by the selected release’s public API and Package Reference as described in Section 11.

---

## 17. Image and Media Rules

Image and media support is conditional. A domain MUST NOT be required to introduce an image field or Media subsystem merely to comply with this Standard.

### Simple Image Value

A scalar path or URL is appropriate only when the domain treats the image as a simple value and does not require media lifecycle, roles, scopes, ordering, a default assignment, processing, or storage ownership. In that case the Package Reference defines the value and mutation semantics. This Standard does not impose `VARCHAR(255)`, null-clearing behavior, or a dedicated operation as universal rules.

### External Media Asset and Domain Assignment

When the domain requires media lifecycle, roles, scopes, ordering, default assignments, processing, or explicit storage ownership, it MUST use an external Media Asset and domain-assignment boundary. The Host/Media system owns upload, storage, processing, and media lifecycle. The Package stores only a stable external media identity (for example, `media_asset_id`) and MUST NOT add Host foreign keys, joins, repositories, or module dependencies to resolve it. The Package owns the assignment semantics it needs, such as role, exact scope, ordering, default, and assignment lifecycle.

Assignment identity fields MUST remain stable under generic updates. A role registry is Package-owned only when role identity or its lifecycle/status is part of the Package's domain invariants. Nullable assignment scopes follow the exact-scope rule in Section 24; `NULL` MUST NOT imply a wildcard or fallback.

---

## 18. Bootstrap / DI Rules

**Composer packages must not require host-specific bindings.**

Rules:
- No Slim/Laravel/Symfony/PHP-DI bindings as package requirements.
- A framework-neutral Factory or Builder is appropriate when internal wiring is non-trivial; a thin public Facade/API may be appropriate when multiple public capabilities make discovery or construction materially clearer.
- These construction patterns are conditional, not boilerplate. A Factory MUST NOT become a Service Locator, and neither a Factory nor a Facade may depend on a framework container.
- Optional Providers, when useful, must be strictly framework-agnostic.
- Host applications are fully responsible for wiring dependencies through their own container or runtime environment.

---

## 19. Decimal / Financial Rules

- Represent monetary values as strings in PHP and use exact-precision storage for persisted monetary values, such as SQL `DECIMAL` where applicable. The Package contract defines the PHP representation of non-monetary fixed-precision values.
- Use BCMath for decimal arithmetic. Declare `ext-bcmath` under the applicable Composer runtime dependency rules in Composer Package Standard §15.2 and §15.4.
- Validate the decimal format against the domain's accepted representation before any BCMath call. The example below checks the lexical form for an unsigned value with at most four fractional digits. Apply the domain's applicable integer-digit limit and value range before calculation.

```php
if (preg_match('/\A\d+(?:\.\d{1,4})?\z/', $value) !== 1) {
    throw SomethingInvalidArgumentException::invalidDecimal($field, $value);
}
```

- Pass the scale explicitly to operations that take a scale argument. The domain defines the scale and rounding semantics for each operation.

---

## 20. PDO Named Placeholder Rule

PDO does not reliably support the same named placeholder more than once per statement.
Every placeholder must appear exactly once per SQL string.

When the same value is needed in multiple subqueries:

```sql
AND gc_al.country_code = :country_code_allow
AND gc_bl.country_code = :country_code_block
```

```php
$params['country_code_allow'] = $countryCode;
$params['country_code_block'] = $countryCode;
```

---

## 21. PHPStan and Testing Rules

### `phpstan.neon`

```neon
parameters:
    level: max
    paths:
        - src
```

The `src` path is mandatory.

When a `tests/` directory exists, it MUST also be included in `parameters.paths`:

```neon
parameters:
    paths:
        - src
        - tests
```

Repositories MUST configure PHPStan from their actual package-owned paths and MUST NOT reference a non-existent path merely to copy this example. Existing package-owned tests MUST NOT be excluded from static analysis.
Follow [CI Workflow Standard §8](CI_WORKFLOW_STANDARD.md#8-mandatory-baseline-quality-checks) for zero-error enforcement, prohibited baselines and `ignoreErrors`, and limits on inline suppression.

### Final Classes and Test Doubles

Follow [Testing Standard §3.6](../testing/TESTING_STANDARD.md#36-final-classes-and-test-doubles) for test doubles and runtime replaceability. Preserve production `final` and `readonly` declarations when the only reason to remove them is to facilitate testing.

A Package MAY use `dg/bypass-finals` as a development-only test tool for a documented need consistent with its test architecture. Its use must preserve the production design and the applicable static-analysis rules. The tool is optional.

### Testing Strategy

- The core testing strategy and regression-protection rules are exclusively governed by the canonical [Testing Standard](../testing/TESTING_STANDARD.md).
- For code with testable behavior, appropriate automated tests are required.
- Packages that own persistence, database, or external-service behavior MUST define appropriate Integration coverage. Unit and Regression suites remain required where applicable.
- Package-owned test behavior, fixtures, and suite responsibilities belong to the package architecture and reference documentation.
- CI execution requirements — including real-service provisioning, MySQL/SQLite enforcement, PHP matrices, cleanup/repeatability checks, and example syntax validation — are governed exclusively by [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md).
- Package readiness requires the complete maintained and applicable test suite to be covered by the appropriate CI quality gate, using the repository's actual maintained test runner and tooling. CI execution and failure enforcement are governed by [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md).

### PDO fetch result annotations

Annotate PDO fetch results with the shape of the selected fetch mode and its actual failure or absence type. An annotation cannot perform result validation or turn a driver failure into an empty result.

```php
/** @var array<string, mixed>|false $row */
$row = $stmt->fetch(PDO::FETCH_ASSOC);

/** @var list<array<string, mixed>> $rows */
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### DTO and Hydration Annotations

Collection generics and list annotations are governed by Section 9. PDO-row hydration and mixed-value extraction rules are governed by Section 10. This section MUST NOT redefine those contracts.

### LIMIT / OFFSET — always PDO::PARAM_INT

```php
// ❌ PDO binds as string by default
$stmt->bindValue(':limit', $limit);

// ✅ explicit type
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
```

### Private method annotations

Use precise PHPDoc types where a native type cannot express a meaningful shape. General documentation coverage is governed by the PHP Source Documentation Standard. The declarations below illustrate type annotations independently.

```text
/** @param array<string, mixed> $row */
private function hydrateListItem(array $row): ?SomeListItemDTO

/** @param array<string, mixed> $row */
private function hydrateDetail(array $row): SomethingDTO

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<SomeDTO>
 */
private function hydrateAll(array $rows): array

/** @return list<string> */
private function fetchRelatedItems(int $parentId): array

/** @return array<string, mixed>|null */
private function findRawById(int $id): ?array
```

---

## 22. CI Workflow Rules

Standalone Packages and extractable Base Artifacts MUST follow [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md) when that Standard applies to the artifact's verification.

`CI_WORKFLOW_STANDARD.md` is the single detailed source of truth for workflow architecture, path relevance, dependency resolution, PHP compatibility matrices, quality checks, real-service Integration execution, security audit, workflow linting, permissions, immutable action pinning, execution reliability, and stable required gates.

Compliance requires the repository's CI to pass the current Compliance Checklist defined by that Standard. This Package Building Standard MUST NOT independently redefine those CI mechanics.

---

## 23. The Package Is NOT Done Until

- [ ] Package-owned persisted domain/operational state and the resulting Operational Read / Reporting classification are documented; Out-of-Scope is used only when no such state is owned.
- [ ] Every In-Scope Package exposes a stable, meaningful, framework-agnostic PHP Operational Read / Reporting surface, and the Host does not need direct Package-table coupling to reconstruct Package-owned semantics.
- [ ] Persisted aggregate, count, filter, or time-window result semantics that exist have Integration evidence on the actual supported persistence engine, under the ownership of the Testing and CI standards.
- [ ] CI workflows exist, pass, and satisfy the current Compliance Checklist in `CI_WORKFLOW_STANDARD.md`
- [ ] Package-owned runtime and test architecture is represented in CI where applicable
- [ ] `README.md`, `CHANGELOG.md`, and other release-facing files comply with `LIBRARY_PRESENTATION_STANDARD.md`.
- [ ] Where the artifact is a reusable Package or applicable Base Artifact, the Usage Guide, `examples/`, `llms.txt`, and the required consumer-facing links are present and accurate under `LIBRARY_PRESENTATION_STANDARD.md`.
- [ ] `{PACKAGE}_PACKAGE_REFERENCE.md` complete — full API, design rules, extension guide
- [ ] `composer.json` complies with [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).
- [ ] Where Section 11 applies, the declared or disclosed range starts at the verified compatibility floor, and the actual resolved Persistence versions are recorded and eligible under the Stable Major 1 policy.
- [ ] The technical consumer workflow and examples meet the requirements in Section 25; their required presentation, navigation, and discoverability follow `LIBRARY_PRESENTATION_STANDARD.md`.
- [ ] The standalone Package or applicable extractable Base Artifact has the reproducible Consumer Verification Harness required by [TESTING_STANDARD.md](../testing/TESTING_STANDARD.md).
- [ ] Every public service/repository capability intended for infrastructure substitution has a matching contract (interface). An infrastructure implementation using the optional prerequisite permission follows the eligibility rules in Composer Package Standard §15.4.
- [ ] Domain-specific failure semantics are documented
- [ ] Boolean mutation meanings distinguish changed, unchanged, missing, and conditional outcomes where applicable; not-found mapping follows Section 10 and is not inferred from zero changed rows alone.
- [ ] Owned transaction failures follow Section 7. Cleanup preserves the primary error, including when the boundary performs a documented semantic conversion.
- [ ] Business orchestration lives in Services, validation in Commands/filters, SQL in Repositories
- [ ] Schema/persistence documentation aligns with the package's declared persistence backend and domain-owned storage structures, with no generic `logs` or `event_logs` storage
- [ ] Framework-agnostic boundaries preserved: no host app namespaces, no framework bindings required
- [ ] No generic logger, recorder, or repository
- [ ] Package-defined exceptions follow Section 7 and use `maatify/exceptions`. A Package that needs a clock abstraction uses `maatify/shared-common` under Section 2.

## 24. Domain Ownership, External References, and Lifecycle

The Package Reference or Architecture MUST make ownership explicit for each domain capability and persisted concept:

- what the Package owns and enforces as a domain invariant
- what the Host owns
- which values are stored only as stable external identities
- which external concepts the Package does not interpret or manage the lifecycle of

A Package MUST create an internal Registry only when the identity, lifecycle, status, or validity of that concept is part of the Package's own invariants. A Host-owned concept that the Package does not own is represented by a stable external ID or key; the Package MUST NOT acquire ownership merely because it stores or receives that value.

Standalone Packages and Base Modules MUST NOT depend on Host foreign keys, Host-owned table joins, Host repositories, or Host modules. Where the Package does not own semantic validation of an external identity, that validation remains with the Host.

Nullable scope fields use exact-scope semantics: `NULL` means only the exact NULL scope. It MUST NOT mean wildcard, fallback, all, default, any language, or any platform unless the domain contract explicitly defines that meaning.

If a nullable scope is part of a unique identity, verify that the chosen constraint on the supported engine rejects duplicates in the NULL scope as well as in named scopes. A composite `UNIQUE` constraint on MySQL, for example, can permit duplicate rows when one of the indexed fields is `NULL`. Use an engine-supported constraint that enforces the documented identity and verify it through applicable persistence Integration tests.

For each entity or assignment, the Package Reference MUST distinguish stable identity fields from mutable business fields, lifecycle fields, and ordering fields. Generic updates MUST NOT change stable identity. Soft Delete is optional and SHOULD be used only when domain history, restore behavior, or identity invariants require it; it is not a universal capability. When a lifecycle is used, its identity, uniqueness, restore, and deletion semantics MUST be documented.

## 25. Runtime Workflow, Transactions, Concurrency, and Clock

Each reusable Package MUST define a realistic technical consumer workflow in its Package Reference or another canonical technical contract document. A Usage Guide MAY present that contract for consumers, but it MUST NOT create a competing technical contract. The documented workflow MUST show the consumer path:

```text
Host Input → Public API → Applicable Orchestration / Integration Boundaries → Observable Result
```

Examples MUST demonstrate supported construction/wiring, a basic workflow, public API use, and the applicable integration boundaries. Show only the layers that participate in the actual workflow. The Package Reference and this Standard own the technical contract. `LIBRARY_PRESENTATION_STANDARD.md` owns the required Usage Guide and examples artifacts and their consumer-facing presentation.

When a domain invariant spans multiple operations, the Package MUST identify the transaction owner and the required transaction, locking, and concurrency boundaries. It MUST state whether an outer transaction is supported when that affects callers. Race-prone invariants—such as ordering, hierarchy, unique defaults, or lifecycle transitions—require concurrency verification when concurrent access is realistic for the domain. The Testing Standard owns the general testing evidence model.

Packages MUST NOT change the global timezone. When the Package needs a clock abstraction, it MUST use the established Clock contract described in Section 2. The Host owns timezone policy unless the domain contract explicitly assigns a different policy to the Package. Repositories MUST NOT silently reinterpret timestamps.

## 26. Extensible Content Fields Pattern

Extensible content fields are conditional and are appropriate only when a domain needs variable content fields alongside its stable core fields. They MUST NOT be added merely to anticipate possible future extension.

When this pattern applies, the domain contract may define a stable `field_key`, a value and typed format, exact optional scopes, deterministic ordering, lifecycle, and uniqueness according to actual domain invariants. Identity fields and scopes that define assignment identity MUST remain stable under generic updates.

The Host owns the meaning and administration of `field_key`, including editor configuration, sanitization, rendering, and fallback, unless the Package's own domain explicitly owns those semantics. The Package MUST NOT create a Field Definition Registry solely because it stores a `field_key`; the Registry rule in Section 24 still applies.

## Version History

### `6.0.0`

- [Issue #213](https://github.com/Maatify-ltd/php-engineering-standards/issues/213) changes the approved Persistence consumption line and replaces RC trial eligibility with a published Stable Major 1 contract. An RC trial compliant under the previous policy cannot qualify as a new trial under this policy, and a previously eligible Stable range can require reconciliation. `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major class).
- Frozen Version Baseline `5.0.0` was established by a completed VALID consumer Upgrade on 2026-11-18, with exact artifact blob `326bfc75603e0a98e0333b8352f1008741608783` at Adoption Commit `1f560795397f24bddcc3d09b982ff93a77f7154e`. The pre-change artifact also includes a clarification of the source-topology deferral boundary after that snapshot. The highest cumulative impact from frozen `5.0.0` is Major. The [Owner finalized the Mutable Candidate at `6.0.0`](https://github.com/Maatify-ltd/php-engineering-standards/pull/214#issuecomment-7132126319) on 2026-11-24.

### `5.0.0`

- Add a pointer in the Compatibility and Legacy Placement section to `std-public-contract-evolution`, clarifying that Deferred Structural Obligation covers source topology only. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class): no obligation or permission changes; absorbed in the existing Mutable Candidate calculated from the Frozen Version Baseline. No Version Finalization, Freeze, release, or Adoption is claimed.
- [Issue #198](https://github.com/Maatify-ltd/php-engineering-standards/issues/198) qualifies Frozen Version Baseline `4.0.0`, artifact blob `290907492e48f11cc599d9ff94c567ea504855d2` at Adoption Commit `d3dd98b8d82c8bcdd65614fe4ed80853bdcdcc73`. A consumer completed VALID Adoption on 2026-11-14 with that exact artifact, before PR #197 changed it. The entries under `4.0.0` record the earlier calculation through that frozen snapshot.
- Section 1 extends general transaction ownership and concurrency rules to applicable non-SQL persistence behavior. `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major class). A Package with non-SQL workflows that span multiple operations may now need to document and verify those boundaries under Section 25.
- Section 10 allows `hardDelete()` to express the Package's documented deletion outcome as a `bool`. `NORMATIVE / BACKWARD_COMPATIBLE_CONTRACT_CHANGE` (Minor class). The previous `rowCount() > 0` contract satisfies the new rule when the result describes whether a row was deleted. Other documented outcomes are permitted subject to the missing-target rule.
- Section 11 records the verified `1.0.0-rc.2` trial pin under the existing `DEC-049` release eligibility rules. An earlier pin had to be updated while `1.0.0-rc.2` was the latest published RC. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class).
- Clarify Package and Base Artifact relationships, Composer/CI/Testing/Presentation ownership, Management/Consumer placement, and the PHP examples. The changes distinguish failed statements and transactions from successful outcomes. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class).
- Clarify exact NULL-scope selection and uniqueness, caller-owned schema and transactions, and SQL Ordering applicability. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class).
- Issue #198 corrects the version metadata and the frozen baseline calculation. `EDITORIAL / NO_CONTRACT_CHANGE` (Patch class). The highest cumulative impact of PR #197 and this correction from Frozen `4.0.0` is Major, yielding Mutable Candidate `5.0.0`.
- [Issue #200](https://github.com/Maatify-ltd/php-engineering-standards/issues/200) permits Composer constraints that match only the eligible RC and pass the existing strict validation gate. `NORMATIVE / BACKWARD_COMPATIBLE_CONTRACT_CHANGE` (Minor class). The eligible release and verification requirements of `DEC-049` were retained at that point. The cumulative Major calculation from Frozen `4.0.0` yielded candidate `5.0.0`.

**Verified trial pin (2026-11-18, historical DEC-049 evidence).** The Composer declaration tested in [PR #201](https://github.com/Maatify-ltd/php-engineering-standards/pull/201) was `"maatify/php-persistence": "1.0.0-rc.2 <=1.0.0-rc.2"`. The equality term matched RC.2 and the matching upper bound made the compound constraint pass strict validation. The [release tag](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2) and [Composer metadata](https://repo.packagist.org/p2/maatify/php-persistence.json) identified source revision `71dc61548e48fd053376db69149a00bc87069b70`. Its [Package Reference](https://github.com/Maatify-ltd/php-persistence/blob/71dc61548e48fd053376db69149a00bc87069b70/PHP_PERSISTENCE_PACKAGE_REFERENCE.md) recorded the capabilities of that revision.

### `4.0.0`

- Issues #188 and #189: correct the transaction example to preserve the original throwable when cleanup fails, and anchor decimal validation to the whole string. `CLARIFICATION / NO_CONTRACT_CHANGE` (Patch class): both examples now fulfill the existing original-error and pre-bcmath validation contracts; no new exception, logging, retry, decimal-format, or precision policy. This correction is absorbed in the existing Mutable Candidate `4.0.0` under the cumulative Major calculation below.

- Latest qualified Frozen Version Baseline: `3.0.2`, artifact blob `1475bb006eefe9e1ef9a6973f6664c3d0690dc2c`, at upstream Adoption Commit `ebcf55b0591db61d18a1407bc3effef0197b388f`. The completed VALID consumer Upgrade and exact artifact identity were qualified in [PR #148 post-merge reconciliation](https://github.com/Maatify/php-engineering-standards/pull/148#issuecomment-6302878287); the existing Mutable Candidate `4.0.0` already includes the normative breaking Ordering integration corrections from Issue #150.
- Issue #183 (central upstream `DEC-048` of `Maatify/php-engineering-standards`, not a consumer-local decision): `NORMATIVE / BREAKING_CONTRACT_CHANGE`. Remove changed-row count as a universal mutation-outcome explanation and require a proven missing-target contract before converting Repository `false` to not-found. Existing implementations following the former unconditional mapping may need correction. Preserve boolean signatures and domain-specific no-op/conditional outcomes; no mandatory SQL algorithm, Host connection option, or new public result type.
- Central upstream `DEC-049` of `Maatify/php-engineering-standards`: `NORMATIVE / BREAKING_CONTRACT_CHANGE`. Move shared Ordering and Pagination integration to `maatify/php-persistence` and permit only the latest verified published RC for temporary trials while no stable release exists. Preserve the `DEC-048` missing-target boundary.
- The cumulative highest impact since Frozen Baseline `3.0.2` remains Major, so the Mutable Candidate remains `4.0.0`, calculated once from that baseline rather than from an earlier candidate. No Version Finalization, Freeze, release, consumer Adoption/Upgrade, or consumer runtime correction is claimed.
