# Roadmap

This roadmap describes planned areas of improvement for dPanel. It is not a fixed promise or release schedule.

## Near Term

- Improve first-time installer reliability on fresh Ubuntu/Debian servers.
- Expand file manager permission repair coverage and diagnostics.
- Add clearer panel UI states for API failures and repair suggestions.
- Improve `drust` API documentation with request and response examples for every endpoint.
- Add more automated checks for docs, Rust code, and installer scripts.

## Core Platform

- Harden website provisioning and vhost sync workflows.
- Improve PHP version detection and pool configuration.
- Add stronger audit trails for privileged operations.
- Improve queue worker and supervisor installation flows.
- Add safer rollback behavior for web-stack changes.

## Hosting Features

- Improve SSL issuance and renewal visibility.
- Expand database user and privilege management.
- Improve backup scheduling and restore workflows.
- Add more DNS and mail management helpers.
- Improve monitoring and alerting.

## Developer Experience

- Add local development examples.
- Add API client examples for `drust`.
- Add contribution labels and issue triage workflow.
- Add architecture diagrams and screenshots.
- Add release packaging guidance.

## Security

- Keep `drust` localhost-only and token protected.
- Continue hardening file path validation.
- Add more tests around permission repair and unsafe path handling.
- Improve secret redaction in logs and reports.

