# Laravel Approval

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-approval.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-approval)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-approval/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-approval/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-approval/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-approval/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-approval.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-approval)

Multi-stage approval workflows for Eloquent models: database-backed workflow
definitions, configurable approval and rejection rules, attribute drafts held
until approval, and a verifiable action history.

The package provides the approval engine. Your application keeps ownership of
its UI, users, roles, and organizational rules.

**Full documentation is a work in progress.** Until it is published, this README
covers installation, usage, the feature set, and the operational notes.

> [!WARNING]
> **Pre-1.0.** The public API may change without notice. Names removed before
> 1.0 are removed outright; no compatibility aliases are shipped.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

## Installation

Install the package and publish its migration:

```bash
composer require byrcsc/laravel-approval
php artisan vendor:publish --tag="laravel-approval-migrations"
php artisan migrate
```

Publish the configuration before the migration when you need custom table names
or non-integer user identities:

```bash
php artisan vendor:publish --tag="laravel-approval-config"
```

Set `APPROVAL_USER_KEY_TYPE` to `uuid`, `ulid`, or `string` as needed. The
approvable model key is independent; adjust `approvable_id` in the published
migration when those models do not use integer keys.

## What the package approves

An **approval request** is one execution of an **approval workflow** for one
already-persisted record. A workflow is made of ordered **approval stages**, each
holding one **approval assignment** per approver. An approver records an
**approval decision**: approve, reject, or return for revision.

The package controls approval state and history. It does not control your
model's lifecycle:

- **Record-level approval** means a request is associated with a record that has
  already been saved. Approving it changes the request's state and history and
  nothing about the record. The package does not hide, publish, activate,
  revert, or delete the record, and it does not gate its creation.
- **Attribute drafts** are the built-in hold-until-approved mechanism. Submit
  proposed attribute values with the request and they are held in the request
  rather than written to the record; they are applied only after the final
  approval.

If you need creation, publication, or visibility gating, keep that in your
application, driven by the package's events and request state.

## Quick start

Add `Approvable` to the model that needs approval:

```php
use ByRcsc\LaravelApproval\Concerns\Approvable;

class PurchaseOrder extends Model
{
    use Approvable;
}
```

Create a workflow and its ordered approval stages. These are ordinary Eloquent
models, so workflow management screens remain part of your application.

```php
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;

$workflow = ApprovalWorkflow::create([
    'name' => 'Purchase orders',
    'slug' => 'purchase-order',
]);

$stage = $workflow->stages()->create([
    'sequence' => 1,
    'name' => 'Finance',
    'required_approvals' => 1,
]);

$stage->approvers()->create([
    'approver_type' => $financeUser->getMorphClass(),
    'approver_id' => $financeUser->getKey(),
]);
```

`required_approvals` is the number of approvals the stage needs. Omit it, or set
it to null, and every resolved assignment must approve. Fluent definitions,
array definitions, persisted rows, and request snapshots all read omission the
same way.

### Approver identity

An approver entry names **one exact actor identity**. The model named on the
entry is the only identity that may decide it.

A `Team`, `Role`, `Position`, or other container model is **not** expanded into
its members. Assigning a team assigns the team record itself, and only something
acting as that team can approve. To assign the members, use an approver
resolver, which is resolved once at submission and must return persisted actor
models:

```php
use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

final class FinanceTeamMembers implements ApproverResolver
{
    public function resolve(
        StageSnapshot $stage,
        Model $approvable,
        Model $submitter,
        array $config,
    ): iterable {
        return Team::findOrFail($config['team'])->members;
    }
}

$stage->approvers()->create([
    'resolver' => FinanceTeamMembers::class,
    'resolver_config' => ['team' => $financeTeam->getKey()],
]);
```

Resolvers are the seam for roles, teams, reporting lines, and any other
organizational rule. Their answer is snapshotted onto the request, so a later
organizational change does not rewrite in-flight work.

### Fetching a workflow's configured approvers

Load the stages, approver entries, and concrete approver models together:

```php
$workflow = ApprovalWorkflow::query()
    ->with('stages.approvers.approver')
    ->where('slug', 'purchase-order')
    ->firstOrFail();

$approversByStage = $workflow->stages->mapWithKeys(
    fn ($stage) => [
        $stage->sequence => $stage->approvers->map(
            fn ($entry) => $entry->approver ?? $entry->resolver,
        ),
    ],
);
```

