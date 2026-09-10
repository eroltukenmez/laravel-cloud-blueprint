<p align="center">
  <img src=".github/assets/lcb-logo.png" alt="Laravel Cloud Blueprint logo" width="160">
</p>

<h1 align="center">Laravel Cloud Blueprint</h1>

<p align="center">Declarative infrastructure for Laravel Cloud.</p>

<p align="center"><code>YAML → PLAN → APPLY</code></p>

Laravel Cloud Blueprint is an unofficial community CLI for describing a supported subset of Laravel Cloud resources in version-controlled YAML blueprints. It produces a read-only plan before mutation, then reconciles supported application, environment, environment-variable, Database, and attachment changes when you apply it.

> [!WARNING]
> Version `0.1.0-alpha.14` is early alpha software with a deliberately limited mutation model. This is an unofficial community project and is not affiliated with or maintained by Laravel.

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

Planning is read-only. Applying a supported plan brings the currently supported subset of Laravel Cloud resources toward the desired blueprint. Remote deletion is limited to explicitly approved, eligible State-owned Environments, logical Databases, and Database Clusters omitted from the Blueprint.

## Installation

Install the current alpha release globally through Composer:

```shell
composer global require laravel-cloud-blueprint/cli:^0.1@alpha
lcb --version
```

Expected output:

```text
Laravel Cloud Blueprint 0.1.0-alpha.14
```

Composer's global bin directory must be available in `PATH` for the `lcb` command to be found.

## Status

Current version: `0.1.0-alpha.14`.

The current build can discover and compare applications, environments, environment variables, Database Clusters, logical Databases, and Environment attachments. It can create missing resources in that supported set, update an environment's branch, an existing variable's value, or a managed attachment, and safely delete eligible State-owned Environments, logical Databases, or Database Clusters removed from the Blueprint. Database deletion requires exact State identity and complete dependency evidence. Application repository and region changes, renames, automatic adoption, remote state, and Database update/replacement remain unsupported.

| Resource or capability | Supported lifecycle |
| --- | --- |
| Application | CREATE and explicit import; repository/region updates and DELETE are unsupported |
| Environment | CREATE, branch UPDATE, explicit import, and guarded State-owned DELETE |
| Environment variable | CREATE and value UPDATE; no import or DELETE |
| Database Cluster | CREATE, explicit import, and guarded State-owned DELETE; UPDATE/replacement unsupported |
| Logical Database | CREATE, explicit import, and guarded State-owned DELETE |
| Environment Database attachment | Guarded attach, switch, and explicit detach reconciliation; no import |
| General destroy | Unsupported |

Database Cluster DELETE planning performs read-only discovery of exact-ID logical Database children, all paginated snapshot rows, retained recovery configuration, and current lifecycle status. A Cluster-create-derived default Database with exact State provenance and matching live parent identity is disclosed as a parent-lifecycle dependency; it is deleted only inside an approved guarded parent lifecycle. Ordinary children remain explicit child-first DELETE actions. Legacy/imported defaults and released derived identities remain unmanaged blockers. Any snapshot or retained recovery configuration blocks deletion; incomplete discovery and unknown lifecycle evidence remain `UNKNOWN`, and snapshots are never deleted automatically.

### Database Cluster topology evidence

LCB combines the exact Database Cluster relationship and a scoped logical-Database list when evaluating Cluster topology. A complete agreement between those sources is **corroborated** evidence. Only corroborated, validated, exact-parent topology can contribute to guarded Database Cluster DELETE; it is still only one of the required safety checks.

Read-only commands may report a scoped-only child when the scoped list is complete, its pagination is validated, and every returned row explicitly proves the requested Cluster parent. This is qualified observation, not ownership, derived provenance, adoption, absence proof, lifecycle permission, or delete authorization.

Partial reads retain useful positive child observations, but never prove a full child set or absence. Missing, malformed, conflicting, or unverified evidence fails closed for destructive reconciliation. In particular, a list that exhausts links without enough pagination validation is not treated as destructive proof. LCB never reconstructs `DERIVED` or `CLUSTER_CREATE_RESPONSE` provenance from current topology.

### Database attachments

