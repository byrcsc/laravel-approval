<?php

declare(strict_types=1);

return [

    'requested' => [
        'subject' => 'Approval needed: :subject',
        'body' => 'You have been asked to approve :subject.',
    ],

    'approved' => [
        'subject' => 'Approved: :subject',
        'body' => ':subject has been approved.',
    ],

    'rejected' => [
        'subject' => 'Rejected: :subject',
        'body' => ':subject was not approved.',
    ],

    'returned' => [
        'subject' => 'Returned for revision: :subject',
        'body' => ':subject was returned to you for revision.',
    ],

    'assigned' => [
        'subject' => 'Approval assigned to you: :subject',
        'body' => 'An approval assignment for :subject is now yours.',
    ],

    'overdue' => [
        'subject' => 'Overdue: :subject',
        'body' => 'An approval waiting on you has passed its deadline: :subject.',
    ],

    'conflicted' => [
        'subject' => 'Approval conflict: :subject',
        'body' => ':subject could not be updated because the record changed while approval was pending.',
        'unresolved' => 'The approval request remains unresolved.',
    ],

    'reason' => 'Reason: :reason',

    'reference' => 'Reference: :reference.',

    'deleted_record' => 'a deleted record',

];
