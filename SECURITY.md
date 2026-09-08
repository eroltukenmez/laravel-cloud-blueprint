# Security Policy

## Supported Versions

The current supported release is `0.1.0-alpha.13`. As early alpha software, fixes may require upgrading to the latest available alpha.

## Reporting a Vulnerability

Do not open a public issue containing API tokens, environment-variable values, secrets, private repository credentials, or sensitive state data.

Use GitHub private vulnerability reporting if it is enabled for this repository. Otherwise, contact the maintainer privately through a repository-supported channel. Include the affected version, impact, reproduction steps, and a minimal sanitized example.

If credentials may have been exposed, rotate or revoke them immediately before continuing the report.

Security-sensitive issues include:

- Laravel Cloud API token leakage
- Secret or environment-value leakage through output, errors, fixtures, or state
- Unsafe or unintended Laravel Cloud mutations
- State corruption or identity confusion that could target the wrong remote resource

Please allow reasonable time for investigation before public disclosure.
