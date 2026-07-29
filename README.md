# Laravel Approval

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-approval.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-approval)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-approval/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-approval/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-approval/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-approval/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-approval.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-approval)

Multi-level approval workflows for Eloquent models: database-backed
definitions, quorum-based decisions, protected attribute drafts, and verifiable
audit trails.

Built for Laravel applications that need review before records or changes take
effect, it provides the workflow engine while your application keeps ownership
of its UI, users, roles, and organizational rules.

**Full documentation is a work in progress.** Until it is published, this
README keeps the essential installation, usage, feature, and operational notes.

> [!WARNING]
> **Pre-1.0.** The public API may change without notice.

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

## Quick start

Add `Approvable` to the model whose creation or changes need approval:

```php
use ByRcsc\LaravelApproval\Concerns\Approvable;

class PurchaseOrder extends Model
{
    use Approvable;
}
```

Create a workflow and its ordered approval levels. These are ordinary Eloquent
models, so workflow management screens remain part of your application.

```php
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;

$workflow = ApprovalWorkflow::create([
    'name' => 'Purchase orders',
    'slug' => 'purchase-order',
]);

$level = $workflow->levels()->create([
    'sequence' => 1,
    'name' => 'Finance',
    'required_approvals' => 1,
]);

$level->approvers()->create([
    'approver_type' => $financeUser->getMorphClass(),
    'approver_id' => $financeUser->getKey(),
]);
```

### Fetching a workflow's configured approvers

Load the levels, approver entries, and concrete approver models together:

```php
$workflow = ApprovalWorkflow::query()
    ->with('levels.approvers.approver')
    ->where('slug', 'purchase-order')
    ->firstOrFail();

$approversByLevel = $workflow->levels->mapWithKeys(
    fn ($level) => [
        $level->sequence => $level->approvers->map(
            fn ($entry) => $entry->approver ?? $entry->resolver,
        ),
    ],
);
```

Each value is either a concrete approver model or a resolver class-string.
Resolver-backed entries cannot name their users from the workflow alone: the
resolver needs the approvable model, submitter, and level configuration. Its
actual users are resolved and snapshotted when a request is submitted.

### Submitting and deciding

Submit the record, optionally holding proposed attribute changes until the
workflow is complete:

```php
use ByRcsc\LaravelApproval\Facades\Approval;

$request = $order->submitForApproval(
    'purchase-order',
    ['amount' => 10_000],
);
```

Omit the second argument when the record itself is what needs approval. Reject
with `Approval::reject($request, $financeUser, $reason)`. Drafted attributes are
not written to the model until the last required approval; use
`$order->pendingDraft()` to inspect the proposed values.

### Reading the active request

Once submitted, fetch the actual approvers holding seats on the active level:

```php
$request = Approval::pendingFor($order);

$currentStep = $request?->currentStep();
$pendingApprovers = $request?->pendingApprovers() ?? collect();

// Equivalent model-side helpers:
$currentStep = $order->currentApprovalStep();
$pendingApprovers = $order->pendingApprovalApprovers();
```

`pendingApprovers()` returns the concrete, snapshotted holders of the current
level's seats and accounts for delegation. Eligibility determines whether a
holder may still act. For every level and its progress, use
`$request->timeline()`.

Build an approver inbox with the package query:

```php
$requests = Approval::awaiting($user)
    ->withProgress()
    ->get();
```

Ask before rendering or executing a decision. The eligibility result uses the
same authorization calculation as the command and explains a refusal:

```php
$eligibility = Approval::eligibility($request, $user);

if ($eligibility->eligible) {
    Approval::approve($request, $user);
}

$eligibility->reason;          // null, or an Ineligibility enum
$eligibility->ballot?->votes();
$eligibility->ballot?->quorum();
```

Use `$user->can('approve', $request)` when authorizing through Laravel's shipped
policy. Extend that policy when the application has additional organizational
rules.

## What is included

- Sequential, database-driven workflows with ordered levels and `1-of-N`,
  `N-of-M`, or unanimous quorums.
- Concrete approvers and container-resolved approver classes for roles,
  managers, teams, and organization-specific rules.
- Snapshotted workflows, conditional levels, and version-controlled workflow
  definitions with safe preview and sync commands.
- Attribute drafts, cast-aware stale checks, explicit conflict resolution, and
  return-and-resume flows.
- Eligibility read models, a Laravel policy, bulk decisions, delegation,
  reassignment, and deliberate approver re-syncing.
- Optional automatic submission and guard mode for Eloquent writes touching
  attributes held in a pending draft.
- SLA deadlines, reminders, configurable escalation strategies, stranded-step
  detection, and operational status commands.
- Notifications, lifecycle events, inbox and progress queries, model scopes,
  timelines, factories, and an `Approval::fake()` test helper.
- Append-only, hash-chained actions and attachment evidence with verification
  through `approval:verify`.

## Important behavior

- Submission snapshots the workflow, levels, conditions, and approver seats.
  Later workflow or organization changes never silently rewrite in-flight work.
- Approval levels are sequential, and only one request may be in flight for a
  model at a time.
- Drafts cover model attributes only. Relationship changes and uploaded file
  contents remain the host application's responsibility.
- A draft is applied only after final approval. If a drafted value changed
  since submission, the request becomes conflicted and waits for an explicit
  reapply-or-discard decision.
- Guard mode covers ordinary Eloquent model writes. Query-builder updates,
  upserts, quiet saves, and raw SQL remain outside model events; the final stale
  check is the backstop for those paths.
- SLA policies do nothing until `approval:escalate` is scheduled. Queue slow or
  external event listeners and dispatch them after commit.
- The package deliberately provides no admin UI, role package, or tenancy
  layer. The host application owns those concerns and can supply approver
  resolvers or a custom policy.
- Workflow slugs use one global namespace. Shared-database multi-tenant
  applications must scope lookup or include the tenant in each slug.

## Documentation

The full documentation is currently **WIP**. It will cover:

- Installation, identity keys, migrations, and configuration.
- Workflow definitions, resolvers, conditional levels, and synchronization.
- Submission, decisions, drafts, conflicts, returns, and guard mode.
- Delegation, reassignment, bulk decisions, SLAs, and escalation.
- Authorization, querying, timelines, notifications, and events.
- Audit verification, attachment evidence, testing, and operations.

Until then, the feature notes above describe the package boundaries, and the
complete in-progress reference remains under local development.

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