Environment database intent is tri-state:

```yaml
environments:
  production:
    branch: main
    # database omitted: LCB does not manage the attachment
```

```yaml
environments:
  production:
    branch: main
    database: primary.application # require this exact logical Database
```

```yaml
environments:
  production:
    branch: main
    database: null # require this Environment to be detached
```

Omitted means unmanaged, a string is attached intent, and `null` is detached intent; omission is never treated as detach. The plan address `database_attachment.<environment>` may be `NO_CHANGE`, `UPDATE`, or `UNSUPPORTED`. Managed intents report typed drift observations; omission produces no attachment observation because ownership is not asserted.

Attachment reconciliation is guarded: the Environment, target ordinary logical Database, and its parent Cluster must each be exact State-owned identities. Derived default Database references are not eligible targets, and incomplete or malformed relationship evidence blocks reconciliation. Each actionable mutation performs at most one PATCH, uncertain responses are not blindly retried, and exact read-after-write confirmation is required. Attachment updates do not write State. Laravel Cloud manages and injects connection variables; LCB does not reconstruct or persist credentials or connection values.

When the Environment, Cluster, or logical Database is created in the same first Apply, attachment work may remain non-actionable until a later Plan/Apply establishes exact State ownership.

### Upgrading to alpha.8

State is now written canonically as V2 so LCB can distinguish ordinary managed resources from typed derived resources with authoritative provenance. Existing V1 State remains readable and migrates in memory without inferring provenance; the next material State mutation writes V2. Database Clusters created by alpha.8 capture their Cloud-created default logical Database as a derived child. Legacy and imported defaults do not gain that authorization and remain unmanaged Cluster-deletion blockers.

## State Inspection and Guided Recovery

`state:inspect` is a read-only alpha command for reviewing local ownership health. It never writes State, changes Laravel Cloud, imports resources, releases ownership, plans, or applies changes.

```shell
lcb state:inspect                 # local, token-free inspection
lcb state:inspect --cloud         # optional State-anchored read-only Cloud verification
lcb state:inspect --cloud --file=cloud.blueprint.yaml
lcb state:inspect --json
lcb state:inspect --check
```

Cloud verification is not a Cloud-wide inventory or compliance scan: it verifies only identities and relationships anchored by local State. `--file` enables Blueprint-aware guidance for existing explicit workflows, but inspection never executes them.

Normal inspection exits `0` once a report completes, even with findings. `--check` exits `3` when strict policy fails; operational failures exit `1`, and Blueprint decode or validation failures exit `2`. JSON output has contract version 1 and is byte-stable between `--json` and `--json --check` for the same report.

Current Cloud topology cannot reconstruct historical derived provenance. An unmanaged Database child may be adopted only as ordinary `MANAGED` ownership through explicit import; a temporary declaration and import never restore `DERIVED` or `CLUSTER_CREATE_RESPONSE` provenance.

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

`init --from-cloud` performs read-only Cloud discovery and exports supported application and environment structure only. It does not create `.lcb/state.json`, adopt state ownership, export environment-variable values or secrets, or persist remote IDs. The separate `lcb import` command can explicitly adopt matching Application, Environment, Database Cluster, and logical Database identities after review. The init command refuses to overwrite an existing file unless `--force` is supplied; review generated output before importing or applying it.

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

database_clusters:
  primary:
    type: neon_serverless_postgres_18
    region: eu-central-1
    config:
      cu_min: 0.25
      cu_max: 1
      suspend_seconds: 300
      retention_days: 7
    databases:
      application: {}
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

Reads the authenticated organization, applications, and environments without mutation. Supports `--json`. This diagnostic discovery command may include Cloud resource IDs.

### `plan`

Creates a read-only comparison against Laravel Cloud. Supports `--file=<path>` and `--json`.

### `drift`

Run `lcb drift` for a concise human report, `lcb drift --json` for all scoped entries as deterministic JSON, or `lcb drift --file=cloud.yaml` for another Blueprint. Drift is read-only: it compares the desired Blueprint, State-owned identity, and current scoped Cloud evidence; it does not Apply, reconcile, or update State. Reports include configuration, identity, lifecycle, ownership, reconciliation, and evidence observations for Applications, Environments, declared variables, Database Clusters, logical Databases, and derived default Databases.

