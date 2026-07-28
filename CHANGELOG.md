# Changelog

All notable changes to `byrcsc/laravel-approval` will be documented in this file.

## 0.1.0 - 2026-07-28

The package foundation. Schema and models only — no approval behavior yet.

- Seven tables in one atomic migration, with configurable names validated at boot
- Eloquent models for workflows, levels, requests, steps, approver slots, and actions
- `RequestState`, `StepState`, `ActionType`, `EscalationAction`, and `ApproverSource` enums
- ULID public identifiers on requests, and an audit trail that refuses to be updated or deleted
- Pest on Testbench, PHPStan at level max, Pint, and architecture tests
