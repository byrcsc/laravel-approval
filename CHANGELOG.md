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
- Completed the requester vocabulary. The person who submits a request and may
  later withdraw or revise it is now the **requester** everywhere, replacing the
  mixture of `submitter` and `requester` the earlier steps left behind. The verb
  is untouched: `submitForApproval()`, `Approval::submit()`, `submitted_at`,
  `ActionType::Submitted`, `ApprovalSubmitted`, and `AutoSubmitsForApproval` all
  keep their names, because they describe the act rather than the person.
  Renamed: the `$submitter` argument of `Approval::submit()`,
  `submitForApproval()`, `ApproverResolver::resolve()`, and
  `StageCondition::passes()` → `$requester`; `submitterFor()` →
  `requesterFor()`; `approvalSubmitter()` → `approvalRequester()`;
  `ApprovalRequest::submitter()` → `requester()`; `wasSubmittedBy()` →
  `wasRequestedBy()`; `AutoSubmission::$submitter` → `$requester`;
  `ApprovalNotification::submitterOf()` → `requesterOf()`;
  `SelfApprovalException::forSubmitter()` → `forRequester()`;
  `UnauthorizedApproverException::notTheSubmitter()` → `notTheRequester()`; and
  `MissingSubmitterException` → `MissingRequesterException`.
- Renamed the requester columns to match. For pre-release installs:
  `approval_requests.submitter_type` → `requester_type` and
  `approval_requests.submitter_id` → `requester_id`. `submitted_at` is
  unchanged. Both names are nine characters, so no generated index name
  changes length.
- Renamed the audit hash payload's stage key from `step` to `stage`, which had
  been left frozen to preserve development chains. **This invalidates every
  previously recorded hash chain**, so `approval:verify` will report findings
  against actions recorded before this change. Pre-release installs should
  migrate fresh. No legacy terminology now remains in the shipped package.
- Consolidated the public API around the facade. `returnToSubmitter()` is now
  `returnForRevision()` on the facade, the manager, and the shipped policy; the
  `bulkApprove()` and `bulkReject()` aliases are gone in favour of
  `approveMany()` and `rejectMany()`; and the manager and facade are grouped so
  administrative and operational operations (`cancel`, `reassign`, `resync`,
  `resolve`, `withoutGuard`, `syncDefinitions`, `verifyTrail`,
  `escalateOverdue`) are separated from routine approval work. `decide()`
  remains as an advanced dispatch entry point.
- Reduced the `Approvable` trait to model-centric members. `withdrawApproval()`,
  `withdrawalEligibility()`, `canWithdrawApproval()`, `delegationEligibility()`,
  and `canDelegateApproval()` are removed from the trait; withdrawal is
  `Approval::withdraw()`, and the three eligibility questions now live together
  on `ApprovalRequest` as `approvalEligibility()`/`canBeApprovedBy()`,
  `withdrawalEligibility()`/`canBeWithdrawnBy()`, and
  `delegationEligibility()`/`canBeDelegatedBy()`.
- Completed the fake's public contract. Withdrawal, cancellation, delegation,
  reassignment, resynchronization, conflict resolution, resubmission,
  attachment, guard bypass, definition sync, and overdue processing are now
  recorded alongside submission and decisions, each with a positive assertion,
  so `assertNothingRecorded()` covers every interaction. `assertBulkApproved()`
  and `assertBulkRejected()` are now `assertApprovedMany()` and
  `assertRejectedMany()`, and `assertReturned()` is
  `assertReturnedForRevision()`. `ApprovalInteraction::$type` is now an
  `InteractionType` enum whose `assertion()` names the covering assertion, and
  interactions carry the assignment recipient as a first-class field. An omitted
  submitter now resolves the same way a real submission resolves it.
- Corrected the documented guarantees. The action history is described as
  application-enforced append-only and tamper-evident within a stated trust
  boundary rather than immutable; `approval:verify` documents exactly which rows
  and recorded metadata it covers and states that stored file bytes are never
  read. Record-level approval is documented as approval of an already-persisted
  record, attribute drafts as the built-in hold-until-approved mechanism, and
  concrete assignments as exact actor identities that never expand a Team, Role,
  or Position into its members.
- Rewrote the README, configuration comments, and docblocks in neutral language,
  removing election, parliamentary, conversational, and anthropomorphic framing.
- No compatibility aliases, deprecation period, or consumer migration layer is
  shipped for these renames: the package is unpublished, so removed names are
  removed outright.
- The package remains explicitly pre-1.0.
