# Laravel Cloud Blueprint

Laravel Cloud Blueprint is a framework-agnostic CLI for describing and reconciling Laravel Cloud infrastructure using version-controlled YAML blueprints.

> [!WARNING]
> Version `0.1.0-alpha.2` is early alpha software with a CREATE-only mutation model. This is an unofficial community project and is not affiliated with or maintained by Laravel.

## Why

Laravel Cloud exposes an API. Laravel Cloud Blueprint provides an experimental declarative YAML workflow around the subset of that API currently supported by this project: applications, environments, and environment variables.

## Status

Current version: `0.1.0-alpha.2`.

This release can discover and compare applications, environments, and environment variables, then create supported missing resources. Mutations are CREATE-only; differences requiring updates are reported as unsupported.

## Requirements

- PHP 8.3 or newer
- Composer
- A Laravel Cloud API token for Cloud reads and mutations
- A GitHub, GitLab, or Bitbucket repository connected to Laravel Cloud

## Installation

Install the current alpha release globally through Composer:

```shell
composer global require laravel-cloud-blueprint/cli:^0.1@alpha
lcb --version
```

Expected output:

```text
Laravel Cloud Blueprint 0.1.0-alpha.2
```

Composer's global bin directory must be available in `PATH` for the `lcb` command to be found.

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
```

If Laravel Cloud does not return source-provider metadata, provide it explicitly:

```shell
lcb init --from-cloud --provider=github
```

This exports supported application and environment structure only. It does not import state ownership, export environment variables or secrets, or write remote IDs into YAML.

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

`value` supplies a literal string. `from_env` resolves a value from the local process environment during plan and apply. `sensitive: true` controls LCB redaction; it does not create or use a Laravel Cloud Secrets Manager secret.

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

Creates supported missing resources after producing a fresh plan. Supports `--file=<path>`, `--auto-approve`, `--non-interactive`, and `--json`.

Run `lcb <command> --help` for exact usage.

## Plan Semantics

- `CREATE`: a supported desired resource is missing remotely.
- `NO_CHANGE`: the remote resource matches the blueprint.
- `UNSUPPORTED`: satisfying the difference would require behavior unavailable in this release, such as update.

Planning is read-only. Extra remote resources are not deleted.

## Apply Semantics

Apply always creates a fresh plan. It is CREATE-only and refuses plans containing unsupported changes. Interactive approval defaults to no. Potentially duplicate-creating POST requests are never automatically retried.

Successful resources are checkpointed as work progresses. A later failure is reported as partial, with completed checkpoints retained. There is no automatic rollback.

## State

Local state is stored in `.lcb/state.json`. It contains remote IDs for LCB-managed application and environment resources. Variables are not state resources, and their values are never stored.

State uses local locking and atomic replacement and should not be edited manually. `init --from-cloud` neither creates state nor adopts remote resources into existing state.

## Security

- API tokens are never written to blueprints or state.
- Environment-variable values are not printed in plans or apply results and are not persisted in state.
- Use `from_env` for secret material.
- Blueprint `value` fields are literal and version-controlled; do not place secrets in them.
- `sensitive: true` is LCB redaction metadata, not Laravel Cloud Secrets Manager integration.

See [SECURITY.md](SECURITY.md) for vulnerability reporting guidance.

## Known Limitations

- This is early alpha software with a CREATE-only mutation model.
- UPDATE, DELETE, destroy, import, drift repair, and remote state are not supported.
- Databases, caches, storage, domains, and Secrets Manager are not supported.
- `init --from-cloud` does not export environment variables or secrets.
- Source-provider metadata may be absent from API responses and require `--provider`.
- Environment-variable mutation uses Laravel Cloud's `method=set` request mode.
- A variable missing during plan could be created externally before apply; because Cloud provides `set` rather than conditional create, apply could then update that key.
- Laravel Cloud API behavior may evolve during the alpha lifecycle.

## Roadmap

- Database and cache resources
- Richer planning and update semantics
- Import and state adoption
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