Each value is either a concrete approver model or a resolver class-string. A
resolver-backed entry cannot name its approvers from the workflow alone: it needs
the approvable model, the requester, and the stage configuration. Its approvers
are resolved and snapshotted when a request is submitted.

> [!NOTE]
> The requester reaches a resolver as its `$submitter` argument, and is stored in
> the `submitter_type` / `submitter_id` columns. Those persisted names predate the
> move to *requester* in the documented vocabulary and are kept to avoid a schema
> and contract rename; they mean the same thing.

### Submitting and deciding

Submit the record, optionally holding proposed attribute changes until the
workflow completes:

```php
use ByRcsc\LaravelApproval\Facades\Approval;

$request = $order->submitForApproval(
    'purchase-order',
    ['amount' => 10_000],
);
```

Omit the second argument for a record-level request. Drafted attributes are not
written to the model until the last required approval; use
`$order->pendingDraft()` to inspect the proposed values.

The three decisions are on the facade:

```php
Approval::approve($request, $financeUser);
Approval::reject($request, $financeUser, $reason);
Approval::returnForRevision($request, $financeUser, $whatToFix);
```

A returned request waits on its requester, who revises the draft and resubmits.
That creates a new linked request starting from the first applicable stage of a
fresh workflow snapshot:

```php
use ByRcsc\LaravelApproval\Data\RevisedDraft;

Approval::reviseAndResubmit($request, RevisedDraft::withAttributes([
    'amount' => 8_000,
]));
```

`RevisedDraft::inherit()` carries the previous draft forward unchanged, and
`RevisedDraft::none()` submits the record itself. Revised attributes are
revalidated against the model's `approvableAttributes()`.

A requester withdraws their own pending request; an approver hands their
assignment to someone else; either may attach supporting evidence:

```php
use ByRcsc\LaravelApproval\Data\AttachmentEvidence;

Approval::withdraw($request, $requester, 'Raised in error');
Approval::delegate($request, $financeUser, $deputy, 'On leave this week');

Approval::attach(new AttachmentEvidence(
    request: $request,
    disk: 's3',
    path: 'quotes/PO-1001.pdf',
    checksum: hash_file('sha256', $localPath),
    name: 'Supplier quote.pdf',
    uploader: $requester,
));
```

### Deciding many at once

Bulk operations use one naming convention, the `Many` suffix. Each request is
decided in its own transaction, so one failure does not roll back the rest:

```php
$result = Approval::approveMany($requests, $financeUser);
$result = Approval::rejectMany($requests, $financeUser, 'Over budget');

$result->successful();   // list<BulkDecisionOutcome>
$result->failed();       // each carrying the request and its exception
```

`Approval::decideMany($requests, fn ($request) => $decision)` is the advanced
form, for building a different decision per request.

### Reading the active request

```php
$request = Approval::pendingFor($order);

$currentStage = $request?->currentStage();
$pendingApprovers = $request?->pendingApprovers() ?? collect();

// Equivalent model-side helpers:
$currentStage = $order->currentApprovalStage();
$pendingApprovers = $order->pendingApprovalApprovers();
```

`pendingApprovers()` lists only the assignment holders who may still respond,
accounting for delegation. `currentStageApprovers()` lists every holder of the
current stage, including those who have already responded. For every stage and
its progress, use `$request->timeline()`.

Build an approver inbox with the package query:

```php
$requests = Approval::awaiting($user)
    ->withProgress()
    ->get();
```

Ask before rendering or executing a decision. The eligibility result uses the
same calculation as the command and explains a refusal:

```php
$eligibility = Approval::eligibility($request, $user);

if ($eligibility->eligible) {
    Approval::approve($request, $user);
}

$eligibility->reason;          // null, or an Ineligibility enum
$progress = $request->stageProgress();

$progress?->assignedApprovers;
$progress?->decisionsRecorded;
$progress?->approvalsReceived;
$progress?->approvalsRequired;
$progress?->remainingApprovals;
$progress?->rejectionsReceived;
$progress?->status;
$progress?->isSatisfied();
```

Withdrawal and delegation eligibility are request-level questions:

