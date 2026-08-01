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

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

## Installation

Install the package and publish its migration:

```bash
composer require byrcsc/laravel-approval
php artisan vendor:publish --tag="approval-migrations"
php artisan migrate
```

Publish the configuration before the migration when you need custom table names
or non-integer user identities:

```bash
php artisan vendor:publish --tag="approval-config"
```

Set `APPROVAL_USER_KEY_TYPE` to `uuid`, `ulid`, or `string` as needed. The
approvable model key is independent; adjust `approvable_id` in the published
migration when those models do not use integer keys.

Notification wording and its mail views publish separately, and only when you
want to change them:

```bash
php artisan vendor:publish --tag="approval-translations"
php artisan vendor:publish --tag="approval-views"
```

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

Submit the record, optionally with the attribute changes the approval is for,
and record a decision:

```php
use ByRcsc\LaravelApproval\Facades\Approval;

$request = $order->submitForApproval('purchase-order', ['amount' => 10_000]);

Approval::approve($request, $financeUser);
```

The new `amount` is not written to the order until the last required approval is
recorded. Until then it is held on the request and readable through
`$order->pendingDraft()->new`.

That is the whole loop. Approver resolvers, drafts, delegation, escalation,
authorization, and the rest are in the
[documentation](#documentation).

## What is included

- Sequential, database-driven workflows with ordered stages and `1-of-N`,
  `N-of-M`, or unanimous approval requirements.
- Rejection policy configured separately from the approval threshold: reject when
  the required approvals become unreachable, or make any rejection a veto.
- Concrete approvers, plus approver resolvers for roles, managers, teams, and
  organization-specific rules.
- Snapshotted workflows, conditional stages, and version-controlled workflow
  definitions with safe preview and sync commands.
- Several workflows per model, registered against the model type and chosen by
  workflow-level conditions and priority, with the applicable set queryable
  before anything is submitted.
- Attribute drafts, cast-aware stale checks, explicit conflict resolution, and
  revise-and-resubmit flows.
- Eligibility read models, a Laravel policy, bulk decisions, delegation,
  reassignment, and administrative approver resynchronization.
- Optional automatic submission after creation, and guard mode for Eloquent
  writes touching attributes held in a pending draft.
- SLA deadlines, reminders, configurable escalation strategies, stranded-stage
  detection, and operational status commands.
- Notifications with publishable, locale-aware wording and mail views;
  lifecycle events, inbox and progress queries, model scopes, timelines,
  factories, and an `Approval::fake()` test helper.
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

## Out of scope

The package draws its edges deliberately. What follows describes what it sets
out to do rather than what it might do later: treat none of it as planned work,
and none of it as ruled out forever.

- **An approval UI.** No admin screens and no approver inbox. The package ships
  the queries an inbox is built from; the interface is yours.
- **Roles, teams, org charts, or tenancy.** An approver entry names one exact
  actor. A `Team` or `Role` model is never expanded into its members; use an
  approver resolver to do that. Workflow slugs share one global namespace.
- **A publication or visibility state machine.** Approving a request changes the
  request, never the record's lifecycle. Attribute drafts are the one built-in
  hold-until-approved mechanism.
- **Relationship drafts.** Drafts cover model attributes. Relationship changes
  and uploaded file contents stay with the application.
- **BPMN-style branching.** Stages are sequential. There are no parallel stage
  graphs, loops, or arbitrary transitions.
- **Cryptographic audit protection.** No signatures, HMAC, external anchoring,
  or write-once storage, and the verifier never reads a storage disk. See
  [Audit guarantees](#audit-guarantees) for what is and is not covered.
- **Blocking every write path.** Guard mode covers Eloquent model events. Raw
  SQL and query-builder writes are not intercepted.

## Documentation

- [Installation and setup](https://docs.rcsc.dev/laravel-approval/v1/installation)
- [Quick start](https://docs.rcsc.dev/laravel-approval/v1/quick-start)
- [Workflows and stages](https://docs.rcsc.dev/laravel-approval/v1/workflows-and-stages)
- [Choosing the workflow](https://docs.rcsc.dev/laravel-approval/v1/choosing-the-workflow)
- [Approvers and resolvers](https://docs.rcsc.dev/laravel-approval/v1/approvers-and-resolvers)
- [Recording decisions](https://docs.rcsc.dev/laravel-approval/v1/decisions)
- [Attribute drafts](https://docs.rcsc.dev/laravel-approval/v1/attribute-drafts)
- [Notifications](https://docs.rcsc.dev/laravel-approval/v1/notifications)
- [Audit trail and evidence](https://docs.rcsc.dev/laravel-approval/v1/audit-trail)
- [Testing](https://docs.rcsc.dev/laravel-approval/v1/testing)
- [Troubleshooting](https://docs.rcsc.dev/laravel-approval/v1/troubleshooting)

## Development

The local checks mirror CI:

```bash
composer install
composer test
composer analyse
vendor/bin/pint --test
```

PHPStan runs at `max` with no baseline. Tests use SQLite locally and run against
MySQL and PostgreSQL in CI. `tests/Release/` pins the public surface and the
documented guarantees. A failure there is asking whether you meant to change the
API. See [CONTRIBUTING.md](CONTRIBUTING.md).

`workbench/` is a bootable demo application that installs the package the way a
real application would, and exercises every integration seam: resolvers,
workflow and stage conditions, guard mode, drafts and conflicts, escalation,
delegation, evidence, notifications, and a policy above this package's.
`composer build` sets it up; see
[workbench/README.md](workbench/README.md) for the demo loop.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

- Upgrading within `1.x` is safe. Nothing you use will break.
- Only a new major version, like `2.0.0`, can break your code.
- If the README or the documentation describes it, it is safe to build on.
  If they don't, treat it as internal and expect it to change.

Bug fixes go into the newest version only. To get a fix, upgrade to it.

## Questions and issues

- **Stuck, or have an idea?** Start a
  [discussion](https://github.com/byrcsc/laravel-approval/discussions). Usage
  questions and feature ideas both live there.
- **Found a bug you can reproduce?**
  [Open an issue](https://github.com/byrcsc/laravel-approval/issues). A failing
  test is the fastest way to a fix, and a short reproduction is the next best
  thing.
- **Found a security problem?** Please don't open a public issue. See
  [SECURITY.md](SECURITY.md) for how to report it privately.
- **Planning a pull request?** [CONTRIBUTING.md](CONTRIBUTING.md) covers the
  setup and the three checks it needs to pass.

This package is maintained by one person, so replies can take a while.
Everything gets read.

## Credits

- [Ryan Catapang](https://github.com/byrcsc)
- [All contributors](https://github.com/byrcsc/laravel-approval/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).
