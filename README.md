# Laravel Approval

[![run-tests](https://github.com/byrcsc/laravel-approval/actions/workflows/run-tests.yml/badge.svg)](https://github.com/byrcsc/laravel-approval/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/byrcsc/laravel-approval/actions/workflows/phpstan.yml/badge.svg)](https://github.com/byrcsc/laravel-approval/actions/workflows/phpstan.yml)

Multi-level approval workflows for Eloquent models.

Workflows live in the database, so the host app owns the admin UI. A workflow is
an ordered list of levels; each level has a set of approvers and a quorum
(`1-of-N`, `2-of-3`, all). Approvers are concrete records or a resolver
class-string, so roles, managers, and org charts are your code, not this
package's dependency. Submitting snapshots the whole workflow onto the request,
so editing a workflow never disturbs anything already in flight. Both new
records and edits to approved records are supported — an edit is captured as a
JSON draft and applied only once the last level signs off, with a stale check
against the values seen at submit time. Every decision and administrative
change is written to an immutable audit log.

> [!WARNING]
> **Pre-1.0.** The public API may change without notice.

## Installation

```bash
composer require byrcsc/laravel-approval
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-approval-migrations"
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="laravel-approval-config"
```

## Getting started

Add the trait to whatever needs approving:

```php
use ByRcsc\LaravelApproval\Concerns\Approvable;

class PurchaseOrder extends Model
{
    use Approvable;
}
```

Define a workflow. There is no admin UI — these are ordinary models, and
building screens for them is your app's job:

```php
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;

$workflow = ApprovalWorkflow::create([
    'name' => 'Purchase orders',
    'slug' => 'purchase-order',
]);

$teamLead = $workflow->levels()->create([
    'sequence' => 1,
    'name' => 'Team lead',
    'required_approvals' => 1,
    'sla_minutes' => 60 * 24,
]);

$teamLead->approvers()->create([
    'approver_type' => $lead->getMorphClass(),
    'approver_id' => $lead->getKey(),
]);

$finance = $workflow->levels()->create([
    'sequence' => 2,
    'name' => 'Finance',
    'required_approvals' => 2,   // two of whoever the resolver finds
]);

$finance->approvers()->create([
    'resolver' => App\Approvals\FinanceTeam::class,
]);
```

Then submit, and decide:

```php
use ByRcsc\LaravelApproval\Facades\Approval;

// Gate the record itself.
$request = $order->submitForApproval('purchase-order');

// Or gate an edit to it — the change is held, not applied.
$request = $order->submitForApproval('purchase-order', ['amount' => 9_000]);

Approval::approve($request, $lead);
Approval::reject($request, $cfo, 'Over budget for this quarter.');
```

The record is only written when the last level signs off. Until then
`$order->amount` is unchanged and `$order->pendingDraft()` holds what is
proposed.

### Where the workflow comes from

Three places, most specific first:

1. the argument to `submitForApproval()`
2. the model's `approvalWorkflow()` method
3. the `approval.workflows` config map, keyed by model class

### The seams on the model

```php
class PurchaseOrder extends Model
{
    use Approvable;

    // Which workflow. A slug or an ApprovalWorkflow.
    public function approvalWorkflow(): ApprovalWorkflow|string|null
    {
        return 'purchase-order';
    }

    // Which attributes may be drafted. Null means all of them, which is rarely
    // what you want on a model with a `status` column somebody else owns.
    public function approvableAttributes(): ?array
    {
        return ['reference', 'amount'];
    }

    // Who is submitting, when it is not simply the authenticated user.
    public function approvalSubmitter(): ?Model
    {
        return null;
    }
}
```

## Writing a resolver

A resolver answers "who approves this?" at the moment of submission. It runs
once; its answer is written into the request's approver seats and never
consulted again. That is what makes the audit trail mean something — an approver
list that changed underneath an in-flight request would make it a guess.

This package deliberately has **no dependency on spatie/laravel-permission**, or
on any particular idea of roles. A role lookup is just one resolver:

```php
use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use Illuminate\Database\Eloquent\Model;

final class FinanceTeam implements ApproverResolver
{
    public function resolve(
        LevelSnapshot $level,
        Model $approvable,
        Model $submitter,
        array $config,
    ): iterable {
        return User::role('finance')->get();   // or whatever you actually use
    }
}
```

Point a level's `resolver` column at it. Anything in `resolver_config` arrives
as `$config`, so one class can serve several levels.

Resolvers are built by the container, so constructor injection works. They must
be side-effect free: they run during validation, before anything is written, and
a later level turning out to be misconfigured will discard everything.

Two are shipped: `StaticUsersResolver` (a list of primary keys) and
`CallbackResolver` (delegates to a class you already have). Reach for the second
when the logic exists somewhere already; otherwise write the resolver, since an
implementation is about fifteen lines and says what it is in its own name.

## Drafts and the stale check

An approval chain can take days, and nothing stops somebody editing the record
while it runs. When the last level signs off, the engine compares the record
against the values snapshotted at submit time. If any drafted attribute has
moved, the request becomes **conflicted**, the record is left alone, and it
waits for a person:

```php
use ByRcsc\LaravelApproval\Enums\ConflictResolution;

Approval::resolve($request, ConflictResolution::Reapply, $admin);  // draft wins
Approval::resolve($request, ConflictResolution::Discard, $admin);  // record wins
```

The comparison is made on **cast** values, not raw columns — an `encrypted`
attribute produces different ciphertext on every write, and comparing raw would
report drift on a record nobody touched. Dates, enums, decimals, and JSON are
handled the same way. Casts that read sibling attributes are the documented
limit.

A conflict is not an exception. The last approver did nothing wrong, and failing
their click for something they did not do would be an odd way to say so.

## Delegation and reassignment

```php
// "I am away this week — ask her instead."
Approval::delegate($request, $approver, $delegate);

// "She left in March. Give it to him."
Approval::reassign($request, $seat, $successor, $admin);

// Rebuild a live request's seats from the current workflow.
Approval::resync($request, $admin, prune: true);
```

Quorum is counted in **seats**, not people. Delegating to somebody who already
holds a seat on that level is refused: it would turn two seats into one holder
and leave the level permanently one short of its quorum.

`approval:resync` does the same thing from the shell, and is the runbook entry
for "an approver left and their requests are stuck". It never removes a seat
that has voted or been delegated.

## SLA and escalation

Give a level `sla_minutes` and an `escalation_strategy`. The clock starts when
the level becomes somebody's turn, not when the request was submitted — a level
that spent two days behind another level has not been slow.

Nothing escalates unless you schedule the command:

```php
Schedule::command('approval:escalate')->everyFifteenMinutes();
```

| Strategy       | What it does                                                |
|----------------|-------------------------------------------------------------|
| `notify`       | Fires `StepOverdue` and changes nothing. The default.        |
| `escalate`     | Brings in whoever the level's configured resolver names.     |
| `auto_approve` | Carries the level, with no actor on the audit line.          |
| `auto_reject`  | Closes the request, with no actor on the audit line.         |

Each maps to a class in `approval.escalation.strategies`; swap any of them for
your own.

The sweep is idempotent — a one-minute cron and an hourly one produce identical
escalations — and each level is handled in its own transaction, so one
misconfigured level cannot stop the rest.

`escalate` deserves a note. A level with a null `required_approvals` needs *all*
its seats, so adding an escalation approver would make an already-late level
harder to carry. The strategy therefore pins a null quorum to the seat count
before adding anyone: the people it brings in are alternatives to those who did
not act, not extra hurdles.

## Notifications

Off by default. Turn on `approval.notifications.enabled` and five events are
mapped to four shipped notifications. Set any entry to null to silence it, or
point it at your own class:

```php
'map' => [
    Events\ApprovalSubmitted::class => App\Notifications\NeedsYourApproval::class,
    Events\ApprovalCompleted::class => null,   // we handle this ourselves
],
```

A notification here declares its own recipients, which is not the Laravel
convention. The reason is that the audience is the part hosts most often want to
change, and keeping it on the class means one map entry changes both the message
and who reads it. Extend `Notifications\ApprovalNotification`.

Sends are deferred until the surrounding transaction commits, so a request that
is rolled back never tells anybody it needed their approval.

## Events

Every event carries the request, so a listener that only cares which record
moved can type-hint the base `ApprovalEvent`.

`ApprovalSubmitted` · `StepApproved` · `LevelCompleted` · `ApprovalCompleted` ·
`DraftApplied` · `DraftConflicted` · `ApprovalRejected` · `ApprovalCancelled` ·
`ApprovalDelegated` · `ApproverReassigned` · `ApproversResynced` · `StepOverdue`

Events fire inside the transaction that caused them. A listener doing anything
slow or external should be queued and dispatched after commit.

## Commands

| Command             | What for                                                    |
|---------------------|-------------------------------------------------------------|
| `approval:escalate` | Apply each overdue level's policy. Schedule this.           |
| `approval:resync`   | Rebuild one request's approver seats from its workflow.     |
| `approval:status`   | Counts by state, plus the overdue and conflicted lists.     |

## Querying

```php
Approval::awaiting($user);        // somebody's inbox
Approval::overdue();              // past deadline, escalated or not
Approval::pendingFor($order);     // the open request for a record
Approval::requestsFor($order);    // its whole history

$order->isPendingApproval();
$order->pendingDraft();
$order->approvalRequests;
```

### Denormalising the state onto your model

The engine does not need a column on your table — `latestApprovalRequest` and
`isPendingApproval()` answer from the request. But a `where('approval_state',
'pending')` index is worth having once you are listing thousands of records, and
this package deliberately does not add columns to tables it does not own.

If you want one, it is your migration and your listener:

```php
$table->string('approval_state')->nullable()->index();
```

```php
Event::listen(ApprovalEvent::class, function (ApprovalEvent $event): void {
    $event->request->approvable?->forceFill([
        'approval_state' => $event->request->state,
    ])->save();
});
```

Note that Laravel's dispatcher does not match parent classes, so listen to each
event you care about rather than to the base — or give your listener the
`ApprovalEvent` type-hint and register it per event class.

## The audit trail

`approval_actions` is append-only and enforced as such — the model throws on
update and delete. Every decision and administrative change writes a line, so
the log and the workflow's deterministic level order are enough to reconstruct
how a request reached its current state. Corrections work the way they do on
paper: by appending another action.

Some actions have no human actor. An escalation that auto-approves a level
records a null actor, because there was none — "the deadline passed and the
level was approved" is a different fact from "somebody approved it", and an
audit that could not tell them apart would be worth less than none.

## Deliberate limitations

- **Sequential only.** No parallel branches or BPMN-style gateways; level N+1 waits for level N.
- **Rejection is terminal.** There is no "send back to level 2 for rework" — a resubmission is a fresh request starting at level 1, chained to the old one for history.
- **No save interception.** `save()` bypasses the engine; submitting for approval is always explicit.
- **Attribute drafts only.** Relation changes, attach/detach, and file uploads are not draftable.
- **One in-flight request per model.** No competing concurrent drafts.
- **No admin UI.** Workflow CRUD is host-app work; this package ships the models and the validation.
- **Multi-tenancy is inherited, not managed.** Scope workflows yourself; the package is tenant-agnostic.
- **Approver snapshots go stale by design.** Org changes are handled with delegation, reassignment, or a deliberate re-sync — never silently.

## Testing

```bash
composer test
composer analyse
composer format
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
