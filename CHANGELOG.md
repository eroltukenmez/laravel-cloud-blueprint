# Changelog

This project uses a Keep a Changelog-inspired format.

## [Unreleased]

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
