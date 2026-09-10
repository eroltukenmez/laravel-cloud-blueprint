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
- Environment DELETE planning and guarded execution
- logical Database DELETE planning and guarded execution
- DELETE planning only for other resource types (non-executable)
- NO_CHANGE
- UNSUPPORTED

Environment, logical Database, and Database Cluster are the only Cloud resources whose DELETE may execute in v0.1. Each requires explicit approval, exact State identity, locked live rediscovery, complete SAFE dependency readiness, and confirmed remote absence before State removal. Logical Database deletion additionally requires its exact State-owned Cluster parent and complete empty reverse Environment attachments. Database Cluster deletion is guarded child-first and additionally requires its exact State-owned Cluster identity, authoritative derived-child provenance where applicable, complete relationship evidence, and safe snapshot, recovery, and lifecycle readiness. Other DELETE resource types remain non-executable.

Database Cluster destructive readiness includes read-only exact-ID child, snapshot, retained recovery configuration, lifecycle discovery, and `CORROBORATED_COMPLETE` topology evidence. Corroboration requires exact Cluster identity, complete exact relationship evidence, a complete scoped logical-Database list with validated pagination, exact child-ID agreement, and no duplicate, parent, or identity conflict. Scoped-only topology is read-only observation only: it can never prove absence, ownership, derived provenance, or DELETE readiness. Any snapshot blocks readiness; incomplete, conflicting, or unverified evidence remains unknown. Database Cluster DELETE remains guarded by these checks, while snapshot DELETE remains unsupported and snapshots are never automatically deleted.

Environment destructive dependency discovery is live and read-only. It must remain conservative when relationship data is missing, malformed, or unknown, and it must not imply ownership. No force or recursive destroy behavior is permitted.

Incomplete Environment dependency diagnostics may expose relationship names only. They must never expose dependency IDs, credentials, secret values, or other sensitive payload data.

Environment destructive discovery should prefer relationship linkage. Missing domain linkage may be completed through the scoped Environment domains endpoint, and missing included Application default linkage through an exact Application GET; missing or malformed fallback data remains non-executable.

Discovered instances are expected Environment children and remain observable but do not by themselves block Environment deletion. Default Environment, database, cache, WebSocket, custom-domain, filesystem, secret, and deployment relationships remain destructive blockers.

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

v0.1.0-alpha.14 supports only:

- Application
- Environment
- Environment Variables
- Database Cluster
- logical Database

Supported mutations are CREATE for all five resource types, UPDATE for environment branches and environment-variable values, guarded Environment Database attachment reconciliation (attach, switch, and explicit detach), and guarded DELETE for Environment, logical Database, and Database Cluster only. Application repository and region changes and Database updates and replacements are UNSUPPORTED.

Explicit import supports Application, Environment, Database Cluster, and logical Database identity adoption into local state only. Environment variables and Database attachments are not importable.

Commands:

- lcb init
- lcb validate
- lcb cloud:inspect
- lcb plan
- lcb drift
- lcb apply
- lcb import
- lcb state:inspect
- lcb state:unmanage

`lcb state:unmanage` mutates local ownership state only. It must never delete, detach, or otherwise mutate a Laravel Cloud resource.

Do not add:

- destroy
- remote state
- provider plugins
- cache

unless explicitly requested.
