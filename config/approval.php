<?php

declare(strict_types=1);

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
    | Self-approval
    |--------------------------------------------------------------------------
    |
    | Whether a submitter may approve their own request. Off by default: an
    | approval chain someone can walk themselves is not a control. A workflow's
    | `settings` column overrides this per workflow.
    |
    */

    'allow_self_approval' => (bool) env('APPROVAL_ALLOW_SELF_APPROVAL', false),

];
