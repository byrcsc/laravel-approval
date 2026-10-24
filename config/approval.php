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
    | Rename any of these to fit your schema conventions; the models read the
    | same values, so nothing else has to change. Set them before migrating.
    | Renaming a table that already holds history requires your own migration.
    |
    | A missing key uses the default name shown here, so a config published
    | before a table was added keeps working.
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
        'delegations' => 'approval_delegations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model classes
    |--------------------------------------------------------------------------
    |
    | Replace any package model with your own subclass, keyed like the table
    | names above. Each class must extend the package model it replaces. The
    | package uses the configured class for every query and relation.
    |
    */

    'model_classes' => [
        // 'requests' => App\Models\ApprovalRequest::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The default model used when nothing more specific is given, for example by
    | the `--operator` option on recovery commands.
    |
    | Approver columns are polymorphic, so an assignment can name any model. Note
    | that an assignment always names one exact identity: assigning a Team or
    | Position model assigns that record itself and does not expand it into its
    | members. Use an approver resolver to assign the members.
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
    | Where `submitForApproval()` looks third. It checks its own argument first,
    | then the model's `approvalWorkflow()` method, then this map, and finally
    | the workflows whose `approvable_type` names the model. Use it for models
    | you do not own, or to keep the mapping in one file.
    |
    | Keyed by model class. One slug names one workflow; a list of slugs is a
    | preference order, and the first whose condition applies is the one that
    | runs.
    |
    */

    'workflows' => [
        // App\Models\PurchaseOrder::class => 'purchase-order',
        // App\Models\Invoice::class => ['invoice-executive', 'invoice-standard'],
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
    | Whether a requester may approve their own request. Disabled by default; a
    | workflow's `settings` column overrides this per workflow.
    |
    */

    'allow_self_approval' => (bool) env('APPROVAL_ALLOW_SELF_APPROVAL', false),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Delivery is opt-in and disabled by default, so installing the package
    | cannot send external messages.
    |
    | Setting `enabled` to true activates the map below. Set an entry to null to
    | silence that event, or point it at your own class to replace both the
    | message and its recipients: a notification declares its own recipients, so
    | the audience is not held in a listener you would have to replace. Extend
    | ByRcsc\LaravelApproval\Notifications\ApprovalNotification.
    |
    | Not every event is mapped. The mapped events are the ones that leave
    | someone waiting on an action; the rest are available to listen to.
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
            Events\DelegationEnded::class => Notifications\ApprovalAssignedNotification::class,
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
    | What happens to a stage that passes its deadline. Each stage names one of
    | these in its `escalation_strategy` column; this map says which class runs
    | it, and replacing an entry is the seam for a custom strategy.
    |
    | Nothing escalates unless `approval:escalate` runs. Deadlines are recorded
    | either way; the command is what acts on them:
    |
    |     Schedule::command('approval:escalate')->everyFifteenMinutes();
    |
    | `record_only` is the default: it records the overdue state and emits
    | StageOverdue, and nothing else. It sends nothing on its own, and a message
    | reaches an approver only when notifications are enabled. Deciding an
    | overdue stage automatically is a policy each organization chooses.
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
