<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('round-trips the workflow settings and flags', function () {
    $workflow = workflow(attributes: [
        'active' => false,
        'settings' => ['allow_self_approval' => true],
    ]);

    $workflow->refresh();

    expect($workflow->active)->toBeFalse()
        ->and($workflow->settings)->toBe(['allow_self_approval' => true]);
});

it('round-trips the level escalation settings', function () {
    $workflow = workflow([[
        'name' => 'Finance',
        'sla_minutes' => 120,
        'escalation_strategy' => EscalationAction::Escalate,
        'escalation_config' => ['resolver' => 'App\\Approvals\\HeadOfFinance'],
        'remind_every_minutes' => 30,
        'condition' => 'App\\Approvals\\LargeAmount',
        'condition_config' => ['amount' => 500_000],
    ]]);

    $level = $workflow->levels()->first();
    $level?->refresh();

    expect($level?->escalation_strategy)->toBe(EscalationAction::Escalate)
        ->and($level?->sla_minutes)->toBe(120)
        ->and($level?->escalation_config)->toBe(['resolver' => 'App\\Approvals\\HeadOfFinance'])
        ->and($level?->remind_every_minutes)->toBe(30)
        ->and($level?->condition_config)->toBe(['amount' => 500_000]);
});

it('round-trips a request state, draft, and timestamps', function () {
    $request = approvalRequest(attributes: [
        'state' => RequestState::Conflicted,
        'new_attributes' => ['title' => 'Revised title', 'published' => true],
        'original_attributes' => ['title' => 'A draft post', 'published' => false],
        'completed_at' => now(),
    ]);

    $request->refresh();

    expect($request->state)->toBe(RequestState::Conflicted)
        ->and($request->new_attributes)->toBe(['title' => 'Revised title', 'published' => true])
        ->and($request->original_attributes)->toBe(['title' => 'A draft post', 'published' => false])
        ->and($request->submitted_at)->toBeInstanceOf(Carbon::class)
        ->and($request->completed_at)->toBeInstanceOf(Carbon::class);
});

it('reads a state written to the database as a plain string', function () {
    $request = approvalRequest();

    ApprovalRequest::query()->whereKey($request->getKey())->toBase()->update(['state' => 'cancelled']);

    expect($request->fresh()?->state)->toBe(RequestState::Cancelled);
});

it('defaults a fresh request to pending at the first level', function () {
    $request = approvalRequest()->fresh();

    expect($request?->state)->toBe(RequestState::Pending)
        ->and($request?->current_level_sequence)->toBe(1);
});

it('round-trips a step state and its deadlines', function () {
    $request = approvalRequest();
    $deadline = now()->addHours(4);

    $step = $request->steps()->create([
        'sequence' => 1,
        'name' => 'Manager',
        'state' => StepState::Active,
        'deadline_at' => $deadline,
        'escalation_strategy' => EscalationAction::Notify,
    ]);

    $step->refresh();

    expect($step->state)->toBe(StepState::Active)
        ->and($step->escalation_strategy)->toBe(EscalationAction::Notify)
        ->and($step->deadline_at?->toDateTimeString())->toBe($deadline->toDateTimeString())
        ->and($step->escalated_at)->toBeNull();
});

it('defaults a fresh step to queued', function () {
    $request = approvalRequest();

    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager'])->fresh();

    expect($step?->state)->toBe(StepState::Queued);
});

it('round-trips the provenance of an approver slot', function () {
    $request = approvalRequest();
    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);
    $approver = user();

    $slot = $step->approverSlots()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'source' => ApproverSource::Escalated,
    ])->fresh();

    expect($slot?->source)->toBe(ApproverSource::Escalated);
});

it('defaults an approver slot to having been assigned', function () {
    $request = approvalRequest();
    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);
    $approver = user();

    $slot = $step->approverSlots()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ])->fresh();

    expect($slot?->source)->toBe(ApproverSource::Assigned);
});

it('round-trips an action type and its metadata', function () {
    $request = approvalRequest();

    $action = $request->actions()->create([
        'action' => ActionType::Rejected,
        'comment' => 'The figures do not add up.',
        'metadata' => ['quorum' => 2, 'votes' => 1],
        'hash' => str_repeat('0', 64),
    ])->fresh();

    expect($action?->action)->toBe(ActionType::Rejected)
        ->and($action?->comment)->toBe('The figures do not add up.')
        ->and(canonical($action?->metadata))->toBe(canonical(['quorum' => 2, 'votes' => 1]));
});

it('stamps an action with when it happened and nothing else', function () {
    $request = approvalRequest();

    $action = $request->actions()->create(['action' => ActionType::Submitted, 'hash' => str_repeat('0', 64)]);

    expect($action->created_at)->toBeInstanceOf(Carbon::class)
        ->and($action->getAttributes())->not->toHaveKey('updated_at');
});

it('generates a sortable identifier for every request', function () {
    $first = approvalRequest();
    $second = approvalRequest();

    expect($first->ulid)->toBeString()->toHaveLength(26)
        ->and($second->ulid)->not->toBe($first->ulid)
        ->and($second->ulid > $first->ulid)->toBeTrue();
});

it('keeps an identifier that was set explicitly', function () {
    $ulid = (string) Str::ulid();

    expect(approvalRequest(attributes: ['ulid' => $ulid])->ulid)->toBe($ulid);
});

it('refuses to let the audit trail be rewritten', function () {
    $action = approvalRequest()->actions()->create([
        'action' => ActionType::Approved,
        'comment' => 'Looks right to me.',
        'hash' => str_repeat('0', 64),
    ]);

    expect(fn () => $action->update(['comment' => 'Actually, no.']))
        ->toThrow(LogicException::class, 'immutable audit trail');

    expect($action->fresh()?->comment)->toBe('Looks right to me.');
});

it('refuses to let the audit trail be erased', function () {
    $action = approvalRequest()->actions()->create(['action' => ActionType::Approved, 'hash' => str_repeat('0', 64)]);

    expect(fn () => $action->delete())
        ->toThrow(LogicException::class, 'immutable audit trail');

    expect(ApprovalAction::query()->whereKey($action->getKey())->exists())->toBeTrue();
});