`lcb drift` is observational reporting mode. A completed report can contain differences or unknown observations and still exits `0`. Use `lcb drift --check` for CI-aware strict observed conformance checking. Check mode evaluates exactly the resources already in Drift scope and passes only when every observation is `in_sync` with complete evidence. It is not Cloud-wide compliance scanning and does not discover additional unmanaged resources.

Any non-conforming observation fails the check, including configuration differences, missing, replaced, or conflicting identities, lifecycle conditions, and incomplete or unknown evidence. Unknown evidence fails closed because CI must not become green when LCB cannot safely establish conformance. Lifecycle conditions are evaluated as strict conformance and lifecycle safety, not only as field-level configuration differences. The result does not depend on whether `lcb apply` could repair the observation; reconciliation status describes capability and context, not conformance.

Check mode is read-only. It does not mutate Laravel Cloud or State, apply repairs, alter attachments, or perform destructive lifecycle operations. Its human output ends with `Check passed.` or, for example, `Check failed: 2 observations violate the policy.` The footer contains only the result and count; it does not expose Cloud IDs, credentials, or secret values. Existing variable redaction and safe Drift output guarantees continue to apply.

For automation, a minimal GitHub Actions step is:

```yaml
- name: Validate Laravel Cloud Blueprint
  env:
    LCB_TOKEN: ${{ secrets.LCB_TOKEN }}
  run: |
    lcb validate
    lcb drift --check
```

The explicit `validate` step is useful as a separate pipeline stage, although `lcb drift` also loads and validates the Blueprint internally. For machine-readable consumers, `lcb drift --json` and `lcb drift --json --check` produce the same JSON document for the same report; check status is communicated only through the process exit code.

> A configuration difference means the current Blueprint and Cloud representation differ. It does not claim whether the Cloud resource drifted remotely or the Blueprint itself changed.

Incomplete evidence remains `unknown`. Variable output never exposes current or desired values, and Drift output contains no remote IDs, secrets, or mutation instructions.

### `apply`

Applies supported creates, updates, and guarded Environment, logical Database, and Database Cluster deletions after producing a fresh plan. Supports `--file=<path>`, `--auto-approve`, `--non-interactive`, and `--json`.

### `import`

Adopts existing Application, Environment, Database Cluster, and logical Database identities into local LCB state without modifying Laravel Cloud resources. Supports `--file=<path>`, `--auto-approve`, `--non-interactive`, and `--json`.

Import requires explicit confirmation unless `--auto-approve` is supplied. Any conflict or unsupported candidate blocks the entire import. Database Clusters are matched by exact name, type, and region; mutable configuration is deliberately not an import-compatibility gate. Logical Databases are matched by exact name within their resolved parent Cluster. Parent resources are proposed before children, and a parent failure blocks its children. Environment variables, Database attachments, credentials, and connection data are never imported into state. Import performs fresh Cloud discovery after acquiring the state lock so that stale preview identities are not persisted. The lock protects local state writers only; it does not lock or freeze Laravel Cloud resources.

### `state:unmanage`

Releases local LCB ownership of one exact State address. Supported address types are `application`, `environment`, `database_cluster`, and `database`; examples include `environment.production` and `database.primary.application`. It never calls Laravel Cloud and does not require `LCB_TOKEN`. The command previews the change and defaults to no; use `--auto-approve` for automation, with optional `--non-interactive` or `--json`.

Ownership release is deliberately non-recursive. An Application or Database Cluster cannot be unmanaged while it has owned children; release those children explicitly first. Missing addresses are successful no-ops. If the resource remains in the blueprint, the next plan uses normal unmanaged discovery semantics; depending on Cloud reality, that can mean an unmanaged match or a supported CREATE. This command does not determine whether the remote resource exists. An existing resource can later follow the normal explicit `lcb import` workflow. This command is not delete, destroy, detach, or Cloud mutation.

Run `lcb <command> --help` for exact usage.

### Machine-readable JSON contracts

