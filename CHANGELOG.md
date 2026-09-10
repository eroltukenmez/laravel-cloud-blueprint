# Changelog

This project uses a Keep a Changelog-inspired format.

## [Unreleased]

## [0.1.0-alpha.14] - 2026-09-10

### Added

- Published the pre-Beta compatibility policy for CLI commands, JSON contracts, resource addresses, Blueprint v1, State, planning, Apply, Drift, import, and local ownership release.
- Added executable public-contract checks for the command surface, JSON contract versions, exit codes, typed result taxonomies, Blueprint schema support, and State V1-to-V2 readability.

### Changed

- Hardened resource-address parsing and Blueprint logical identifier validation so ambiguous address collisions fail before planning.
- Unified typed planning ownership and reconciliation evidence, Apply outcomes, JSON failure envelopes, deterministic Cloud inspection ordering, and command exit behavior ahead of the Beta freeze.
- Centralized the release version used by the CLI banner and Laravel Cloud API user agent.

### Safety

- This release adds no destructive capability, State schema, Blueprint schema, authentication change, or automatic retry behavior.
- Existing State V1 remains readable; current State writes remain V2 and preserve managed, derived, and provenance distinctions.

### Compatibility

- JSON contract v1 output changed during Alpha as documented; incompatible changes after Beta require a contract-version bump.
- Blueprint v1 remains the only supported Blueprint schema. The stricter logical identifier rules reject previously ambiguous input rather than reinterpret it.

## [0.1.0-alpha.13] - 2026-09-08

### Changed

- Added shared typed Database Cluster topology evidence for planning, Drift, `state:inspect`, and locked destructive execution.
- Read-only consumers can report qualified scoped-list observations; guarded Database Cluster DELETE remains corroborated-topology-only.
- Hardened exact row-level parent proof and scoped logical-Database pagination evidence validation.

### Safety

- Incomplete, conflicting, and unverified topology evidence fails closed for destructive reconciliation.
- This release adds no destructive capability and no State schema change.

## [0.1.0-alpha.12] - 2026-09-07

### Added

- Added read-only `lcb state:inspect` with offline local inspection, optional State-anchored Cloud verification, Blueprint-aware recovery guidance, JSON contract v1, and strict check mode.

### Security

- Inspection never mutates State or Laravel Cloud, never executes guidance, introduces no State V3, and never infers historical derived provenance from current Cloud topology.
- `--check` returns exit code 3 for unhealthy completed reports; normal inspection remains observational and exits 0 when it completes.

## [0.1.0-alpha.11] - 2026-09-07

### Added

- Added opt-in `lcb drift --check` for strict, evidence-safe observed conformance checking with dedicated exit code `3` and concise human pass/fail output.
- Check mode fails for `UNKNOWN` or incomplete evidence and lifecycle conditions, and its result is independent of reconciliation support.

### Changed

- Kept Drift JSON output and schema unchanged in check mode; check status is communicated through the process exit code.
- Preserved normal `lcb drift` exit behavior: completed reports exit `0`, while operational failures exit `1` and Blueprint decode/validation failures exit `2`.

### Safety

- Check mode remains read-only, performs no State or Cloud mutation, and introduces no State schema changes.

### Verified

- Validated against a disposable Laravel Cloud environment: baseline check exited `0`; an external Environment branch difference left normal Drift at `0` and check at `3`; normal and check JSON were byte-identical; LCB did not reconcile Cloud or change State; restoring the branch returned the final check to `0`.
- CI usage and documentation cover strict check mode, including its human pass/fail footer and unchanged JSON contract.

## [0.1.0-alpha.10] - 2026-09-04

### Added

- Added typed tri-state Environment database attachment intent: omitted, attached, or explicitly detached.
- Added typed database attachment drift observations and safe `NO_CHANGE`, `UPDATE`, and `UNSUPPORTED` planning.

### Changed

- Added guarded, at-most-once attachment PATCH reconciliation with authoritative read-after-write confirmation.
- Attachment updates leave State unchanged; Laravel Cloud remains responsible for injected connection variables and credentials.

