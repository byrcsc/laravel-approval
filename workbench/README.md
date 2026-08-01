# Workbench demo app

A real, bootable Laravel application that installs `byrcsc/laravel-approval` the way an
application would. It exists so the package can be driven by hand — every README example is
executed here before it is published, and it is the only place where the parts that need a
running application meet one: scheduled escalation, notification delivery, policy gates, and
the app-owned listeners that turn an approval into a published article.

It is a development tool. It is excluded from the Composer dist archive via `.gitattributes`
and is not covered by CI, so it may drift.

## Setup

```bash
composer build
```

That runs `vendor/bin/testbench workbench:build`, which creates the SQLite database, publishes
the package migration exactly as `vendor:publish --tag=approval-migrations` would in a real
app, migrates, seeds an org chart and some records, and then runs `approval:sync` to write the
two version-controlled purchase-order workflows.

Testbench runs the seeder off the `DatabaseRefreshed` event, so `migrate:fresh` seeds too. Bare
`migrate --seed` does not — it looks for `Database\Seeders\DatabaseSeeder`, and the seeder here
lives under the Workbench namespace. Name it if you want that route:

```bash
vendor/bin/testbench migrate --seed --seeder="Workbench\Database\Seeders\DatabaseSeeder"
```

## The cast

Everyone's password is `password`, and the demo commands accept the local part of an address —
`--as=grace` rather than `--as=grace@example.test`.

| Team | Lead | Members |
|------|------|---------|
| `procurement` | Ada Lovelace | Ada, Linus Torvalds |
| `finance` | Grace Hopper | Grace, Alan Turing, Katherine Johnson |
| `executive` | Edsger Dijkstra | Edsger, Barbara Liskov |
| `editorial` | Donald Knuth | Donald, Radia Perlman |
| `legal` | Margaret Hamilton | Margaret |
| `audit` | — | Karen Sparck Jones |

Teams are what make the demo need approver resolvers at all: naming a team as an approver would
assign the *team record* — one identity that can never sign in and decide — so
`TeamLeadResolver` and `TeamMembersResolver` are what turn the org chart into people.

## The demo loop

The build leaves two articles already submitted, because `Article` submits itself on creation.
Start there:

```bash
vendor/bin/testbench demo:inbox donald         # two articles waiting on the editors
vendor/bin/testbench demo:show <REFERENCE> --as=donald
vendor/bin/testbench demo:decide <REFERENCE> --as=donald
```

Purchase orders are seeded unsubmitted, because which workflow one lands in depends on the
amount being **proposed**, not the amount on the record:

```bash
vendor/bin/testbench demo:submit PO-1001 --as=linus --amount=52000 --vendor="Northwind GmbH"
```

`demo:submit` prints `Approval::workflowsFor()` before it submits anything — the query a form
builds its choice from — then the stages and assignments as they were frozen onto the request.

Three amounts, three shapes. Amounts are in minor units:

| `--amount` | Workflow | Stages |
|-----------|----------|--------|
| `52000` | `purchase-order-standard` | Team lead → any one of finance |
| `1200000` | `purchase-order-executive` | Team lead → two of finance → *board sign-off skipped* |
| `6400000` | `purchase-order-executive` | Team lead → two of finance → unanimous board |

The middle row is the point of stage conditions: the workflow-level condition sends anything at
or above 10,000 to the executive workflow, and the stage-level condition on board sign-off only
applies at or above 50,000. A stage whose condition fails is written onto the request as
`skipped` rather than left out, so the timeline still shows it and records that it did not
apply.

## Things worth trying

### Guard mode, and the way past it

`PurchaseOrder` runs in guard mode. Submit a draft, then try to write one of the drafted
attributes:

```bash
vendor/bin/testbench demo:edit PO-1001 --amount=99999          # refused
vendor/bin/testbench demo:edit PO-1001 --status=submitted      # allowed: never drafted
vendor/bin/testbench demo:edit PO-1001 --amount=45500 --bypass --as=ada
vendor/bin/testbench demo:edit PO-1001 --amount=45500 --raw    # sails past: no model event
```

`--bypass` goes through `withoutApprovalGuard()`, and the bypass lands on the request's audit
trail. `--raw` goes through the query builder, which dispatches no model events — guard mode
never sees it, which is exactly what the stale check before draft application exists to catch.

### Conflicts

Bypass or raw-write a drafted attribute and then approve the last stage:

```bash
vendor/bin/testbench demo:decide <REFERENCE> --as=ada
vendor/bin/testbench demo:decide <REFERENCE> --as=grace        # -> conflicted
vendor/bin/testbench demo:resolve <REFERENCE> --as=edsger      # reapply
vendor/bin/testbench demo:resolve <REFERENCE> --as=edsger --discard
```

The request approved every stage and then found the record had changed underneath it. Nothing
is applied, no completion timestamp is written, and no approved notification is sent. There are
two resolutions and no merge.

### Deadlines and escalation

Deadlines are recorded at submission, and nothing acts on them until the sweep runs. Nothing in
the demo waits an hour for that:

```bash
vendor/bin/testbench demo:overdue <REFERENCE> --minutes=5      # demo aid, backdates the deadline
vendor/bin/testbench approval:escalate
```

`demo:overdue` writes directly to the stage's deadline columns. No application should ever do
that; it is here because SLA behaviour is otherwise the one part of the package that cannot be
driven by hand.

What happens next depends on the stage:

| Stage | Strategy | Result |
|-------|----------|--------|
| `purchase-order-standard` → Team lead | `escalate` | Finance is added *alongside* the lead |
| `purchase-order-executive` → Team lead | none, so `record_only` | A line in `storage/logs/laravel.log` |
| `article-review` → Legal hold | `auto_approve` | The hold lapses and the stage completes |