The beta-candidate compatibility policy for CLI names and options, JSON evolution, exit codes, resource addresses, Blueprint and State formats, typed Plan/Apply/Drift semantics, redaction, and the feature freeze is documented in [Public Contracts and Compatibility](docs/compatibility.md).

Every first-party command with `--json` emits exactly one JSON document on stdout. Each command owns its contract independently and currently declares top-level `"contract_version": 1`; this version is unrelated to Blueprint schema version 1, State V2, or the CLI release version. Successful and completed no-change or refusal results retain their command-specific fields. Command-owned failures use this common envelope:

```json
{
  "contract_version": 1,
  "status": "error",
  "error": {
    "category": "filesystem",
    "code": "file_not_found",
    "message": "..."
  }
}
```

The stable machine fields are `contract_version`, `status`, `error.category`, `error.code`, and each report's typed fields. Current error categories are `input`, `blueprint`, `state`, `authentication`, `cloud`, `mutation`, `filesystem`, and `output`; `status: "error"` is reserved for this envelope. Human-facing `message`, `reason`, and validation descriptions are non-normative and must not be parsed for decisions. Blueprint validation errors remain structured under `error.validation_errors`.

Contract stability is semantic; general JSON key order and byte representation are not promised. The deliberate exceptions are `drift --json --check` and `state:inspect --json --check`: for the same report, `--check` changes only the process exit status and the JSON bytes remain identical. Symfony errors raised while parsing command-line arguments happen before command execution and are outside this first-party JSON contract, so they may be human-formatted and written to stderr.

`cloud:inspect` and `import` intentionally expose remote IDs for operator discovery and explicit identity adoption. Plan, Apply, Drift, State inspection, and State unmanage machine reports do not expose remote IDs; existing secret, variable-value, credential, and request-payload redaction rules continue to apply.

### Exit codes

- `0`: command or report completed successfully, including current no-op/cancellation results and a passing strict check.
- `1`: operational, Cloud, State, authentication, filesystem, output, or mutation failure/refusal.
- `2`: Blueprint decode/validation or command-owned input-contract failure.
- `3`: a strict check completed, but its policy failed.

Exit code `3` does not mean LCB failed to execute. It means `lcb drift --check` or `lcb state:inspect --check` completed successfully and found observations that fail its strict policy. Normal observational reports continue to exit `0` when they complete.

## Plan Semantics

- `CREATE`: a supported desired resource is missing remotely.
- `UPDATE`: a supported mutable field differs remotely (environment branch or variable value).
- `DELETE`: a State-owned resource is absent from the blueprint. Eligible Environment, logical Database, and Database Cluster actions may execute under the guarded lifecycle below; other resource types remain non-executable.
- `NO_CHANGE`: the remote resource matches the blueprint.
- `UNSUPPORTED`: satisfying the difference would require behavior unavailable in this release.

Text plans use `+` for create, `~` for update, `-` for delete intent, `=` for no change, and `!` for unsupported. When present, DELETE counts are included in text and JSON summaries.

Plan `operation` is the desired-state action and retains the meanings above. Every JSON action also includes `reconciliation`, the current Plan-time LCB capability for that specific action: `supported` means the implementation supports it and the Plan's current prerequisites are satisfied, `blocked` means supported lifecycle behavior is prevented by current evidence or safety conditions, `unsupported` means Apply intentionally lacks that reconciliation capability, and `not_applicable` means no mutation applies, including `no_change`. The `reason` field is human-readable and non-normative; automation should use the typed fields. `supported` is not an execution guarantee: Apply revalidates mutable State, ownership, Cloud, dependency, topology, lifecycle, and recovery evidence under lock before mutation and fails closed if fresh evidence differs.

Planning is read-only and deterministic. State-owned resources absent from the Blueprint are represented child-first as DELETE intent using their exact stored identities; unmanaged same-name resources never become deletion targets. Environment DELETE intent includes conservative live discovery of attached databases, caches, WebSockets, domains, instances, deployments, secrets, filesystems, and default-environment status. Instances are reported as expected Environment children and do not by themselves block deletion; all other discovered categories remain blockers. Missing, malformed, or unknown relationship data is never treated as safe. Only complete, SAFE Environment DELETE actions can reach guarded apply execution.

