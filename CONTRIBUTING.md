# Contributing

Thanks for helping. Clear, focused pull requests are easier to review and
maintain.

## Getting set up

```bash
git clone https://github.com/byrcsc/laravel-approval
cd laravel-approval
composer install
```

No database server and no `.env` are needed. The suite runs against an in-memory
SQLite database that Testbench sets up for you.

To reproduce a CI matrix failure locally, point the suite at a real engine with
`DB_DRIVER`:

```bash
DB_DRIVER=mysql composer test
DB_DRIVER=pgsql composer test
```

That path exists because SQLite does not tell the truth about `lockForUpdate()`,
cascade behavior, or index use, and this package depends on all three.

## The three checks

All three must be green before a pull request can be merged. CI runs them too,
but running them locally is faster than waiting.

```bash
composer test      # Pest, random order
composer analyse   # PHPStan, level max
composer format    # Pint, applies fixes
```

Two things worth knowing:

- **PHPStan runs at level max with no baseline.**
  If an error is genuinely a false positive, explain it in the pull request so
  we can find the right fix. Do not add `@phpstan-ignore`, a baseline entry, or
  a cast only to silence it.
- **Pint is the only style authority.** Run `composer format` before pushing.
  Avoid manual formatting that conflicts with its output.

The suite runs in random order and fails on warnings, risky tests, and an empty
suite. A test that passes only in a particular order is a bug in the test.

## The workbench

`workbench/` is a bootable demo application that installs the package the way a
real application would. It is where a change is driven by hand before it is
documented, and the only place the parts that need a running application meet
one: scheduled escalation, notification delivery, and policy gates.

```bash
composer build    # set the demo up
composer clear    # tear it down again
```

See [workbench/README.md](workbench/README.md) for the demo loop. It is excluded
from the Composer dist archive and is not covered by CI, so it may drift — if
you change a public seam, run it and fix what broke.

## Where tests go

Tests are grouped by behavior, not by class: `Submit/`, `Decide/`, `Draft/`,
`Delegate/`, `Escalate/`, `Guard/`, `Lifecycle/`, `Notify/`, `Audit/`,
`Commands/`, `Models/`, and `Release/`. Extend the group that owns the behavior
rather than adding a parallel suite for a class.

The primary seam is the `Approval` facade combined with persisted models. Submit
a request, decide it, and assert the observable request, model, event,
notification, and audit outcomes. Reach for a direct action class only when the
behavior has no facade equivalent.

Two directories deserve a warning:

- **`tests/Release/`** pins the public surface, the documented guarantees, and
  the terminology. If a change there fails, that is usually the point: it is
  asking whether you meant to change the public API or to reintroduce language
  the package deliberately removed. Update it consciously, in the same pull
  request, and say so.
- **`tests/ArchTest.php`** enforces strict types, string-backed enums, a single
  catchable exception root, and no leftover debugging calls.

## What a good change looks like

**Adding a public method.** Decide first whether it belongs on the facade, the
`Approvable` trait, or `ApprovalRequest`. The facade is the mutation and query
surface; the trait keeps model-centric members only; the request model answers
questions about itself. Add it to the `Release/PublicSurfaceTest.php` pin.

**Adding an approval or rejection rule.** Approval and rejection are configured
separately and snapshotted at submission. Cover the threshold case where one
rejection does not reject because the remaining approvers can still satisfy the
stage, and the veto case where it does.

**Touching the lifecycle.** Withdrawal, cancellation, system closure, return for
revision, and resubmission are deliberately distinct. Keep them distinct, and
assert the events and audit actions each one records.

**Touching the audit chain.** The hash payload is part of the format. Changing
it invalidates every recorded chain, so it needs an explicit note in the pull
request and a changelog entry, not just a passing suite.

**Fixing behavior.** Please include a test that fails before the change.

## Language

The package uses one canonical vocabulary: approval **stages**, **assignments**,
**decisions**, approvals **required**, stage **satisfaction**, and the
**requester** who submits. It does not use levels, steps, ballots, or
submitters, and `tests/Release/ReleaseLanguageTest.php` keeps them out.

Documentation and docblocks state behavior, constraints, and tradeoffs directly.
Avoid election, parliamentary, conversational, and anthropomorphic framing, and
do not describe the action history as immutable or tamper-proof — it is
application-enforced append-only and tamper-evident within the boundary
`SECURITY.md` documents.

## Package scope

The following are deliberate boundaries rather than gaps. Explain any proposed
change to them in the pull request:

- **No admin UI, approver inbox UI, roles, teams, org charts, or tenancy.** The
  package resolves approvers through your resolvers and authorizes through a
  policy you can replace.
- **No generic publication, activation, or visibility state machine.** Record
  approval approves an already-persisted row; attribute drafts are the built-in
  hold-until-approved mechanism.
- **No relationship drafts.** Drafts cover model attributes only.
- **No cryptographic signatures, HMAC, external anchoring, or write-once
  storage**, and no reading of storage disks to recompute attachment checksums.
- **No arbitrary BPMN branching, parallel stage graphs, or loops.** Stages are
  sequential.
- **No compatibility aliases for pre-1.0 names.** Removed names are removed.

## Commits and branches

Branch off `main` as `feat/…`, `fix/…`, `docs/…`, `refactor/…`, or `chore/…`,
and write [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`). Mark breaking changes with
`!`, which matters while the package is pre-1.0 and changing shape.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