### Fixed

- Fixed validation of explicit-null database detach intent.

### Verified

- Validated the real-Cloud lifecycle: detached, attach A, no-op, switch B, detach, and omission unmanaged, plus guarded legacy cleanup.

## [0.1.0-alpha.9] - 2026-09-04

### Added

- Added typed, read-only observations and `lcb drift` human and deterministic JSON reports.
- Added ownership, reconciliation, and evidence dimensions across Application, Environment, declared variable, Database Cluster, logical Database, and derived default Database observations.

### Changed

- DriftReport reuses scoped discovery and shared typed comparison paths; it does not parse Plan output or introduce a separate discovery engine.

### Fixed

- Logical Database observation now retrieves authoritative parent and Environment relationship evidence when required, avoiding false incomplete observations from weaker list representations.

### Safety

- Drift does not write State, reconcile, or Apply. Exact State identity remains authoritative for managed resources; incomplete evidence remains `unknown`.
- Variable output never exposes current or desired values. A configuration difference describes a Blueprint/Cloud difference and does not prove causal remote drift or identify which changed.

### Verified

- Verified with the automated full suite, PHPStan, Composer validation, and disposable real Laravel Cloud validation, including deterministic JSON, an externally introduced reversible Environment difference that remained after Drift, byte-identical State, and restoration to all in sync.

## [0.1.0-alpha.8] - 2026-09-03

### Added

- Canonical State V2 with typed `managed` and `derived` ownership classifications, typed provenance, deterministic read-only V1 migration, and V2 persistence on the next material State mutation.
- Database Cluster CREATE provenance capture for the exact Cloud-created default logical Database at the reserved derived address.
- Explicitly approved guarded Database Cluster deletion with ordinary logical Database actions executed and checkpointed child-first, followed by the derived parent-lifecycle dependency and then the Cluster.
- Typed derived parent-lifecycle dependency metadata in human and JSON plans without remote identities or Cloud names.
- Exact already-absent recovery for confirmed logical Database and Database Cluster absence, plus bounded exact-ID Cluster absence verification.
- Sanitized destructive diagnostics that distinguish missing, unknown, and conflicting readiness evidence.

### Fixed

- Normalized numeric Laravel Cloud resource identifiers at the HTTP boundary without weakening exact identity matching.
- Scoped Database-list rows may omit redundant parent metadata without creating a false derived ownership conflict; explicit contradictory parent evidence remains a conflict.

### Safety

- Guarded deletion requires exact State identity and, for the derived default, exact `cluster_create_response` provenance; names and default-looking topology never grant authorization.
- Every confirmed child absence is checkpointed before parent work. Cloud deletion is not claimed to be transactional or cascading.
- Potentially destructive requests are transmitted at most once; uncertain mutation outcomes are never retried automatically and retain State unless exact absence is proven.
- Cluster topology, complete logical Database enumeration, snapshots, recovery configuration, and lifecycle are freshly rediscovered after child deletion and before the Cluster DELETE.
- Cluster deletion requires zero remaining children, zero snapshots, safe disabled retained recovery, and an explicitly eligible lifecycle state.
- Legacy, imported, released, and otherwise unmanaged default Databases remain blockers. `state:unmanage` is local-only and removes derived deletion authorization.

### Verified

- The guarded Database Cluster lifecycle was validated against a disposable real Laravel Cloud resource: two ordinary children, the derived default, and the Cluster were each deleted, exactly verified absent, and checkpointed in order; the resulting State and plan converged without exposing Cloud identifiers or credentials.

## [0.1.0-alpha.7] - 2026-09-01

### Added

- Added deterministic DELETE planning for State-owned resources omitted from the Blueprint and guarded execution for eligible Environment resources only.
- Added typed Environment dependency readiness (`safe`, `blocked`, or `unknown`), with expected instances reported as informational children and unsafe dependencies or default-Environment status retained as blockers.
- Added authoritative Environment domain and Application default-Environment discovery, including non-sensitive completeness diagnostics and scoped read-only fallbacks.