JSON Environment, Database Cluster, and logical Database DELETE plans expose missing and unknown dependency relationship names for safe diagnosis. These diagnostics contain relationship names only, never dependency IDs or secret values, and do not weaken UNKNOWN readiness.

Dependency discovery prefers Environment relationship linkage. When Laravel Cloud omits domain linkage or the included Application's default-Environment linkage, LCB uses the documented scoped domain-list and Application-get endpoints to obtain only the existence/count or exact identity needed for destructive readiness.

Planning loads local state and treats stored Application, Environment, Database Cluster, and logical Database remote IDs as authoritative ownership. A missing managed identity or same-name replacement is never automatically recreated, adopted, or substituted for an owned deletion identity. Exact-name resources without state ownership may still be inspected read-only; existing unmanaged resources must be explicitly adopted with `lcb import` before LCB can mutate them or create owned children. Genuinely absent Database Clusters and logical Databases under new or already-owned Clusters are eligible for `CREATE`. Database configuration differences remain unsupported.

Removing a managed Environment, logical Database, or Database Cluster from the blueprint produces a DELETE plan. Apply may delete only exact State-owned identities after explicit approval and locked rediscovery. Cluster deletion executes ordinary Databases child-first, then an exactly proven derived default child, checkpoints every confirmed absence, performs bounded GET-only lifecycle settling, and freshly requires empty topology, zero snapshots, disabled retained recovery, and eligible lifecycle before one parent DELETE. There is no force, recursive Cloud-side destroy, cascade assumption, or automatic mutation retry. State ownership is removed only after authoritative exact-ID absence; already-absent recovery never targets a same-name replacement. Application DELETE remains non-executable. `lcb state:unmanage` remains local-only.

Environment variables remain desired-only and are not recorded as owned state resources. Removing a variable key from the blueprint therefore produces no removal action and leaves the remote variable untouched.

## Apply Semantics

Apply always creates a fresh plan and refuses unsupported actions before Cloud mutation. Supported changes request approval unless auto-approved; interactive approval defaults to no, and `--json` or non-interactive mode does not imply approval. Environment, logical Database, and Database Cluster DELETE show a permanent-deletion warning, then reload State and Blueprint under lock and perform fresh exact-ID dependency discovery. DELETE is transmitted at most once and a 204 or error never removes State without subsequent authoritative absence. Cluster absence verification uses an immediate exact GET plus up to eleven delayed GET retries. Each confirmed child absence is checkpointed before parent work; Cloud-side atomicity is not claimed. Potentially destructive or duplicate-creating requests are never automatically retried.

Every resource in Apply JSON has both `operation` and `outcome`. `operation` retains the high-level result (`created`, `updated`, `unchanged`, `deleted`, or `failed`); `outcome` is the stable semantic classification. The outcome vocabulary is `created`, `updated`, `unchanged`, `delete_confirmed`, `already_absent`, `refused`, `conflict`, `uncertain`, `postcondition_failed`, `state_checkpoint_failed`, and `failed`. The optional `message` and validation text are explanatory and non-normative; automation must not parse their wording. Apply JSON summaries always contain integer `created`, `updated`, `unchanged`, `deleted`, and `failed` counts.

`refused` means a known safety condition or definitive Cloud rejection prevented reconciliation, while contradictory exact identity or ownership evidence is `conflict`. `uncertain` is reserved for a mutation that may have been transmitted or applied when authoritative evidence cannot resolve the result; LCB does not blindly retry it. `postcondition_failed` means a response or authoritative follow-up could not satisfy a required result check, including an unusable returned identity or a read that proves the desired relationship was not established. `state_checkpoint_failed` means remote reconciliation was confirmed but its required local ownership checkpoint failed; LCB retains conservative recovery behavior and does not roll back the Cloud mutation. The generic `failed` outcome is reserved for deterministic execution failures that have no more precise classification.

Supported work is processed in dependency order: application, environments, Database Clusters, logical Databases, then environment-variable groups. Environment branch updates use Laravel Cloud's environment PATCH endpoint and accept a confirmed success response without requiring an `attributes.branch` string. Variables are sent per environment with Laravel Cloud's `method=set` mode for both create and update.

