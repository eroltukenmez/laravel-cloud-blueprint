<p align="center">
  <img src=".github/assets/lcb-logo.png" alt="Laravel Cloud Blueprint logo" width="160">
</p>

<h1 align="center">Laravel Cloud Blueprint</h1>

<p align="center">Declarative infrastructure for Laravel Cloud.</p>

<p align="center"><code>YAML → PLAN → APPLY</code></p>

Laravel Cloud Blueprint is an unofficial community CLI for describing a supported subset of Laravel Cloud resources in version-controlled YAML blueprints. It produces a read-only plan before mutation, then reconciles supported application, environment, and environment-variable changes when you apply it.

> [!WARNING]
> Version `0.1.0-alpha.4` is early alpha software with a deliberately limited mutation model. This is an unofficial community project and is not affiliated with or maintained by Laravel.

## See the Plan Before You Apply

```text
$ lcb plan
Laravel Cloud Blueprint Plan

~ environment.production
  branch: develop → main

~ variable.production.APP_ENV
  Environment variable differs from desired state.

Plan: 0 to create, 2 to update, 0 unchanged, 0 unsupported.
```

Planning is read-only. Applying a supported plan brings the currently supported subset of Laravel Cloud resources toward the desired blueprint without deleting extra remote resources.

## Installation

Install the current alpha release globally through Composer:

```shell
composer global require laravel-cloud-blueprint/cli:^0.1@alpha
lcb --version
```

Expected output:

```text
Laravel Cloud Blueprint 0.1.0-alpha.4
```

Composer's global bin directory must be available in `PATH` for the `lcb` command to be found.

## Status

Current version: `0.1.0-alpha.4`.

This release can discover and compare applications, environments, and environment variables. It can create missing applications, environments, and variables; update an environment's branch; update an existing variable's value; and explicitly adopt existing Application and Environment identities into local state. Application repository and region changes remain unsupported.

## Requirements

- PHP 8.3 or newer
- Composer
- A Laravel Cloud API token for Cloud reads and mutations
- A GitHub, GitLab, or Bitbucket repository connected to Laravel Cloud

## Authentication

Set your Laravel Cloud API token in the process environment:

```shell
export LCB_TOKEN="your-token"
```

Never commit API tokens or place them in blueprint files.

## Quick Start: New Infrastructure

```shell
lcb init
lcb validate
lcb plan
lcb apply
```

`apply` displays a fresh plan and asks for confirmation, defaulting to no. Use `--auto-approve` for automation. `--non-interactive` explicitly disables prompting and refuses changes unless combined with `--auto-approve`; JSON apply output likewise requires `--auto-approve` when changes are present.

## Quick Start: Existing Laravel Cloud Application

```shell
lcb init --from-cloud
lcb import
```

If Laravel Cloud does not return source-provider metadata, provide it explicitly:

```shell
lcb init --from-cloud --provider=github
```

`init --from-cloud` performs read-only Cloud discovery and exports supported application and environment structure only. It does not create `.lcb/state.json`, adopt state ownership, export environment-variable values or secrets, or persist remote IDs. The separate `lcb import` command can explicitly adopt the generated blueprint's existing application and environment identities after review. The init command refuses to overwrite an existing file unless `--force` is supplied; review generated output before importing or applying it.

## Blueprint Example

```yaml
version: 1

organization: my-organization

application:
  name: my-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/my-api

environments:
  production:
    branch: main
    variables:
      APP_ENV:
        value: production

      APP_KEY:
        from_env: APP_KEY
        sensitive: true
```

`value` supplies a literal string. `from_env` resolves a value from the local process environment when needed for reconciliation, including the fresh plan performed by apply. `sensitive: true` marks intent but does not weaken or strengthen output redaction; it does not create or use a Laravel Cloud Secrets Manager secret.

See [examples/cloud.blueprint.yaml](examples/cloud.blueprint.yaml) for a complete safe example.

## Commands

### `init`

Creates a starter blueprint. `--file=<path>` selects the output path and `--force` permits overwrite.

`--from-cloud` generates a blueprint through read-only Cloud requests. With multiple applications, select interactively or use an exact `--application=<name-or-slug>`. `--non-interactive` refuses ambiguous selection. `--provider=github|gitlab|bitbucket` supplies provider metadata when the API omits it.

### `validate`

Validates the current v1 schema locally. Use `--file=<path>` for a non-default blueprint.

### `cloud:inspect`

Reads the authenticated organization, applications, and environments without mutation. Supports `--json`.

### `plan`

Creates a read-only comparison against Laravel Cloud. Supports `--file=<path>` and `--json`.

### `apply`

Creates and updates supported resources after producing a fresh plan. Supports `--file=<path>`, `--auto-approve`, `--non-interactive`, and `--json`.

### `import`

Adopts existing Application and Environment identities into local LCB state without modifying Laravel Cloud resources. Supports `--file=<path>`, `--auto-approve`, `--non-interactive`, and `--json`.

Import requires explicit confirmation unless `--auto-approve` is supplied. Any conflict or unsupported candidate blocks the entire import. Environment variables and their values are never imported into state. Import performs fresh Cloud discovery after acquiring the state lock so that stale preview identities are not persisted. The lock protects local state writers only; it does not lock or freeze Laravel Cloud resources.

Run `lcb <command> --help` for exact usage.

## Plan Semantics

- `CREATE`: a supported desired resource is missing remotely.
- `UPDATE`: a supported mutable field differs remotely (environment branch or variable value).
- `NO_CHANGE`: the remote resource matches the blueprint.
- `UNSUPPORTED`: satisfying the difference would require behavior unavailable in this release.

