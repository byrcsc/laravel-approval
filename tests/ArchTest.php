<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Actions\RecordAction;
use ByRcsc\LaravelApproval\Commands\Command;
use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
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

arch('contracts are interfaces')
    ->expect('ByRcsc\LaravelApproval\Contracts')
    ->toBeInterfaces();

arch('actions are final and invokable')
    ->expect('ByRcsc\LaravelApproval\Actions')
    ->toBeFinal()
    ->toBeInvokable()
    // The audit trail is appended to by every other action, so it is the one
    // that takes named arguments rather than a single subject.
    ->ignoring(RecordAction::class);

arch('commands share the package base')
    ->expect('ByRcsc\LaravelApproval\Commands')
    ->toExtend(Command::class);

arch('only the base command is extendable')
    ->expect('ByRcsc\LaravelApproval\Commands')
    ->toBeFinal()
    ->ignoring(Command::class);

arch('data objects are final and readonly')
    ->expect('ByRcsc\LaravelApproval\Data')
    ->toBeFinal()
    ->toBeReadonly();

arch('escalation strategies implement the escalation contract')
    ->expect('ByRcsc\LaravelApproval\Escalation')
    ->toBeFinal()
    ->toImplement(EscalationStrategy::class);

arch('notifications share the package base')
    ->expect('ByRcsc\LaravelApproval\Notifications')
    ->toExtend(ApprovalNotification::class);

arch('only the base notification is extendable')
    ->expect('ByRcsc\LaravelApproval\Notifications')
    ->toBeFinal()
    ->ignoring(ApprovalNotification::class);

arch('listeners are final')
    ->expect('ByRcsc\LaravelApproval\Listeners')
    ->toBeFinal();

arch('resolvers implement the resolver contract')
    ->expect('ByRcsc\LaravelApproval\Resolvers')
    ->toBeFinal()
    ->toImplement(ApproverResolver::class);

arch('events carry a request and nothing else')
    ->expect('ByRcsc\LaravelApproval\Events')
    ->toExtend(ApprovalEvent::class)
    ->ignoring(ApprovalEvent::class);

// Audit entries can only be appended through an action, so stateful model code
// cannot quietly create a second write path.
arch('only actions write the audit trail')
    ->expect('ByRcsc\LaravelApproval\Models\ApprovalAction')
    ->toOnlyBeUsedIn([
        'ByRcsc\LaravelApproval\Actions',
        'ByRcsc\LaravelApproval\Models',
    ]);

// Models describe the schema. Transactions and dispatching belong to the
// actions, and this keeps it that way.
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
