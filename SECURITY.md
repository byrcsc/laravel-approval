# Security Policy

## Reporting a vulnerability

Please report privately, not in a public issue. Use GitHub's
[private vulnerability reporting](https://github.com/byrcsc/laravel-approval/security/advisories/new)
on this repository; it opens a channel visible only to the maintainers.

Include the package version, the Laravel and PHP versions, the database driver,
and enough detail to reproduce. If it helps, a failing test is the clearest
possible report.

You can expect an acknowledgement within a week. Because this is a
single-maintainer package, please do not expect a same-day response; if the
issue is being actively exploited, say so in the title.

## Supported versions

Security fixes are released for the latest package version and are not
backported, and neither are ordinary bug fixes. Only the current major receives
either; when a new major is released, the previous one stops receiving fixes.
Keep your dependency constraint current.

## What this package does and does not protect

This package decides who may approve what, and records what was decided. It is
an authorization and audit component, so the boundary matters.

It **does**:

- check every decision against the recorded assignments for the current stage,
  through the same checks the shipped `ApprovalRequestPolicy` uses, so an
  authorization question and the engine's own enforcement cannot disagree;
- snapshot the workflow, its stages, and the resolved assignments at submission,
  so changing a workflow or an organizational chart cannot retroactively alter
  who was allowed to decide on work already in flight;
- take row locks and run each request's decision in its own transaction, so
  concurrent decisions cannot double-count toward a threshold;
- re-check drafted attributes against the record immediately before applying
  them, so a value edited after submission produces a `conflicted` request
  rather than a silent write of stale data;
- refuse updates and deletes on `ApprovalAction` and `ApprovalAttachment`
  through the models, and chain each action's SHA-256 hash to the one before it,
  which makes an edit, deletion, reordering, or insertion detectable.

It **does not**:

- make the action history immutable or tamper-proof. The hash is unkeyed and
  stored beside the data it covers, so anyone able to write to the database
  directly can recompute a consistent chain. The guarantee holds against
  accidental and application-level modification, not against a database
  administrator. There are no cryptographic signatures, HMAC protection,
  external anchoring, or write-once storage;
- verify stored file bytes. `approval:verify` covers action content, chain
  linkage, the request's recorded chain head, attachment rows, and the
  attachment metadata recorded at attach time. It never reads a storage disk or
  recomputes a checksum, so a checksum row matching its recorded action is
  treated as valid;
- protect request state, draft attributes, workflow stage snapshots, or
  assignment rows. None of those are chained, and a clean verification result
  says nothing about them;
- intercept writes that do not dispatch Eloquent model events. Guard mode covers
  ordinary model writes; query-builder updates, upserts, quiet saves, and raw
  SQL bypass it, and the stale check before draft application is the only
  backstop on those paths;
- supply users, roles, teams, tenancy, or an admin UI. Approver resolution and
  any tenant scoping are the application's responsibility, and workflow slugs
  share one global namespace;
- gate persistence, visibility, or publication of your records. Record-level
  approval approves an already-persisted row. Attribute drafts are the built-in
  hold-until-approved mechanism; anything else is yours to enforce.

## Reporting something that is documented

The list above is the intended boundary, and the README's **Important behavior**
and **Audit guarantees** sections state it in full. A report that a documented
limitation exists is not a vulnerability, but a report that the package does not
actually hold a line it claims to hold very much is. When in doubt, report it.
