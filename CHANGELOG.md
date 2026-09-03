# Changelog

This project uses a Keep a Changelog-inspired format.

## [Unreleased]

### Added

- Added canonical State V2 with typed `managed` and `derived` ownership classifications and typed provenance for future Cluster-create-derived logical Databases.
- Added deterministic, read-only V1-to-V2 in-memory migration; existing identities and parents remain ordinary managed resources, and canonical V2 is written only after a material State mutation.
- Database Cluster CREATE now validates exactly one default Database relationship and atomically checkpoints its exact identity at the reserved `database.<cluster>.__derived_default` address with typed create-response provenance before creating declared Databases.
- Added guarded DELETE execution for exact State-owned logical Databases omitted from the Blueprint when parent identity and reverse Environment attachment discovery are complete and safe.
- Added read-only, Cluster-scoped snapshot discovery and conservative Database Cluster lifecycle readiness covering complete pagination, retained recovery configuration, and lifecycle status.
- Database Cluster destructive-readiness now recognizes an exactly proven Cluster-create-derived default Database as a typed informational parent-lifecycle dependency while keeping ordinary owned children, unmanaged children, and conflicts distinct.

### Security

- Derived resources require recognized provenance, participate in the existing parent/child ownership graph, and fail closed in planning; no provenance is inferred from resource names or later Cloud discovery.
- Default-child capture never uses the Cloud Database name, rejects malformed or ambiguous CREATE evidence, and does not retroactively classify legacy or imported Cluster children. Database Cluster DELETE remains unsupported.
- Derived parent dependencies require exact State provenance, exact parent ownership, complete matching Cluster and Database discovery, and conflict-free identity evidence. They remain non-actionable outside a future guarded Cluster lifecycle; releasing their State ownership makes the live child unmanaged again.
- Logical Database deletion requires explicit approval, locked Blueprint/State/Cloud replanning, an exact State-owned Cluster and Database identity, complete empty attachments, at most one DELETE transmission, authoritative exact-ID absence, and an immediate atomic State checkpoint.
- Destructive logical Database discovery explicitly requests the authoritative parent Database and reverse Environment relationships; missing or malformed relationship data remains non-executable.
- Database Cluster deletion and automatic detach remain unsupported; `state:unmanage` remains local-only.
- Any discovered Database snapshot blocks Cluster destructive readiness, incomplete snapshot or lifecycle evidence remains unknown, and snapshots are never automatically deleted.

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
