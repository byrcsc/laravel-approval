<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApproversResynced;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Notifications\ApprovalAssignedNotification;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

it('adds the approvers the workflow now names', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    // The department gets a second head while the request is in flight.
    $deputy = user('Deputy');
    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    $changes = Approval::resync($request, user('Operator'));

    $assignments = $request->stages()->sole()->assignments()->get();

    expect($assignments)->toHaveCount(2)
        ->and($changes[1]['added'])->toBe([$deputy->getMorphClass().':'.$deputy->getKey()])
        ->and($changes[1]['removed'])->toBe([])
        ->and($assignments->last()?->source)->toBe(ApproverSource::Resynced);
});

it('leaves stale approvers in place unless asked to prune', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    $successor = user('Successor');
    HeadOfDepartmentResolver::$heads = [$successor];

    Approval::resync($request, user('Operator'));

    expect($request->stages()->sole()->assignments()->count())->toBe(2);
});

it('drops stale approvers when pruning', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    $successor = user('Successor');
    HeadOfDepartmentResolver::$heads = [$successor];

    $changes = Approval::resync($request, user('Operator'), prune: true);

    expect($request->stages()->sole()->assignments()->sole()->approver?->getKey())->toBe($successor->getKey())
        ->and($changes[1]['removed'])->toBe([$head->getMorphClass().':'.$head->getKey()]);
});

it('never prunes an assignment that has decided', function (): void {
    $one = user('One');
    HeadOfDepartmentResolver::$heads = [$one, user('Two')];

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    Approval::approve($request, $one);

    HeadOfDepartmentResolver::$heads = [user('Replacement A'), user('Replacement B')];

    Approval::resync($request, user('Operator'), prune: true);

    $assignments = $request->stages()->sole()->assignments()->get();

    expect($assignments->pluck('approver_id')->all())->toContain($one->getKey());
});

it('never prunes an assignment that has been delegated', function (): void {
    $head = user('Head');
    $delegate = user('Delegate');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    Approval::delegate($request, $head, $delegate);

    HeadOfDepartmentResolver::$heads = [user('Successor')];

    Approval::resync($request, user('Operator'), prune: true);

    expect($request->stages()->sole()->assignments()->count())->toBe(2);
});

it('does not add somebody who already holds a delegated assignment', function (): void {
    $one = user('One');
    $two = user('Two');
    $delegate = user('Delegate');
    HeadOfDepartmentResolver::$heads = [$one, $two];

    $workflow = workflow([[
        'required_approvals' => 2,
        'approvers' => [['resolver' => HeadOfDepartmentResolver::class]],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));
    Approval::delegate($request, $one, $delegate);

    HeadOfDepartmentResolver::$heads = [$delegate, $two];
    Approval::resync($request, user('Operator'));
    Approval::approve($request, $delegate);

    expect($request->stages()->sole()->assignments()->count())->toBe(2)
        ->and($request->refresh()->state)->toBe(RequestState::Pending);
});

it('resyncs stages the request has not reached yet', function (): void {
    $first = user('First');
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    $deputy = user('Deputy');
    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    $changes = Approval::resync($request, user('Operator'));

    expect(array_keys($changes))->toBe([2])
        ->and($request->stages()->get()[1]->assignments()->count())->toBe(2);
});

it('rolls back rather than stranding a stage below its required approvals', function (): void {
    $one = user('One');
    $two = user('Two');
    HeadOfDepartmentResolver::$heads = [$one, $two];

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$one];

    expect(fn () => Approval::resync($request, user('Operator'), prune: true))
        ->toThrow(InvalidWorkflowException::class, 'approval rule requires 2 approvals but only 1 assignments were resolved');

    expect($request->stages()->sole()->assignments()->count())->toBe(2);
});

it('carries an all-assignments stage when pruning makes its existing decisions sufficient', function (): void {
    $one = user('One');
    $two = user('Two');
    HeadOfDepartmentResolver::$heads = [$one, $two];

    $workflow = workflow([[
        'required_approvals' => null,
        'approvers' => [['resolver' => HeadOfDepartmentResolver::class]],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));
    Approval::approve($request, $one);

    HeadOfDepartmentResolver::$heads = [$one];
    Approval::resync($request, user('Operator'), prune: true);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('says nothing changed when nothing did', function (): void {
    Event::fake([ApproversResynced::class]);

    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    expect(Approval::resync($request, user('Operator')))->toBe([])
        ->and($request->actions()->where('action', 'resynced')->count())->toBe(0);

    Event::assertNotDispatched(ApproversResynced::class);
});

it('records and announces what it changed', function (): void {
    Event::fake([ApproversResynced::class]);

    $head = user('Head');
    $admin = user('Admin');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];

    Approval::resync($request, $admin);

    $action = $request->actions()->where('action', 'resynced')->sole();

    expect($action->actor?->getKey())->toBe($admin->getKey())
        ->and($action->getAttributeValue('metadata')['pruned'] ?? null)->toBeFalse();

    Event::assertDispatched(ApproversResynced::class, fn (ApproversResynced $e): bool => $e->actor?->is($admin) === true);
});

it('refuses to resync a closed request', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver);

    expect(fn () => Approval::resync($request, user('Operator')))
        ->toThrow(AlreadyDecidedException::class, 'cannot be decided again');
});

it('resyncs through the artisan command and attributes the operator', function (): void {
    $head = user('Head');
    $operator = user('Operator');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];

    $this->artisan('approval:resync', [
        'request' => $request->getAttributeValue('ulid'),
        '--operator' => $operator->getMorphClass().':'.$operator->getKey(),
    ])->assertSuccessful();

    $resynced = $request->actions()->where('action', 'resynced')->sole();

    expect($request->stages()->sole()->assignments()->count())->toBe(2)
        ->and($resynced->actor?->is($operator))->toBeTrue();
});

it('refuses to resync without an attributable operator', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];

    $this->artisan('approval:resync', ['request' => $request->getAttributeValue('ulid')])
        ->expectsOutputToContain('An attributable operator is required.')
        ->assertFailed();

    expect($request->stages()->sole()->assignments()->count())->toBe(1);
});

it('reports an unresolvable operator rather than guessing', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    $this->artisan('approval:resync', [
        'request' => $request->getAttributeValue('ulid'),
        '--operator' => '999999',
    ])->assertFailed();
});

it('reports an unknown request rather than failing silently', function (): void {
    $this->artisan('approval:resync', ['request' => 'nope', '--operator' => (string) user('Operator')->getKey()])
        ->assertFailed();
});

it('leaves the request pending throughout', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];
    Approval::resync($request, user('Operator'));

    expect($request->refresh()->state)->toBe(RequestState::Pending);
});

it('notifies the added approver when the resync runs through the console', function (): void {
    test()->rebootWith(['approval.notifications.enabled' => true]);
    Notification::fake();

    $head = user('Head');
    $deputy = user('Deputy');
    $operator = user('Operator');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
    $request = post()->submitForApproval($workflow, null, user());

    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    $this->artisan('approval:resync', [
        'request' => $request->getAttributeValue('ulid'),
        '--operator' => $operator->getMorphClass().':'.$operator->getKey(),
    ])->assertSuccessful();

    Notification::assertSentTo($deputy, ApprovalAssignedNotification::class);
    Notification::assertNotSentTo($head, ApprovalAssignedNotification::class);
});