Text plans use `+` for create, `~` for update, `=` for no change, and `!` for unsupported. The summary reports counts to create, update, leave unchanged, and treat as unsupported; JSON output exposes the same four counts.

Planning is read-only and deterministic. Extra remote resources are not deleted. Application repository and region differences are reported as unsupported, so they cannot be accidentally applied.

Planning loads local state and treats stored Application and Environment remote IDs as authoritative ownership. A missing managed identity or a same-name replacement is reported as unsupported and is never automatically recreated or adopted. Exact-name resources without state ownership may still be inspected read-only; an unmanaged Environment must be explicitly adopted with `lcb import` before LCB can update its branch. A genuinely missing resource with no state ownership remains eligible for `CREATE`.

Removing a managed Application or Environment from the blueprint does not delete the Laravel Cloud resource or remove its identity from local state. The owned resource remains visible in the plan as `UNSUPPORTED`, and apply refuses the entire plan until the lifecycle condition is resolved. LCB does not currently provide destroy, automatic state cleanup, rename inference, or a state-removal command.

Environment variables remain desired-only and are not recorded as owned state resources. Removing a variable key from the blueprint therefore produces no removal action and leaves the remote variable untouched.

## Apply Semantics

Apply always creates a fresh plan, refuses the entire plan before Cloud mutation when any unsupported action is present, and requests approval unless auto-approved. Interactive approval defaults to no. After approval and preflight checks it acquires the state lock for managed mutation and state work. Potentially duplicate-creating POST requests are never automatically retried.

Supported work is processed in dependency order: application, environments, then environment-variable groups. Environment branch updates use Laravel Cloud's environment PATCH endpoint and accept a confirmed success response without requiring an `attributes.branch` string. Variables are sent per environment with Laravel Cloud's `method=set` mode for both create and update.

Successful application and environment creations are checkpointed as work progresses. Environment branch updates retain their remote identity and do not cause a state save or serial increment solely because of the update. Variables remain outside state, so variable updates likewise do not save or increment state. A pure supported UPDATE apply leaves `.lcb/state.json` unchanged; a mixed CREATE + UPDATE apply can change it when a successful CREATE identity is checkpointed. A later failure is reported as partial, with completed checkpoints retained, and confirmed remote mutations are not rolled back.

## State

Local state is stored in `.lcb/state.json`. It contains remote IDs for LCB-managed application and environment resources. Variables are not state resources, and their values are never stored.

State uses local locking and atomic replacement and should not be edited manually. `init --from-cloud` neither creates state nor adopts remote resources into existing state.

Managed Application and Environment addresses are resolved by their stored remote IDs. Planning validates their expected type, Environment parent address, and conflicting reuse of a remote ID. State ownership is not silently reassigned when Cloud contains another resource with the same name.

`lcb import` is the only explicit state-adoption workflow. It can atomically record matching Application and Environment identities using the existing state schema. It does not adopt variables, repair conflicts, or modify remote configuration. Normal plan and apply matching never adopt unmanaged resources implicitly.

## Security

- API tokens are never written to blueprints or state.
- Environment-variable values are not printed in plans or apply results and are not persisted in state.
- Use `from_env` for secret material.
- Blueprint `value` fields are literal and version-controlled; do not place secrets in them.
- `sensitive: true` is LCB redaction metadata, not Laravel Cloud Secrets Manager integration.

See [SECURITY.md](SECURITY.md) for vulnerability reporting guidance.

## Known Limitations

- This is early alpha software with a limited mutation model.
- Blueprint schema v1 accepts typed `database_clusters` declarations and Environment `database` references as a read-only foundation only. Plan, apply, import, state, `cloud:inspect`, and `init --from-cloud` do not reconcile or export these Database definitions yet.
- Environment branch and variable value updates are supported; other updates and renames are not. A variable-key change is not an in-place rename and cannot remove the old remote key.
- Application repository changes are explicitly unsupported because changing a repository can affect existing environment branch relationships in Laravel Cloud, requiring a broader lifecycle/rebinding workflow than this release implements. No repository mutation request is sent.
- Application region changes are unsupported.
- Explicit Application and Environment identity adoption is supported through `lcb import`; variable adoption, automatic adoption, conflict repair, and repository migration or rebinding are not supported.
- Managed Applications and Environments removed from the blueprint are reported as unsupported and retained in state; they are not deleted. Rename/state-move semantics are not supported.
- Removed environment-variable keys are not reported because variables do not yet have state ownership; remote variables remain untouched.
- DELETE, destroy, drift repair, and remote state are not supported.
- Databases, caches, storage, domains, and Secrets Manager are not supported.
- `init --from-cloud` does not export environment variables or secrets.
- Source-provider metadata may be absent from API responses and require `--provider`.
- Environment-variable mutation uses Laravel Cloud's `method=set` request mode for both creates and updates.
- A variable missing during plan could be created externally before apply; because Cloud provides `set` rather than conditional create, apply could then update that key.
- Variable values are omitted or redacted from text and JSON plan/apply output, state, and safe exceptions regardless of whether `sensitive: true` is set; Cloud validation and transport errors are sanitized before display.
- Laravel Cloud API behavior may evolve during the alpha lifecycle.

## Roadmap

- Database and cache resources
- Richer planning and update semantics
- Broader import workflows and conflict repair
- Destroy and drift detection
- Distribution improvements

No release dates are promised for roadmap items.

## Development

```shell
git clone https://github.com/eroltukenmez/laravel-cloud-blueprint.git
cd laravel-cloud-blueprint
composer install
composer test
composer analyse
```

From a repository checkout, invoke the CLI as `php bin/lcb`. PHPStan runs at maximum level. Tests use fakes and do not require a live Laravel Cloud account.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Laravel Cloud Blueprint is released under the [MIT License](LICENSE).
