# Public Contracts and Compatibility

This document defines the compatibility candidate for Laravel Cloud Blueprint's supported subset. It applies to first-party LCB commands and data formats; Symfony Console behavior that occurs before an LCB command starts is outside this contract.

## Compatibility lifecycle

- **Alpha:** Public surfaces may change without compatibility guarantees. Changes must be documented.
- **Beta:** Compatibility begins for the supported subset. Command names, custom argument and option names, exit-code meanings, Blueprint v1 semantics, resource-address grammar, State backward readability, ownership and provenance meanings, versioned JSON v1 schemas, and existing enum meanings are frozen.
- **Release candidate:** No intentional public-contract changes. Work is limited to compatibility corrections, release fixes, documentation, and installation validation.
- **Stable 0.1:** Compatibility is preserved throughout `0.1.x`. A breaking contract change requires `0.2.0` with migration and release guidance.

LCB is pre-1.0. A documented minor release may begin a new compatibility line and contain breaking changes; the project does not promise major-version-only breaks before 1.0.

## CLI surface

The beta-candidate first-party CLI surface is:

| Command | Required arguments | Custom options and defaults |
| --- | --- | --- |
| `init` | — | `--file=cloud.blueprint.yaml`, `--force`, `--from-cloud`, `--application`, `--provider`, `--non-interactive` |
| `validate` | — | `--file=cloud.blueprint.yaml` |
| `cloud:inspect` | — | `--json` |
| `plan` | — | `--file=cloud.blueprint.yaml`, `--json` |
| `drift` | — | `--file=cloud.blueprint.yaml`, `--json`, `--check` |
| `apply` | — | `--file=cloud.blueprint.yaml`, `--auto-approve`, `--non-interactive`, `--json` |
| `import` | — | `--file=cloud.blueprint.yaml`, `--auto-approve`, `--non-interactive`, `--json` |
| `state:inspect` | — | `--cloud`, optional `--file`, `--json`, `--check` |
| `state:unmanage` | `address` | `--auto-approve`, `--non-interactive`, `--json` |

Apply, import, and State ownership release default to no when confirmation is required. Automation must use `--auto-approve`; `--non-interactive` and `--json` do not imply approval. `state:unmanage` is a local-only operation. The detailed mutation and safety boundaries remain documented in the README.

## JSON contracts

Every first-party command supporting `--json` owns an independent top-level integer `contract_version`. The current version is `1` for `cloud:inspect`, `plan`, `drift`, `apply`, `import`, `state:inspect`, and `state:unmanage`. It is not the Blueprint schema version, State format version, or CLI release version.

A contract version must change when a command makes an incompatible change such as:

- removing or renaming a required field;
- changing a field's type or nullability;
- changing required nesting or object shape;
- redefining field or enum semantics;
- changing the meaning of an existing enum value.

Compatible evolution may include a genuinely optional additive field, an additional error code inside an existing category, or an additive enum value when consumers are explicitly required to tolerate unknown future values. Additions are not automatically compatible when existing semantics make them required in practice; LCB will assess each change conservatively.

Command-owned failures use:

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

`error.category` and `error.code` are normative. `error.message` is not. Blueprint validation failures include typed entries in `error.validation_errors`; consumers must not classify them by description wording.

In JSON mode, a handled command-owned execution writes exactly one JSON document to stdout without human progress or ANSI output. Symfony parse-time failures such as an unknown command or option, or a missing required argument, happen before command-owned execution and may use Symfony's human stderr behavior.

### Machine and human fields

Automation must use typed fields, never `reason`, `message`, or human validation descriptions.

- Plan classification uses `resource`, `type`, `operation`, `reconciliation`, `ownership`, and typed readiness/destructive metadata.
- Apply classification uses top-level `status` and `summary`, plus each resource's `resource`, `operation`, and `outcome`.
- Drift classification uses `observation`, `ownership`, `reconciliation`, `evidence`, and typed reason codes where present.
- Error classification uses `category` and `code`.

