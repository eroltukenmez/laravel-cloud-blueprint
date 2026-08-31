# Changelog

This project uses a Keep a Changelog-inspired format.

## [Unreleased]

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
