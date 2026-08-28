# Changelog

This project uses a Keep a Changelog-inspired format.

## [Unreleased]

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