The middle row is the demo's replacement strategy at work: `LogOverdueStrategy` is mapped over
the shipped `record_only`, which is deliberately empty. Replacing a strategy is a config entry,
not a fork — and note that the demo therefore behaves differently from a stock install, where
`record_only` records the overdue state and does nothing further.

In a real application this is a scheduled command:

```php
Schedule::command('approval:escalate')->everyFifteenMinutes();
```

### Delegation and reassignment

```bash
vendor/bin/testbench demo:delegate <REFERENCE> --from=ada --to=karen
vendor/bin/testbench demo:delegate <REFERENCE> --from=alan --to=barbara --reassign --as=edsger
```

The first is the approver acting for themselves; the assignment moves, and appears in exactly
one inbox afterwards. The second is administrative, needs an attributable operator, and works
on any undecided stage rather than only the active one.

Delegating to somebody who already holds an assignment on the stage is refused —
`delegate_already_assigned` — which is worth seeing, because it is the reason the demo's two
editors cannot cover for each other.

### Evidence and the audit trail

```bash
vendor/bin/testbench demo:attach <REFERENCE> --as=radia --name=legal-signoff.txt
vendor/bin/testbench approval:verify <REFERENCE>
```

The application writes the file and computes the digest; the package records the reference and
writes the checksum into an audit line, which puts the file's *content* under the hash chain.
The verifier never opens a disk, so a clean result proves the row matches what was recorded and
does not prove the bytes are still those bytes.

### Returning, revising, and bulk decisions

```bash
vendor/bin/testbench demo:decide <REFERENCE> --as=donald --verdict=return --comment="Too long."
vendor/bin/testbench demo:submit --revise=<REFERENCE> --as=linus --record
vendor/bin/testbench demo:decide --as=grace --inbox
```

Revising creates a new linked request from a fresh workflow snapshot; the original keeps its own
history and is never reopened. Bulk decisions isolate failures — one request that cannot be
decided does not roll back the rest.

### Operational commands

```bash
vendor/bin/testbench approval:status               # a count per state, then what needs attention
vendor/bin/testbench approval:doctor               # stages whose living holders cannot finish
vendor/bin/testbench approval:sync --dry-run       # preview the config-defined workflows
vendor/bin/testbench approval:resync <REFERENCE> --operator=6
```

`approval:resync` wants a resolvable identity, not a name: a key of `approval.user_model`, or
`type:id` for anything else — `--operator=user:6` works here because the demo enforces a morph
map. To see `approval:doctor` find something, delete an approver out from under a pending
request.

### Notifications

Delivery is opt-in and off by default; the demo turns it on with `mail` and `database` channels.
Mail goes to `vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log` because
`workbench/.env` sets `MAIL_MAILER=log`. Database notifications show up in `demo:inbox`.

`ApprovalApproved` is mapped to the demo's own notification, which tells the finance team as
well as the requester. In this package a notification declares its own recipients, so one map
entry replaces the message and its audience together.

## Resetting

```bash
composer clear   # purges the skeleton and deletes the demo database
composer build   # rebuild from scratch
```

A record can only have one unresolved request at a time, so the seeded purchase orders run out
once all three are in flight. Resolve one — approve, reject, withdraw, or cancel it — and it can
be submitted again; or rebuild.

## Layout

| Path | What it is |
|------|------------|
| `.env` | The demo app's environment. Testbench copies it into the skeleton, where the package test suite also boots — read the comments in it before changing a value |
| `app/Providers/WorkbenchServiceProvider.php` | Stands in for an app's `config/*.php` edits: every integration seam the package offers, in one file, with a table at the top saying which is which |
| `app/Approval/Resolvers/` | Turning the org chart into approvers |
| `app/Approval/Conditions/` | Which workflow runs, and which of its stages apply |
| `app/Approval/Strategies/` | A replacement for a shipped escalation strategy |
| `app/Policies/` | The app's rules on top of the package's, by extension rather than replacement |
| `app/Notifications/` | A replacement message with its own audience |
| `app/Console/` | The demo's `demo:*` commands — the interface the package deliberately does not ship |
| `app/Models/` | `PurchaseOrder` (approved as an edit), `Article` (approved as a record), and the org chart |
| `database/migrations/` | The host app's own schema. `users`, `cache` and `jobs` come from Testbench's default migrations; the approval tables are published by `composer build` |
| `storage/app/evidence/` | Where `demo:attach` writes files (gitignored) |

## Two workflows, two ways of writing one

Both paths ship a workflow, so neither goes untried.

`article-review` is built by the seeder out of ordinary Eloquent models, the way the package
README's quick start does and the way an administration screen would. It uses **concrete
approver rows** — each naming one exact actor — and one `StaticUsersResolver`.

The two purchase-order workflows are **version-controlled definitions** in
`WorkbenchServiceProvider`, written by `approval:sync`. Neither names a concrete approver, on
purpose: a definition in version control that names user 4 means something different in every
environment, so both reach people through resolvers. `approval:sync` applies additive and
supported changes and refuses to remove or reorder an existing stage or approver, so a
definition file cannot quietly rewrite a workflow that requests are already running under.

## What the demo app owns, and what the package owns

Worth stating, because the line is the package's main design decision.

The package controls approval state and history. It does not control a model's lifecycle.
Approving an article's request changes the request; the article stays unpublished until
`WorkbenchServiceProvider`'s listener on `ApprovalApproved` publishes it. A purchase order is
the other case — its draft is applied by the package, because the draft is what was approved —
so all the app does there is move its own `status` column, which `approvableAttributes()` keeps
out of reach of any draft.

Everything else the demo supplies is the same division: the users, the org chart, the policy
above the package's, the wording of a replaced notification, and every screen. The package
ships the engine and the queries an interface is built from.
