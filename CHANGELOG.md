# Changelog

All notable changes to `byrcsc/laravel-approval` will be documented in this file.

## Unreleased

The complete approval engine and its installation schema, currently under
pre-release testing and compatibility review.

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
- Replaced resume with an explicit revise-and-resubmit lifecycle.
  `Approval::reviseAndResubmit()` takes a `RevisedDraft` that states whether
  the replacement inherits the previous draft, submits a revised draft (which
  may add newly allowed attributes), or gates the record itself. Returned,
  rejected, withdrawn, cancelled, and conflicted requests all produce a new
  linked request; `ApprovalResubmitted` replaces `ApprovalResumed` and the
  `resumed` audit action is gone.
- Made progress helpers and scopes truthful. `pendingApprovers()` now excludes
  holders who already responded and `currentStageApprovers()` lists every
  holder; model scopes (`pendingApproval`, `approvalApproved`,
  `approvalRejected`, and friends) inspect the latest request while
  `everInApprovalState()` searches history; `approvalTimeline()` became
  `latestApprovalTimeline()`; `workflowName()` became `workflowSlug()`; and
  `pendingDraft()` returns a `Draft` object or null instead of an array.
- Completed assignment notifications. When notifications are enabled,
  delegation, reassignment, resynchronization additions, and escalation
  additions notify the new assignment holder after the assignment exists and
  the transaction commits, and return-for-revision notifies the requester.
  Reminders now emit `StageOverdueReminder`, distinct from the initial
  `StageOverdue`, and escalation additions emit `ApproversEscalated`.
- Required attributable operators for administrative assignment changes.
  `Approval::reassign()` and `Approval::resync()` require an actor, and
  `approval:resync` requires `--operator`. Automated escalations and reminders
  are recorded as system actions with `automatic` metadata.
- Renamed the non-deciding overdue strategy from `notify` to `record_only`:
  it records overdue state and emits an event, and promises no delivery.
- Migrated every remaining internal to the canonical vocabulary. Models are
  now `ApprovalWorkflowStage`, `ApprovalWorkflowStageApprover`,
  `ApprovalRequestStage`, and `ApprovalRequestAssignment`; events are
  `StageCompleted`, `StageApprovalRecorded`, `StageOverdue`, and
  `StageStranded`; definitions use `WorkflowBuilder::stage()`,
  `StageBuilder`, `StageDefinition`, and a `stages` array key; `StageStatus`
  replaces `StepState`; and the `level_skipped` audit action is now
  `stage_skipped`.
- Renamed the schema to match. For pre-release installs, the old-to-new
  mapping is: `approval_workflow_levels` → `approval_workflow_stages`,
  `approval_workflow_level_approvers` → `approval_workflow_stage_approvers`
  (`level_id` → `stage_id`), `approval_request_steps` →
  `approval_request_stages`, `approval_request_step_approvers` →
  `approval_request_assignments` (`step_id` → `stage_id`),
  `approval_actions.step_id` → `stage_id`, and
  `approval_requests.current_level_sequence` → `current_stage_sequence`, with
  `table_names` config keys `workflow_stages`, `workflow_stage_approvers`,
  `request_stages`, and `assignments`. Persisted `escalation_strategy` values
  of `notify` become `record_only`. The audit hash serialization is unchanged,
  so existing chains still verify after the rename.
- The package remains explicitly pre-1.0. Its public API will receive another
  compatibility pass before a stable `1.0.0` tag.
