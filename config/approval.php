<?php

declare(strict_types=1);
use ByRcsc\LaravelApproval\Escalation\AutoDecideStrategy;
use ByRcsc\LaravelApproval\Escalation\EscalateStrategy;
use ByRcsc\LaravelApproval\Escalation\RecordOnlyStrategy;
use ByRcsc\LaravelApproval\Events;
use ByRcsc\LaravelApproval\Notifications;

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Rename any of these to fit your schema conventions - the models read the
    | same values, so nothing else has to change. Do it before you migrate;
    | renaming a table that already holds history is your migration to write.
    |
    | Every key must be present. A missing one fails at boot rather than the
    | first time somebody submits something for approval.
    |
    */

    'table_names' => [
        'workflows' => 'approval_workflows',
        'workflow_stages' => 'approval_workflow_stages',
        'workflow_stage_approvers' => 'approval_workflow_stage_approvers',
        'requests' => 'approval_requests',
        'request_stages' => 'approval_request_stages',
        'assignments' => 'approval_request_assignments',
        'actions' => 'approval_actions',
        'attachments' => 'approval_attachments',
    ],

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The default the package assumes when nothing more specific is given. The
    | approver columns are polymorphic, so a workflow can point at any model -
    | a team, a position, a service account.
    |
    */

    'user_model' => env('APPROVAL_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Polymorphic identity key type
    |--------------------------------------------------------------------------
    |
    | Set this before running the package migration when the models that may
    | submit, approve, delegate, act, reassign, or upload use UUID, ULID, or
    | other string keys. Valid values: int, uuid, ulid, string.
    |
    */

    'user_key_type' => env('APPROVAL_USER_KEY_TYPE', 'int'),

    /*
    |--------------------------------------------------------------------------
    | Default workflow per model
    |--------------------------------------------------------------------------
    |
    | The last place `submitForApproval()` looks. It checks its own argument
    | first, then the model's `approvalWorkflow()` method, then this - so this
    | is for wiring up a model you do not own, or for keeping the mapping in one
    | file when an operations team edits it.
    |
    | Keyed by model class, valued by workflow slug.
    |
    */

    'workflows' => [
        // App\Models\PurchaseOrder::class => 'purchase-order',
    ],

    /*
    |--------------------------------------------------------------------------
    | Version-controlled workflow definitions
    |--------------------------------------------------------------------------
    |
    | `php artisan approval:sync --dry-run` previews these definitions and
    | `php artisan approval:sync` applies additive and supported configuration
    | changes. Removing or reordering existing stages or approvers is refused.
    |
    */

    'definitions' => [
        // 'purchase-order' => ['name' => 'Purchase order', 'stages' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model modes
    |--------------------------------------------------------------------------
    |
    | Explicit is the default. Guard mode rejects Eloquent `save()` and
    | `update()` calls that touch attributes in a pending draft. Query-builder
    | updates, upserts, and raw SQL do not dispatch model events and therefore
    | remain outside this boundary; the final stale check still catches them.
    |
    */

    'models' => [
        // App\Models\PurchaseOrder::class => ['mode' => 'guard'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-approval
    |--------------------------------------------------------------------------
    |
    | Whether a submitter may approve their own request. Off by default: an
    | approval chain someone can walk themselves is not a control. A workflow's
    | `settings` column overrides this per workflow.
    |
    */

    'allow_self_approval' => (bool) env('APPROVAL_ALLOW_SELF_APPROVAL', false),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Off by default. A package that starts mailing your users the moment it is
    | installed is a package you uninstall - and your application already has
    | opinions about who hears what.
    |
    | Turn `enabled` on and you get the map below. Set any entry to null to
    | silence that event, or point it at your own class to change both the
    | message and who receives it: a notification here declares its own
    | recipients, so the audience is not stranded in a listener you would have
    | to fork. Extend ByRcsc\LaravelApproval\Notifications\ApprovalNotification.
    |
    | Not every event is mapped. The rest are there to be listened to; not
    | every state change is worth an email.
    |
    */

    'notifications' => [

        'enabled' => (bool) env('APPROVAL_NOTIFICATIONS_ENABLED', false),

        'channels' => ['mail'],

        'map' => [
            Events\ApprovalSubmitted::class => Notifications\ApprovalRequestedNotification::class,
            Events\StageCompleted::class => Notifications\ApprovalRequestedNotification::class,
            Events\ApprovalApproved::class => Notifications\ApprovalApprovedNotification::class,
            Events\DraftConflicted::class => Notifications\ApprovalConflictNotification::class,
            Events\ApprovalRejected::class => Notifications\ApprovalRejectedNotification::class,
            Events\ApprovalReturned::class => Notifications\ApprovalReturnedNotification::class,
            Events\ApprovalDelegated::class => Notifications\ApprovalAssignedNotification::class,
            Events\ApproverReassigned::class => Notifications\ApprovalAssignedNotification::class,
            Events\ApproversResynced::class => Notifications\ApprovalAssignedNotification::class,
            Events\ApproversEscalated::class => Notifications\ApprovalAssignedNotification::class,
            Events\StageOverdue::class => Notifications\ApprovalOverdueNotification::class,
            Events\StageOverdueReminder::class => Notifications\ApprovalOverdueNotification::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    |
    | What happens to a stage that passes its deadline. Each stage picks one of
    | these by name in its `escalation_strategy` column; this map says which
    | class does the work, and swapping one for your own is the seam.
    |
    | Nothing escalates unless `approval:escalate` is scheduled. Deadlines are
    | recorded either way - the command is what notices them:
    |
    |     Schedule::command('approval:escalate')->everyFifteenMinutes();
    |
    | `record_only` records the overdue state and fires StageOverdue, nothing
    | more - delivery is not promised unless notifications are enabled. It is
    | the default: a late approval is late, and deciding it automatically is a
    | policy an organization chooses rather than one this package assumes.
    |
    */

    'escalation' => [

        'strategies' => [
            'record_only' => RecordOnlyStrategy::class,
            'escalate' => EscalateStrategy::class,
            'auto_approve' => AutoDecideStrategy::class,
            'auto_reject' => AutoDecideStrategy::class,
        ],

    ],

];