`reason` and `message` exist only to explain typed results to people and may be reworded without a contract version change.

## Exit codes

- `0`: successful or completed execution, including existing no-op and cancellation semantics and a passing strict check.
- `1`: operational/runtime failure, including authentication, Cloud, State, filesystem, lock, output/serialization, and mutation/refusal failures whose command semantics are operational.
- `2`: Blueprint decode/validation or command-owned input-contract failure.
- `3`: a strict check completed successfully but its policy failed.

No other first-party exit codes are defined. Symfony parse-time failures remain outside the command-owned JSON and exit-code contract.

## Resource addresses

Resource addresses are case-sensitive and have these forms:

```text
application.<application>
environment.<environment>
database_cluster.<cluster>
database.<cluster>.<database>
database.<cluster>.__derived_default
database_attachment.<environment>
variable.<environment>.<variable>
```

The parser splits the type prefix at the first dot and treats the remaining non-empty suffix as opaque. There is no escaping mechanism in v1. New Blueprint application names may contain dots, but environment, Database Cluster, logical Database, and variable logical keys may not. Environment and variable keys are segments in composite addresses, so allowing dots would make distinct logical resources collide. ASCII control characters `U+0000` through `U+001F` and `U+007F` (DEL) are forbidden in every new address-bearing Blueprint identifier. `__derived_default` is reserved for LCB's derived logical Database identity.

Historical persisted State addresses remain readable and may be released with `state:unmanage` even when the equivalent identifier would be rejected in a new Blueprint. This compatibility does not make such identifiers valid for new Blueprint declarations.

## Blueprint version 1

Blueprint v1 is the beta-candidate schema. Unknown properties are validation errors. Its supported structure and semantics include `organization`, one `application` with source provider/repository, `environments` with branches and variables, `database_clusters` with logical Databases, and optional Environment Database attachment intent.

A variable declares exactly one of `value` or `from_env`. `sensitive` records intent/display metadata, but output redaction never depends solely on `sensitive: true`; literal and resolved variable values remain protected regardless.

Environment Database attachment intent is exactly tri-state:

- `database` omitted: LCB does not manage the attachment relationship.
- `database: null`: LCB explicitly requires detached.
- `database: cluster.database`: LCB explicitly requires attached to the exact desired logical Database.

Blueprint v2 is not defined.

## State compatibility

State is public but implementation-managed. Operators may inspect it, exact remote identities are intentionally persisted, and recovery workflows may depend on addresses, IDs, classification, and provenance. Manual editing is unsupported. LCB guarantees supported backward reading and migration behavior within the compatibility line, not textual identity of future State formats.

State V1 remains readable and is interpreted as ordinary `managed` ownership. State V2 is the current canonical write format. The serial is a non-negative monotonic revision: it increments only when a material State change is atomically persisted, and no-op saves preserve it.

`managed` means an explicitly created or adopted ordinary resource identity. `derived` means an LCB-owned identity created as a typed consequence of a parent lifecycle. The only current derived provenance is `cluster_create_response`, which records the exact default logical Database identity returned by Database Cluster creation. Current Cloud topology or names cannot reconstruct historical derived provenance. Import never manufactures it.

## Plan contract

`operation` describes desired-state action: `create`, `update`, `delete`, `no_change`, or `unsupported`. `reconciliation` separately describes current Plan-time capability: `supported`, `blocked`, `unsupported`, or `not_applicable`.

`supported` is not an execution guarantee. Apply revalidates mutable Blueprint, State, identity, Cloud, dependency, topology, lifecycle, and recovery evidence and may still refuse or fail safely. For example, an omitted managed Application can produce `operation=delete` with `reconciliation=unsupported`: desired-state deletion exists, but Application deletion is intentionally not implemented. Plan `reason` is explanatory and non-normative.

## Apply contract

