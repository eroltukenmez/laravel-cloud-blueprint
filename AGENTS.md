# Laravel Cloud Blueprint Development Rules

Laravel Cloud Blueprint is a CLI-first, framework-agnostic Infrastructure as Code
tool for Laravel Cloud.

## Architecture

The project is currently distributed as a single CLI package.

Do not create separate core packages yet.

Business logic must remain separable from the CLI so that it can be extracted
into a standalone core package in the future.

## Dependency Rules

Symfony dependencies are allowed only where they are adapters or CLI infrastructure.

The following namespaces must not depend on Symfony Console:

- Application
- Blueprint
- Planning
- Resource
- State

Console commands must not contain business logic.

Symfony YAML must be hidden behind a project-owned abstraction.

Symfony HTTP Client must be hidden behind a project-owned Cloud API abstraction.

## PHP

- PHP 8.3+
- declare(strict_types=1) is required.
- Prefer final classes.
- Prefer readonly classes/value objects where appropriate.
- Prefer enums over string constants.
- Prefer typed objects over associative arrays after parsing/normalization.
- Avoid mutable global state.
- Avoid static service access.
- Avoid service locator patterns.

## Dependencies

Do not add Composer dependencies without explicit approval.

Prefer PHP standard library and existing dependencies.

## Blueprint

Raw YAML arrays must not leak beyond the parsing/normalization boundary.

After normalization, use typed definitions/value objects.

Unknown blueprint properties must cause validation errors.

Blueprint schema is versioned.

Current supported schema version: 1.

## Cloud

Never expose API tokens.

Never log:

- authentication tokens
- sensitive environment variable values
- secrets

Cloud API DTOs must be separated from domain resources.

## Planning

The planner must be deterministic.

v0.1 supports:

- CREATE
- UPDATE
- NO_CHANGE
- UNSUPPORTED

Resource deletion is explicitly forbidden in v0.1.

Do not implement destroy behavior.

## Apply

Apply must be designed to be idempotent.

Successful operations must be safely recordable in state.

Partial failures must be represented explicitly.

Never silently retry potentially destructive or duplicate-creating operations.

## State

Secrets must never be persisted in state.

State writes must eventually use atomic file replacement.

Do not use resource names as the only long-term identity.

## Testing

Every behavior change requires tests.

Unit tests must not require a real Laravel Cloud account.

Before completing a task run:

composer test
composer analyse

Both commands must pass.

## Scope

v0.1.0-alpha.6 supports only:

- Application
- Environment
- Environment Variables
- Database Cluster
- logical Database

Supported mutations are CREATE for all five resource types, UPDATE for environment branches and environment-variable values, and no DELETE. Application repository and region changes, Database updates and replacements, and Environment Database attachment are UNSUPPORTED.

Explicit import supports Application, Environment, Database Cluster, and logical Database identity adoption into local state only. Environment variables and Database attachments are not importable.

Commands:

- lcb init
- lcb validate
- lcb cloud:inspect
- lcb plan
- lcb apply
- lcb import
- lcb state:unmanage

`lcb state:unmanage` mutates local ownership state only. It must never delete, detach, or otherwise mutate a Laravel Cloud resource.

Do not add:

- destroy
- drift detection
- remote state
- provider plugins
- cache

unless explicitly requested.
