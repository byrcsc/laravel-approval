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
against the values seen at submit time. Every transition is written to an
immutable audit log.

> [!WARNING]
> **Under development.** `0.1.0` is the foundation only — schema, models, enums,
> and tooling. There is no `Approvable` trait and no approval behavior yet, so
> the package does nothing useful on its own. Being pre-1.0, the public API may
> change without notice.

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

## Roadmap

Build order — each phase lands green and testable.

1. **Skeleton** — composer, provider, config, migrations, models, enums, arch tests, CI. ← *you are here*
2. **Submit path** — the `Approvable` trait, `SubmitForApproval`, approver resolvers, workflow validation, level snapshotting.
3. **Decision path** — approve, reject, cancel, quorum counting, eligibility rules, audit actions, events.
4. **Draft apply** — diffing, stale checks, the `conflicted` state, conflict resolution, resubmission.
5. **Delegation** — delegate, reassign, and re-sync approvers on a live request. *(A coherent v1.0 ends here.)*
6. **SLA and escalation** — deadlines and per-level escalation strategies, driven by a scheduled command.
7. **Notifications** — opt-in, swappable notification classes.
8. **Polish** — status command, full documentation.

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
