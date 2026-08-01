# Changelog

All notable changes to `byrcsc/laravel-approval` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-01

First release. The complete approval engine and its installation schema.

### Added

- **First-install schema** — eight configurable tables for workflows, workflow
  stages, requests, request stages, approval assignments, audit actions, and
  attachment evidence;
  portable integer, UUID, ULID, and string identity customization; explicit
  cross-database indexes; and public ULIDs for requests.
- **Submit path** — the `Approvable` trait, the `ApproverResolver` contract with
  `StaticUsersResolver` and `CallbackResolver`, workflow validation, and stage
  snapshotting. A workflow that cannot reach a decision fails before a single
  row is written.
- **Version-controlled workflows** — array and fluent definitions, dry-run and
  idempotent reconciliation through `approval:sync`, and refusal of destructive
  drift without changing in-flight snapshots.
- **Decision path** — approve, reject, return for revision, cancel, withdraw,
  revise and resubmit, and bulk approve or reject with assignment-based
  approval counting, per-request transactions, row locks, and attributable
  outcomes.
- **Eligibility and progress read models** — stable reasons for approval,
  cancellation, and delegation refusal; model and request booleans; current
  stage, pending approvers, eager-loadable timelines, and approvable scopes for
  state and approver inboxes.
- **Authorization** — a convention-discovered `ApprovalRequestPolicy` for
  approve, reject, return, cancel, delegate, and view abilities, backed by the
  same checks as the engine.
- **Draft apply** — cast-aware stale checking, the `conflicted` state, and
  conflict resolution. Resubmission chains a fresh request to the old one and
  takes a fresh snapshot of the workflow.
- **Guard mode** — opt-in Eloquent protection for pending drafted attributes
  plus a re-entrant, record-scoped, audited `withoutApprovalGuard()` escape
  hatch; the final stale check remains the backstop for writes outside model
  events.
- **Conditional stages** — container-resolved `StageCondition` classes,
  evaluated once at submission and snapshotted as visible skipped stages.
- **Several workflows per model** — an `approvable_type` column registers a
  workflow against a model type, container-resolved `WorkflowCondition` classes
  decide which of them applies to the submission in hand, and `priority` orders
  what is left. Equally specific candidates are refused rather than resolved
  arbitrarily. `Approval::workflows()` and `Approval::workflowsFor()` are the
  listing queries behind a workflow picker.
- **Delegation and recovery** — delegate or reassign an assignment,
  deliberately resync a live request, preserve living assignment coverage
  during ownership changes, and close orphaned work when its approvable is
  hard-deleted.
- **SLA and escalation** — per-stage deadlines and four strategies behind a
  swappable config map, reminder cadence, stranded-stage detection, bounded
  operational scans, `approval:doctor`, and the idempotent
  `approval:escalate`.
- **Notifications** — built-in notifications for requested, approved,
  rejected, returned-for-revision, assigned, conflicted, and overdue events
  behind an opt-in event map, disabled by default and deferred until the
  transaction commits.
- **Publishable notification content** — every user-facing string in
  `resources/lang/en/approval.php` and every mail body in
  `resources/views/mail`, published with `approval-translations` and
  `approval-views`. Messages follow the application locale, placeholders carry
  the record name rather than concatenation fixing the word order, and the
  views read the translations, so republishing one half does not freeze the
  other. `ApprovalNotification::mailFor()` gives a replacement notification the
  same path.
- **Append-only action history** — application-enforced append-only, per-request
  hash-chained actions that are tamper-evident within a documented trust
  boundary, actor labels, host-owned attachment references bound into the chain,
  and `approval:verify` for global or request-scoped verification.
- **Host testing support** — exported coherent factories for every package
  model and an `Approval::fake()` that records every public mutation, with a
  matching assertion for each and no approval-state writes.
- **Automatic submission** — an opt-in model hook that submits newly created
  approvables exactly once after persistence.
- **Operations and compatibility** — `approval:status`, database-aware tests
  for SQLite, MySQL, and PostgreSQL, string-identity coverage, PHP 8.3 and 8.4,
  Laravel 12 and 13, PHPStan at maximum level, and Pint.
- **Workbench demo application** — `workbench/` is a bootable Laravel app that
  installs the package the way a real application would and exercises every
  integration seam: approver resolvers, workflow and stage conditions, guard
  mode and its bypass, drafts and conflict resolution, SLA escalation with a
  replaced strategy, delegation and administrative reassignment, attachment
  evidence, opt-in notifications with a replaced message and audience, and a
  policy extending the package's. Set it up with `composer build`; see
  `workbench/README.md`. Development tooling only — it is excluded from the
  Composer dist archive and is not covered by CI.

[Unreleased]: https://github.com/byrcsc/laravel-approval/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/byrcsc/laravel-approval/releases/tag/v1.0.0