Database Cluster and logical Database creation use typed provider-specific requests. Each confirmed remote identity is checkpointed immediately before dependent work continues. Cluster readiness uses bounded, deterministic GET-only observation; neither Cluster nor logical Database POST requests are automatically retried. An uncertain POST outcome or failed state checkpoint stops dependent creation and directs the operator to inspect Laravel Cloud and use explicit import. Database connection and credential fields are discarded at the HTTP parsing boundary.

The safe Database workflow is: define a Cluster and its logical Databases, validate, review the read-only plan, apply, checkpoint each confirmed identity, then verify that the next plan is `NO_CHANGE`. An exact remote match without local ownership remains read-only until explicitly adopted with `lcb import`.

Guarded Database Cluster deletion was validated against a disposable real Laravel Cloud resource: ordinary logical Databases were deleted and checkpointed child-first, the exactly proven derived default was deleted only within the approved parent lifecycle, the Cluster was freshly revalidated and deleted, and the resulting State and plan converged. This verifies the guarded sequence, not transactional Cloud behavior or a Cloud cascade guarantee.

Environment Database attachment updates are guarded and require exact State ownership and authoritative read-after-write confirmation. They do not write State or reconstruct platform-injected variables.

Database Cluster and logical Database creation were verified in a controlled real Laravel Cloud E2E using `neon_serverless_postgres_18` with a Dev-sized configuration. Both identities were checkpointed, the post-create plan reconciled to `NO_CHANGE`, and repeat apply returned `No changes` without rewriting state. No credential material appeared in visible output or state.

Successful application and environment creations are checkpointed as work progresses. Environment branch updates retain their remote identity and do not cause a state save or serial increment solely because of the update. Variables remain outside state, so variable updates likewise do not save or increment state. A pure supported UPDATE apply leaves `.lcb/state.json` unchanged; a mixed CREATE + UPDATE apply can change it when a successful CREATE identity is checkpointed. A later failure is reported as partial, with completed checkpoints retained, and confirmed remote mutations are not rolled back.

## State

Local state is stored in `.lcb/state.json`. Canonical State schema V2 contains remote IDs for LCB-managed Application, Environment, Database Cluster, and logical Database resources. Each resource has a typed ownership classification: ordinary resources are `managed`, while `derived` resources must carry a recognized provenance such as `cluster_create_response`. Resource type and ownership origin remain separate, and secret values are never stored. Variables and Database attachments are not state resources.

State uses local locking and atomic replacement and should not be edited manually. V1 documents remain readable and migrate deterministically in memory: every V1 resource retains its address, remote identity, and parent as ordinary managed ownership, with no provenance inferred from names or Cloud discovery. Read-only loading does not rewrite a V1 file; the next material State mutation writes canonical V2. Future versions remain rejected. `init --from-cloud` neither creates state nor adopts remote resources into existing state.

Managed Application and Environment addresses are resolved by their stored remote IDs. Planning validates their expected type, Environment parent address, and conflicting reuse of a remote ID. State ownership is not silently reassigned when Cloud contains another resource with the same name.

`lcb import` is the only explicit state-adoption workflow. It atomically records matching Application, Environment, Database Cluster, and logical Database identities as ordinary managed resources using State V2. Cluster and logical Database identities are state-owned; Database attachments remain derived read-only relationships and are never persisted. Import does not adopt variables, infer derived provenance, repair conflicts, or modify remote configuration. Normal plan and apply matching never adopt unmanaged resources implicitly.

`lcb state:unmanage <address>` is the explicit inverse ownership operation. It atomically removes only the selected local identity, preserves normal State serial progression, makes no Cloud request, and refuses parents with owned children, including derived children. It never recursively removes ownership or deletes the remote resource.

