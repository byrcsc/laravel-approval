<?php

declare(strict_types=1);
use ByRcsc\LaravelApproval\Escalation\AutoDecideStrategy;
use ByRcsc\LaravelApproval\Escalation\EscalateStrategy;
use ByRcsc\LaravelApproval\Escalation\NotifyStrategy;
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
        'levels' => 'approval_workflow_levels',
        'level_approvers' => 'approval_workflow_level_approvers',
        'requests' => 'approval_requests',
        'steps' => 'approval_request_steps',
        'step_approvers' => 'approval_request_step_approvers',
        'actions' => 'approval_actions',
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
    | Only five of the events are mapped. The rest are there to be listened to;
    | not every state change is worth an email.
    |
    */

    'notifications' => [

        'enabled' => (bool) env('APPROVAL_NOTIFICATIONS_ENABLED', false),

        'channels' => ['mail'],

        'map' => [
            Events\ApprovalSubmitted::class => Notifications\ApprovalRequestedNotification::class,
            Events\LevelCompleted::class => Notifications\ApprovalRequestedNotification::class,
            Events\ApprovalCompleted::class => Notifications\ApprovalApprovedNotification::class,
            Events\ApprovalRejected::class => Notifications\ApprovalRejectedNotification::class,
            Events\StepOverdue::class => Notifications\ApprovalOverdueNotification::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation
    |--------------------------------------------------------------------------
    |
    | What happens to a level that passes its deadline. Each level picks one of
    | these by name in its `escalation_strategy` column; this map says which
    | class does the work, and swapping one for your own is the seam.
    |
    | Nothing escalates unless `approval:escalate` is scheduled. Deadlines are
    | recorded either way - the command is what notices them:
    |
    |     Schedule::command('approval:escalate')->everyFifteenMinutes();
    |
    | `notify` does nothing beyond firing StepOverdue, which is the honest
    | default: a late approval is late, and deciding it automatically is a
    | policy an organization chooses rather than one this package assumes.
    |
    */

    'escalation' => [

        'strategies' => [
            'notify' => NotifyStrategy::class,
            'escalate' => EscalateStrategy::class,
            'auto_approve' => AutoDecideStrategy::class,
            'auto_reject' => AutoDecideStrategy::class,
        ],

    ],

];
