# Contributing

Thank you for helping improve Laravel Cloud Blueprint.

## Workflow

1. Fork the repository and create a focused branch.
2. Install dependencies with `composer install`.
3. Make a scoped change and add tests for every behavior change.
4. Run `composer test` and `composer analyse`.
5. Open a pull request describing the change and its verification.

## Architecture

- Keep business logic separable from the CLI.
- Core and domain namespaces must not depend on Symfony Console or Laravel.
- Framework adapters belong under `Infrastructure`.
- Symfony YAML and HTTP Client must remain behind project-owned abstractions.
- Prefer typed DTOs, contracts, value objects, and enums over raw arrays across boundaries.
- Keep console commands focused on input/output orchestration rather than business logic.

## Safety and Scope

- Never include API tokens, environment-variable values, secrets, or private repository credentials in fixtures, tests, logs, issues, or pull requests.
- Do not add dependencies without prior agreement.
- Keep changes within the supported release scope. Avoid combining feature work with unrelated refactoring.
- Do not add destructive Cloud behavior or live-account requirements to unit tests.

Please review [AGENTS.md](AGENTS.md) for the complete development rules and [SECURITY.md](SECURITY.md) for responsible disclosure guidance.