```php
$request->canBeWithdrawnBy($requester);
$request->canBeDelegatedBy($approver);
$request->canBeApprovedBy($approver);
```

Use `$user->can('approve', $request)` when authorizing through the shipped
policy. Its abilities are `view`, `approve`, `reject`, `returnForRevision`,
`withdraw`, `delegate`, and `cancel`. `cancel` denies by default because
administrative cancellation is host-owned; grant it in your own policy. Extend
the shipped policy when your application has additional organizational rules.

### The routine facade surface

One path per routine command and query. `$model->submitForApproval(...)` is the
only model-side alias, for the one operation that starts on the model.

| Command | |
|---|---|
| `Approval::submit()` | Submit a persisted record, optionally with a draft |
| `Approval::approve()` / `reject()` / `returnForRevision()` | The three decisions |
| `Approval::decide()` | Advanced: record a decision built elsewhere |
| `Approval::approveMany()` / `rejectMany()` / `decideMany()` | The same, in bulk |
| `Approval::withdraw()` | Requester withdrawal |
| `Approval::delegate()` | An approver hands their assignment on |
| `Approval::reviseAndResubmit()` | Revise a closed request into a new linked one |
| `Approval::attach()` | Put a file in evidence |

| Query | |
|---|---|
| `Approval::eligibility()` / `canApprove()` | May this actor decide, and why not |
| `Approval::withdrawalEligibility()` / `canWithdraw()` | May this actor withdraw |
| `Approval::delegationEligibility()` / `canDelegate()` | May this actor delegate |
| `Approval::awaiting()` | The approver inbox |
| `Approval::overdue()` | Open requests past their stage deadline |
| `Approval::pendingFor()` | A model's one unresolved request, or null |
| `Approval::requestsFor()` | A model's request history, newest first |
| `Approval::workflow()` / `findWorkflow()` / `workflowFor()` | Workflow lookup |

The `canX()` forms return the boolean; the eligibility forms return the reason
too. The request-level equivalents (`$request->canBeApprovedBy()` and friends)
answer the same questions when you already hold the request.

### Administrative and operational operations

These are not part of routine approval flow. Keep them behind administrative
authorization, a console command, or a scheduled job:

| Operation | What it does |
|---|---|
| `Approval::cancel()` | Administrative cancellation; requires an attributable actor |
| `Approval::reassign()` | Rewrites which approver an assignment belongs to |
| `Approval::resync()` | Rebuilds a live request's assignments from the current workflow |
| `Approval::resolve()` | Reapplies or discards a conflicted draft |
| `Approval::withoutGuard()` | Runs a correction while guard mode holds a draft |
| `Approval::syncDefinitions()` | Creates, updates, or previews persisted workflows |
| `Approval::verifyTrail()` | Walks the action history's hash chains |
| `Approval::escalateOverdue()` | Applies each overdue stage's escalation policy |

The console equivalents are `approval:status`, `approval:sync`,
`approval:escalate`, `approval:verify`, `approval:resync`, and
`approval:doctor`. `approval:resync` takes `--operator` so the assignment change
is attributed to a person rather than to shell history.

### Testing

`Approval::fake()` records every public mutation instead of performing it, and
provides an assertion for each:

```php
$fake = Approval::fake();

$order->submitForApproval('purchase-order', ['amount' => 10_000]);

$fake->assertSubmitted(
    $order,
    fn ($recorded) => $recorded->metadata['new_attributes'] === ['amount' => 10_000],
);

$fake->restore();
```

Recorded operations cover submission, decisions, bulk decisions, withdrawal,
cancellation, delegation, reassignment, resynchronization, conflict resolution,
resubmission, attachment, guard bypass, definition sync, and overdue processing.
Each has a matching assertion, and `assertNothingRecorded()` covers all of them.
Nothing is written to the approval tables while the fake is active.

## What is included

- Sequential, database-driven workflows with ordered stages and `1-of-N`,
  `N-of-M`, or unanimous approval requirements.
- Rejection policy configured separately from the approval threshold: reject when
  the required approvals become unreachable, or make any rejection a veto.
- Concrete approvers, plus approver resolvers for roles, managers, teams, and
  organization-specific rules.
- Snapshotted workflows, conditional stages, and version-controlled workflow
  definitions with safe preview and sync commands.