### Security

- Environment deletion requires explicit destructive approval, exact State identity, locked Blueprint/State/Cloud revalidation, complete SAFE readiness, at most one transmitted DELETE, and authoritative exact-ID absence before atomic State removal.
- The guarded flow was validated end-to-end against a disposable Laravel Cloud Environment, including refusal without explicit automation approval, confirmed deletion, State checkpointing, post-delete convergence, and repeat-apply idempotency.
- Application, Database Cluster, logical Database, variable, and attachment deletion remain unsupported; there is no recursive destroy, force bypass, or destructive mutation retry.

## [0.1.0-alpha.6] - 2026-09-01

### Changed

- Added confirmation-gated `lcb state:unmanage` to release one exact Application, Environment, Database Cluster, or logical Database identity from local State without requiring a Laravel Cloud token or modifying Laravel Cloud.
- Ownership release is idempotent for unmanaged addresses and preserves State V1 while enabling removed or stale owned resources to return to normal unmanaged planning and explicit import workflows.
- State V1 now enforces that every owned Environment references an owned Application parent, matching the existing logical Database parent-integrity boundary.

### Security

- Ownership release defaults to no, requires explicit automation approval, refuses parents with owned children, and reloads and revalidates the exact approved identity and child set under the State lock before one atomic save.
- `state:unmanage` is a local ownership operation only: it performs no Laravel Cloud read, deletion, detach, or other remote mutation and never exposes remote IDs or secret material.

## [0.1.0-alpha.5] - 2026-08-31

### Changed

- Hardened CLI help and recovery guidance and made command-owned JSON errors valid standalone JSON for machine-readable plan, inspect, import, and apply workflows.
- Completed the Database feature safety review: pagination now rejects repeated next URLs in addition to foreign URLs and duplicate resource IDs, and locked Database revalidation refuses newly appearing actionable work that was not present in the approved plan.
- Added safe Database Cluster and logical Database creation with typed provider payloads, locked Cloud/state revalidation, immediate identity checkpoints, bounded GET-only Cluster readiness observation, and no automatic POST retry.
- Missing logical Databases may be created only under a newly created or already state-owned Cluster; unmanaged matches still require explicit import, while stale identities, replacements, configuration changes, attachments, and deletion remain unsupported.
- Extended atomic `lcb import` state adoption to Database Clusters and logical Databases, with exact scoped matching, parent-first dependencies, locked rediscovery, and no Database mutations or attachment ownership.
- State schema v1 now persists Database Cluster and logical Database identities, and planning resolves imported resources by stored IDs while safely reporting stale identities, same-name replacements, configuration differences, and owned resources absent from the blueprint.
- Planning now compares desired Database Clusters, logical Databases, and Environment attachments with safe Cloud discovery; supported missing resources plan as CREATE, exact unmanaged matches remain read-only no-change/import boundaries, and update, replacement, attachment, and deletion lifecycles remain unsupported.
- Added a typed, read-only Laravel Cloud discovery boundary for Database Clusters, logical Databases, and Environment Database attachment identities; connection and credential data is discarded during response parsing.
- Blueprint schema v1 now accepts typed top-level Laravel MySQL and Neon Serverless Postgres Cluster definitions, logical Databases, and optional Environment Database references as a read-only foundation for future reconciliation.
- Database configuration validation is provider-specific and rejects unknown, incompatible, RDS, connection, and credential properties.
- Planning now resolves state-managed Application and Environment resources by their stored remote IDs before name-based discovery.
- Matching unmanaged Environments require explicit import before branch reconciliation, while genuinely missing resources remain eligible for creation.
- Planning now considers desired resources together with state-owned Applications and Environments, reporting owned resources absent from the blueprint as unsupported without deleting them or removing state ownership.
- Owned-only lifecycle actions retain deterministic Application-before-Environment ordering and block apply before confirmation, state transactions, or Cloud mutation.
- Environment variables remain desired-only; removing a variable key does not remove or report the remote variable because variables do not yet have state ownership.

### Security

