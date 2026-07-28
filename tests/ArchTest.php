<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use Illuminate\Database\Eloquent\Model;

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('ByRcsc\LaravelApproval')
    ->toUseStrictTypes();

arch('enums are backed by strings')
    ->expect('ByRcsc\LaravelApproval\Enums')
    ->toBeStringBackedEnums();

arch('every exception is catchable as an ApprovalException')
    ->expect('ByRcsc\LaravelApproval\Exceptions')
    ->toExtend(ApprovalException::class);

arch('only the base exception is extendable')
    ->expect('ByRcsc\LaravelApproval\Exceptions')
    ->toBeFinal()
    ->ignoring(ApprovalException::class);

arch('models are final Eloquent models')
    ->expect('ByRcsc\LaravelApproval\Models')
    ->toExtend(Model::class)
    ->toBeFinal();

arch('the facade is final')
    ->expect('ByRcsc\LaravelApproval\Facades')
    ->toBeFinal();

arch('support helpers are final')
    ->expect('ByRcsc\LaravelApproval\Support')
    ->toBeFinal();

arch('support helpers stay below the models')
    ->expect('ByRcsc\LaravelApproval\Support')
    ->not->toUse('ByRcsc\LaravelApproval\Models');

// Models describe the schema. Transactions and dispatching belong to the
// actions that arrive in later phases, and this keeps it that way.
arch('models stay out of the transaction business')
    ->expect('ByRcsc\LaravelApproval\Models')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Event',
        'Illuminate\Support\Facades\Notification',
    ]);

arch('enums carry no dependencies')
    ->expect('ByRcsc\LaravelApproval\Enums')
    ->not->toUse([
        'ByRcsc\LaravelApproval\Models',
        'ByRcsc\LaravelApproval\Support',
        'Illuminate\Support\Facades\DB',
    ]);
