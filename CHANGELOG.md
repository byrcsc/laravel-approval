# Changelog

All notable changes to `byrcsc/laravel-approval` will be documented in this file.

## Unreleased

The engine. Everything from submitting a request to escalating one nobody
answered.

### Added

- **Submit path** — the `Approvable` trait, the `ApproverResolver` contract with
  `StaticUsersResolver` and `CallbackResolver`, workflow validation, and level
  snapshotting. A workflow that cannot reach a decision fails before a single
  row is written.
- **Decision path** — approve, reject, and cancel, with seat-based quorum
  counting and eligibility checked by the engine rather than trusted from the
  caller. Row-locked, so two approvers deciding in the same second cannot both
  carry a level.
- **Draft apply** — cast-aware stale checking, the `conflicted` state, and
  conflict resolution. Resubmission chains a fresh request to the old one and
  takes a fresh snapshot of the workflow.
- **Delegation** — delegate a seat, reassign one, or resync a live request's
  seats against the current workflow. `approval:resync`.
- **SLA and escalation** — per-level deadlines and four strategies behind a
  swappable config map, driven by the idempotent `approval:escalate`.
- **Notifications** — four notification classes behind an opt-in event map,
  disabled by default and deferred until the transaction commits.
- **`approval:status`** — counts by state, plus the overdue and conflicted
  lists.
- Twelve lifecycle events, and an immutable audit trail that records who made
  each decision or administrative change, including when the answer was
  "nobody, the clock did".

### Changed

- `illuminate/auth`, `illuminate/console`, `illuminate/events`, and
  `illuminate/notifications` are now required.
- `ApproverSource` gained a `Reassigned` case.

## 0.1.0 - 2026-07-28

The package foundation. Schema and models only — no approval behavior yet.

- Seven tables in one atomic migration, with configurable names validated at boot
- Eloquent models for workflows, levels, requests, steps, approver slots, and actions
- `RequestState`, `StepState`, `ActionType`, `EscalationAction`, and `ApproverSource` enums
- ULID public identifiers on requests, and an audit trail that refuses to be updated or deleted
- Pest on Testbench, PHPStan at level max, Pint, and architecture tests