- Stale, replaced, wrongly parented, or multiply owned state identities produce non-actionable plans instead of implicit reassignment, recreation, or mutation.
- Removing a managed Application or Environment cannot trigger deletion, automatic state cleanup, replacement adoption, or inferred rename behavior.
- Database connection and credential material is discarded at parsing boundaries and never enters plans, apply output, import proposals, or State.
- Any unsupported lifecycle or Environment Database attachment action blocks the complete apply, and uncertain Database POST outcomes are not blindly retried; confirmed identities are checkpointed before dependent work continues.
- Environment Database attachment remains non-actionable because authoritative injected-variable reconciliation and safe attachment lifecycle semantics are not yet available.

### Verified

- A controlled real Laravel Cloud E2E using `neon_serverless_postgres_18` with a Dev-sized configuration verified Database Cluster CREATE, readiness-gated logical Database CREATE, immediate State V1 checkpoints, post-create `NO_CHANGE` reconciliation, and repeat-apply idempotency without a State rewrite.
- No credential material appeared in visible E2E output or State. Environment attachment was not tested or enabled, and internal readiness statuses and raw create HTTP statuses were not exposed by the CLI output.

## [0.1.0-alpha.4] - 2026-08-28

### Added

- Explicit, confirmation-gated `lcb import` support for Application and Environment identity adoption, including deterministic text and JSON proposals.
- Atomic local-state adoption with state locking and fresh state and Cloud identity revalidation before persistence.

### Security

- Import refuses conflicts, ambiguous identities, and unsupported candidates as one operation, never mutates Laravel Cloud, and keeps environment variables and their values outside import state.

## [0.1.0-alpha.3] - 2026-08-25

### Added

- UPDATE planning and apply support for environment branches and existing environment-variable values.
- UPDATE counts in text and JSON plan summaries, with `~` plan rendering and updated apply outcomes.

### Changed

- Plans now distinguish CREATE, UPDATE, NO_CHANGE, and UNSUPPORTED operations.
- Environment-variable creates and updates are reconciled per environment through Laravel Cloud's `method=set` request mode.
- Apply preserves dependency ordering, preflight refusal for unsupported plans, and state checkpointing for created application and environment resources.
- Pure UPDATE operations preserve the local state file and serial.
- Environment PATCH response handling accepts confirmed success without requiring an `attributes.branch` string.

### Fixed

- Environment PATCH success no longer requires an `attributes.branch` string when Laravel Cloud represents the branch through relationships.
- Application repository differences are now reported as unsupported and never sent as an application mutation after real Cloud testing exposed broader environment-branch lifecycle effects.
- Environment-variable values remain redacted across planning, apply results, and sanitized Cloud errors.

### Security

- Unsupported application repository or region changes block apply before any Cloud mutation.
- Documented the `method=set` create race: a variable created after planning may be updated when apply runs.

### Verified

- Real Laravel Cloud testing covered branch and variable UPDATE-to-NO_CHANGE reconciliation, mixed CREATE/UPDATE checkpointing, pure UPDATE state stability, and complete refusal of repository-difference plans. No credentials, values, or remote identifiers are recorded here.

## [0.1.0-alpha.2]

### Changed

- Packagist is now the documented primary installation method.

### Fixed

- Composer-installed and global CLI binaries now discover the consumer Composer autoloader correctly.
- Direct repository execution remains supported.

## [0.1.0-alpha.1]

### Added

- Typed blueprint model, YAML decoder, normalizer, and validator for schema version 1.
- Starter generation with `init` and read-only Cloud generation with `init --from-cloud`.
- Read-only Laravel Cloud inspection and deterministic planning.
- CREATE-only apply for applications, environments, and environment variables.
- Reconciliation of Laravel Cloud's implicitly created default environment.
- Versioned local state with locking, atomic writes, checkpointing, and partial-failure recovery semantics.
- Safe field-level Laravel Cloud validation reporting.

### Security

- API tokens and environment-variable values are redacted from user-visible failures.
- Variable values and secrets are never persisted in local state.
