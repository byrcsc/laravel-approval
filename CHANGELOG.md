# Changelog

All notable changes to `byrcsc/laravel-approval` will be documented in this file.

## Unreleased

The complete approval engine and its installation schema, currently under
pre-release testing and compatibility review.

### Added

- **First-install schema** — eight configurable tables for workflows, levels,
  requests, steps, approver seats, audit actions, and attachment evidence;
  portable integer, UUID, ULID, and string identity customization; explicit
  cross-database indexes; and public ULIDs for requests.
- **Submit path** — the `Approvable` trait, the `ApproverResolver` contract with
  `StaticUsersResolver` and `CallbackResolver`, workflow validation, and level
  snapshotting. A workflow that cannot reach a decision fails before a single
  row is written.
- **Version-controlled workflows** — array and fluent definitions, dry-run and
  idempotent reconciliation through `approval:sync`, and refusal of destructive
  drift without changing in-flight snapshots.
- **Decision path** — approve, reject, return, cancel, resume, resubmit, and
  bulk approve or reject with seat-based quorum counting, per-request
  transactions, row locks, and attributable outcomes.
- **Eligibility and progress read models** — stable reasons for approval,
  cancellation, and delegation refusal; model and request booleans; current
  step, pending approvers, eager-loadable timelines, and approvable scopes for
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
- **Conditional levels** — container-resolved `LevelCondition` classes,
  evaluated once at submission and snapshotted as visible skipped steps.
- **Delegation and recovery** — delegate or reassign a seat, deliberately
  resync a live request, preserve living quorum during seat mutations, and
  cancel orphaned work when its approvable is hard-deleted.
- **SLA and escalation** — per-level deadlines and four strategies behind a
  swappable config map, reminder cadence, stranded-step detection, bounded
  operational scans, `approval:doctor`, and the idempotent
  `approval:escalate`.
- **Notifications** — four notification classes behind an opt-in event map,
  disabled by default and deferred until the transaction commits.
- **Tamper-evident history** — append-only, per-request hash-chained audit
  actions, actor labels, host-owned attachment references bound into the chain,
  and `approval:verify` for global or request-scoped verification.
- **Host testing support** — exported coherent factories for every package
  model and `Approval::fake()` assertions for submissions and single or bulk
  decisions without state changes or outward side effects.
- **Automatic submission** — an opt-in model hook that submits newly created
  approvables exactly once after persistence.
- **Operations and compatibility** — `approval:status`, database-aware tests
  for SQLite, MySQL, and PostgreSQL, string-identity coverage, PHP 8.3 and 8.4,
  Laravel 12 and 13, PHPStan at maximum level, and Pint.

### Changed

- Replaced the public ballot terminology with request stage progress, including
  assignment, decision, approval, rejection, remaining-approval, satisfaction,
  and stage-status reporting.
- The package remains explicitly pre-1.0. Its public API will receive another
  compatibility pass before a stable `1.0.0` tag.