When LCB creates a Database Cluster, the successful CREATE response must contain exactly one valid default logical Database relationship. LCB atomically checkpoints the Cluster and that child at the reserved internal address `database.<cluster>.__derived_default`, classified as `derived` with `cluster_create_response` provenance, before creating Blueprint-declared Databases. The address and provenance never depend on the Cloud name. Missing, ambiguous, malformed, or conflicting response evidence fails closed, and a valid relationship identity remains sufficient when the corresponding included resource is absent. During Cluster destructive-readiness evaluation, only this exact typed provenance plus complete matching Cluster and Database discovery can classify the child as a `parent_lifecycle_dependency`. It remains retained during ordinary reconciliation and may be deleted only inside an explicitly approved guarded parent lifecycle. Releasing it with `state:unmanage` removes that authorization and makes the live child an unmanaged blocker.

Blueprint logical Database keys may not use the reserved `__derived_default` segment. Blueprint Environment, Environment-variable, Database Cluster, and logical Database keys are address segments: they must be non-empty and may not contain dots, ASCII control characters, or DEL. Application names may contain dots because their address is unambiguous, but may not contain ASCII control characters or DEL. Legacy and imported Clusters do not gain derived provenance through names or later discovery; their existing default children remain unmanaged. Guarded Database Cluster deletion requires exact captured provenance and never infers authorization from a default-looking name or topology.

Clusters created before derived-default provenance tracking may expose their Cloud-created default database as unmanaged. LCB does not infer `DERIVED` ownership from names, ordering, count, or later Cloud reads. Explicit ownership recovery may be required before guarded cleanup.

## Security

- API tokens are never written to blueprints or state.
- Environment-variable values are not printed in plans or apply results and are not persisted in state.
- Use `from_env` for secret material.
- Blueprint `value` fields are literal and version-controlled; do not place secrets in them.
- `sensitive: true` is LCB redaction metadata, not Laravel Cloud Secrets Manager integration.

See [SECURITY.md](SECURITY.md) for vulnerability reporting guidance.

## Known Limitations

- This is early alpha software with a limited mutation model.
- Blueprint schema v1 accepts typed `database_clusters` declarations and Environment `database` references. Discovery, planning, explicit import, Database Cluster CREATE, logical Database CREATE, guarded attachment reconciliation, and guarded logical Database/Cluster DELETE are supported. Existing resources require import before owned mutation. Database configuration UPDATE and general destroy remain unsupported.
- Environment branch and variable value updates are supported; other updates and renames are not. A variable-key change is not an in-place rename and cannot remove the old remote key.
- Application repository changes are explicitly unsupported because changing a repository can affect existing environment branch relationships in Laravel Cloud, requiring a broader lifecycle/rebinding workflow than this release implements. No repository mutation request is sent.
- Application region changes are unsupported.
- Explicit Application, Environment, Database Cluster, and logical Database identity adoption is supported through `lcb import`; variable and Database attachment adoption, automatic adoption, conflict repair, and repository migration or rebinding are not supported.
- Managed resources removed from the blueprint produce DELETE plans. Eligible Environment, logical Database, and Database Cluster actions may execute; other resource types remain in State unless ownership is explicitly released with `lcb state:unmanage`. Rename/state-move semantics are not supported.
- Removed environment-variable keys are not reported because variables do not yet have state ownership; remote variables remain untouched.
- DELETE execution for Application resources, automatic Database detach, destroy commands, force bypasses, drift repair, and remote state are not supported. Database Cluster deletion is limited to the guarded State-owned lifecycle described above.
- Database configuration mutation, caches, storage, domains, and Secrets Manager are not supported.
- `init --from-cloud` does not export environment variables or secrets.
- Source-provider metadata may be absent from API responses and require `--provider`.
- Environment-variable mutation uses Laravel Cloud's `method=set` request mode for both creates and updates.
- A variable missing during plan could be created externally before apply; because Cloud provides `set` rather than conditional create, apply could then update that key.
- Variable values are omitted or redacted from text and JSON plan/apply output, state, and safe exceptions regardless of whether `sensitive: true` is set; Cloud validation and transport errors are sanitized before display.
- Laravel Cloud API behavior may evolve during the alpha lifecycle.

## Roadmap

- Database configuration updates and replacements
- Cache resources
- Richer planning and update semantics
- Broader import workflows and conflict repair
- Broader safe lifecycle operations
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