Apply reports top-level `success`, `partial_failure`, or `failed`. Command failures before a report exists use `status=error`. Each resource has a high-level `operation` and one of these outcomes:

- `created`: creation was confirmed.
- `updated`: supported reconciliation was confirmed.
- `unchanged`: no mutation was required.
- `delete_confirmed`: a transmitted deletion was authoritatively confirmed absent.
- `already_absent`: authoritative absence was established without sending deletion.
- `refused`: a known safety condition or definitive Cloud rejection prevented reconciliation.
- `conflict`: identity, ownership, or authoritative evidence contradicted the requested reconciliation.
- `uncertain`: a mutation may have been transmitted or applied and authoritative evidence cannot resolve the final result.
- `postcondition_failed`: an authoritative required result was not satisfied.
- `state_checkpoint_failed`: remote reconciliation succeeded or was confirmed, but required local State persistence failed.
- `failed`: a deterministic execution failure has no more precise outcome.

Potentially destructive or duplicate-creating requests are never blindly retried after they may have been transmitted. Cloud operations are not promised to be atomic or automatically rolled back; confirmed State checkpoints are retained across partial failure.

## Drift contract

`ObservationKind` values are `in_sync`, `desired_resource_missing`, `desired_resource_absent`, `configuration_difference`, `identity_missing`, `identity_replacement`, `identity_conflict`, `lifecycle_condition`, and `unknown`.

`OwnershipStatus` values are `managed`, `derived`, `unmanaged`, `conflict`, `none`, and `unknown`. `ReconciliationStatus` values are `supported`, `blocked`, `unsupported`, and `not_applicable`. `EvidenceStatus` values are `complete` and `incomplete`.

`configuration_difference` means the current Blueprint differs from the current Cloud observation. It does not assert which side changed historically. `unknown` requires incomplete evidence and fails strict checking closed. For the same report, `drift --json` and `drift --json --check` are byte-identical; `--check` changes only the process exit status.

## State inspection, import, and ownership release

`state:inspect` is a read-only diagnostic and recovery-guidance surface. It never mutates State or Cloud, manufactures derived provenance, or auto-adopts resources. Its JSON v1 report exposes typed diagnostics, evidence, dispositions, summary counts, and recovery guidance while redacting remote IDs. Normal completed inspection exits `0`; strict `--check` exits `3` when policy fails. For the same report, JSON is byte-identical with and without `--check`.

Import is explicit adoption based on exact identity and name scope: it performs no fuzzy matching, automatic reconciliation, partial adoption, provenance manufacturing, or Cloud mutation. A conflict or unsupported candidate refuses the whole proposal. Successful import changes local State only after locked read-only Cloud revalidation.

`state:unmanage` releases one exact local State address. It performs zero Cloud reads and mutations, refuses a parent while owned children remain, defaults confirmation to no, and requires `--auto-approve` for automation. Historical State addresses remain releasable.

## Security and determinism

Normal Plan, Apply, Drift, `state:unmanage`, and `state:inspect` output must never expose `LCB_TOKEN`, literal or resolved environment-variable values, Database or platform-injected connection credentials, raw Cloud payloads, or dependency IDs used only internally. Remote IDs are intentionally visible in the State file, `cloud:inspect`, and import. IDs are identities, not secrets; State inspection nevertheless redacts them by contract.

For identical normalized inputs and evidence, LCB aims to produce deterministic machine ordering for documented collections. JSON object-key order and general byte representation are not public guarantees. Only the Drift and State inspection check-mode byte-identity guarantees above are frozen.

## Beta feature freeze

Between alpha.14 and beta.1, accepted work is limited to contract bug fixes, compatibility corrections, safety fixes, documentation, tests, install/release validation, and narrowly scoped UX clarity that does not alter public machine semantics.

Deferred beyond beta.1 are Application DELETE, variable DELETE, Database update/replacement, retained-recovery mutation, snapshot deletion, force/cascade/destroy behavior, remote State, domains, caches, and broader Laravel Cloud resource support.