- Attribute drafts, cast-aware stale checks, explicit conflict resolution, and
  revise-and-resubmit flows.
- Eligibility read models, a Laravel policy, bulk decisions, delegation,
  reassignment, and administrative approver resynchronization.
- Optional automatic submission after creation, and guard mode for Eloquent
  writes touching attributes held in a pending draft.
- SLA deadlines, reminders, configurable escalation strategies, stranded-stage
  detection, and operational status commands.
- Notifications, lifecycle events, inbox and progress queries, model scopes,
  timelines, factories, and an `Approval::fake()` test helper.
- An append-only, hash-chained action history with attachment evidence and
  verification through `approval:verify`.

## Important behavior

- Submission snapshots the workflow, its stages, their conditions, and the
  resolved approval assignments. Later workflow or organizational changes never
  rewrite in-flight work.
- Approval stages are sequential, and a model has at most one unresolved
  request. Pending and conflicted requests both count as unresolved.
- Drafts cover model attributes only. Relationship changes and uploaded file
  contents remain the application's responsibility.
- A draft is applied only after final approval. If a drafted value changed since
  submission, the request becomes conflicted and waits for an explicit
  reapply-or-discard decision. A conflicted request receives no completion
  timestamp and no approved notification.
- Guard mode covers ordinary Eloquent model writes. Query-builder updates,
  upserts, quiet saves, and raw SQL do not dispatch model events; the stale check
  before draft application is the backstop for those paths.
- SLA policies do nothing until `approval:escalate` is scheduled. The
  `record_only` default records the overdue state and emits an event; it sends
  nothing unless notifications are enabled.
- Notification delivery is opt-in and disabled by default.
- The package provides no admin UI, role package, or tenancy layer. Your
  application owns those, and can supply approver resolvers or a custom policy.
- Workflow slugs use one global namespace. Shared-database multi-tenant
  applications must scope lookups or include the tenant in each slug.

## Audit guarantees

The action history is **application-enforced append-only** and
**tamper-evident** within a stated trust boundary. It is not immutable,
tamper-proof, or externally anchored.

- Append-only is enforced by the `ApprovalAction` and `ApprovalAttachment`
  models, which refuse updates and deletes. A direct query-builder or SQL write
  bypasses that enforcement.
- Each action's SHA-256 hash covers its own content and the hash of the action
  before it, per request. That makes an edit, deletion, reordering, or insertion
  detectable. The hash is unkeyed and stored beside the data it covers, so
  anyone who can write to the database directly can recompute a consistent
  chain. The guarantee holds against accidental and application-level
  modification, not against a database administrator.

`approval:verify` reports findings and never repairs. A clean result covers:

- action content and the chain linkage between actions,
- the request's recorded chain head,
- attachment rows, and
- the attachment metadata recorded at attach time, including the checksum the
  application supplied.

A clean result does **not** cover:

- request state, draft attributes, workflow stage snapshots, or assignment rows,
  none of which are chained; and
- the stored files themselves. Their bytes are never read and their checksums are
  never recomputed. A checksum row that matches its recorded action is treated as
  valid. Reading storage disks is out of scope for the current verifier.

The package provides no cryptographic signatures, HMAC protection, external hash
anchoring, or write-once storage.

## Documentation

The full documentation is currently **WIP**. It will cover:

- Installation, identity keys, migrations, and configuration.
- Workflow definitions, resolvers, conditional stages, and synchronization.
- Submission, decisions, drafts, conflicts, returns, and guard mode.
- Delegation, reassignment, bulk decisions, SLAs, and escalation.
- Authorization, querying, timelines, notifications, and events.
- Audit verification, attachment evidence, testing, and operations.

Until then, the notes above describe the package boundaries, and the complete
in-progress reference remains under local development.

## Support and contributing

Ask usage questions in
[GitHub Discussions](https://github.com/byrcsc/laravel-approval/discussions).
For reproducible package bugs,
[open an issue](https://github.com/byrcsc/laravel-approval/issues).

Report vulnerabilities privately through the repository's
[security advisory form](https://github.com/byrcsc/laravel-approval/security/advisories/new).

## Credits

- [Ryan Catapang](https://github.com/byrcsc)
- [All contributors](https://github.com/byrcsc/laravel-approval/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).
