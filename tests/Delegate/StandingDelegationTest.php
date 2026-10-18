<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Events\DelegationEnded;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('routes the delegator\'s open assignments to the delegate as soon as the window opens', function (): void {
    $boss = user('Boss');
    $deputy = user('Deputy');
    $request = post()->submitForApproval(workflow([['approvers' => [$boss]]]), null, user());

    Event::fake([ApprovalDelegated::class]);
    Approval::grantDelegation($boss, $deputy, comment: 'Out until the 9th.');

    Event::assertDispatched(ApprovalDelegated::class, fn (ApprovalDelegated $event): bool => $event->delegate->is($deputy));
    expect(Approval::awaiting($deputy)->sole()->is($request))->toBeTrue()
        ->and(Approval::awaiting($boss)->exists())->toBeFalse()
        ->and(fn () => Approval::approve($request, $boss))->toThrow(UnauthorizedApproverException::class);

    Approval::approve($request, $deputy);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and(Approval::verifyTrail($request))->toBe([]);
});

it('routes a stage that becomes active while the window is open', function (): void {
    $first = user('First');
    $boss = user('Boss');
    $deputy = user('Deputy');
    $request = post()->submitForApproval(workflow([
        ['approvers' => [$first]],
        ['approvers' => [$boss]],
    ]), null, user());

    Approval::grantDelegation($boss, $deputy);
    Approval::approve($request, $first);

    $routed = $request->refresh()->currentStage()?->assignments()->sole();

    expect($routed?->delegatedTo?->is($deputy))->toBeTrue()
        ->and($routed?->approver?->is($boss))->toBeTrue();
});

it('hands undecided assignments back on revoke and leaves decided ones where they were decided', function (): void {
    $boss = user('Boss');
    $deputy = user('Deputy');
    $decided = post('Decided')->submitForApproval(workflow([['approvers' => [$boss, user('Other')], 'required_approvals' => 2]]), null, user());
    $open = post('Open')->submitForApproval(workflow([['approvers' => [$boss]]]), null, user());

    $delegation = Approval::grantDelegation($boss, $deputy);
    Approval::approve($decided, $deputy);

    Event::fake([DelegationEnded::class]);
    Approval::revokeDelegation($delegation);

    Event::assertDispatchedTimes(DelegationEnded::class, 1);
    expect(Approval::awaiting($boss)->sole()->is($open))->toBeTrue()
        ->and($decided->refresh()->currentStage()?->assignments()->where('approver_id', $boss->getKey())->sole()->delegatedTo?->is($deputy))->toBeTrue()
        ->and($open->actions()->where('action', ActionType::DelegationEnded->value)->exists())->toBeTrue()
        ->and(Approval::verifyTrail($open))->toBe([]);
});

it('opens and closes scheduled windows when the sweep runs', function (): void {
    $boss = user('Boss');
    $deputy = user('Deputy');
    $request = post()->submitForApproval(workflow([['approvers' => [$boss]]]), null, user());

    Approval::grantDelegation($boss, $deputy, Date::now()->addDay(), Date::now()->addDays(3));

    expect(Approval::awaiting($boss)->exists())->toBeTrue();

    $this->travel(2)->days();
    $this->artisan('approval:delegations')->expectsOutputToContain('Moved 1 assignment.')->assertSuccessful();

    expect(Approval::awaiting($deputy)->sole()->is($request))->toBeTrue();

    $this->travel(2)->days();
    $this->artisan('approval:delegations')->assertSuccessful();

    expect(Approval::awaiting($boss)->sole()->is($request))->toBeTrue()
        ->and(Approval::delegationsFrom($boss)->exists())->toBeFalse();
});

it('leaves an assignment with its approver when the delegate requested it or already holds one', function (): void {
    $boss = user('Boss');
    $deputy = user('Deputy');
    $requestedByDeputy = post('Requested')->submitForApproval(workflow([['approvers' => [$boss]]]), null, $deputy);
    $deputyAssigned = post('Assigned')->submitForApproval(workflow([['approvers' => [$boss, $deputy], 'required_approvals' => 2]]), null, user());

    Approval::grantDelegation($boss, $deputy);

    expect(Approval::awaiting($boss)->pluck('id')->sort()->values()->all())
        ->toBe([$requestedByDeputy->getKey(), $deputyAssigned->getKey()]);
});

it('leaves hand delegations alone', function (): void {
    $boss = user('Boss');
    $colleague = user('Colleague');
    $request = post()->submitForApproval(workflow([['approvers' => [$boss]]]), null, user());

    Approval::delegate($request, $boss, $colleague);
    $delegation = Approval::grantDelegation($boss, user('Deputy'));
    Approval::revokeDelegation($delegation);

    expect(Approval::awaiting($colleague)->sole()->is($request))->toBeTrue();
});

it('refuses delegations it cannot honor', function (Closure $grant, string $message): void {
    expect(fn () => $grant(user('Boss'), user('Deputy')))->toThrow(DelegationException::class, $message);
})->with([
    'to themselves' => [fn ($boss) => Approval::grantDelegation($boss, $boss), 'to themselves'],
    'ending before it starts' => [fn ($boss, $deputy) => Approval::grantDelegation($boss, $deputy, Date::now()->addDay(), Date::now()), 'end before it starts'],
    'overlapping another window' => [function ($boss, $deputy): void {
        Approval::grantDelegation($boss, $deputy, Date::now(), Date::now()->addDays(5));
        Approval::grantDelegation($boss, user('Other'), Date::now()->addDays(4));
    }, 'already has a delegation'],
    'revoked twice' => [function ($boss, $deputy): void {
        $delegation = Approval::grantDelegation($boss, $deputy);
        Approval::revokeDelegation($delegation);
        Approval::revokeDelegation($delegation);
    }, 'already been revoked'],
]);

it('keeps working after an upgrade whose delegations migration has not run yet', function (): void {
    // A 1.0 schema: the second migration rolled back, so only the first ran.
    (require __DIR__.'/../../database/migrations/create_approval_delegations_table.php.stub')->down();

    $first = user('First');
    $second = user('Second');
    $request = post()->submitForApproval(workflow([['approvers' => [$first]], ['approvers' => [$second]]]), null, user());

    Approval::approve($request, $first);
    Approval::reassign($request, $request->refresh()->currentStage()->assignments()->sole(), $first, user('Admin'));

    expect(Approval::awaiting($first)->sole()->is($request))->toBeTrue()
        ->and(fn () => Approval::grantDelegation($first, $second))->toThrow(DelegationException::class, 'delegations migration');

    $this->artisan('approval:delegations')->expectsOutputToContain('has not run')->assertSuccessful();
});
