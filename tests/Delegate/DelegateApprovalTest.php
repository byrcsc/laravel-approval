<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Event;

it('hands the seat to the delegate without erasing who was asked', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    $seat = Approval::delegate($request, $approver, $delegate, 'On leave until the 9th.');

    expect($seat->getAttributeValue('approver_id'))->toBe($approver->getKey())
        ->and($seat->delegatedTo?->getKey())->toBe($delegate->getKey())
        ->and($seat->source)->toBe(ApproverSource::Delegated);
});

it('lets the delegate carry the level', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $approver, $delegate);
    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('takes the vote away from the original approver', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $approver, $delegate);

    expect(fn () => Approval::approve($request, $approver))
        ->toThrow(UnauthorizedApproverException::class, 'delegated their approval');
});

it('counts a delegate vote as exactly one seat', function (): void {
    $one = user('One');
    $two = user('Two');
    $delegate = user('Delegate');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $one, $delegate);
    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Pending);

    Approval::approve($request, $two);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('lets the delegate reject on the seat too', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $approver, $delegate);
    Approval::reject($request, $delegate, 'Not while I am covering.');

    expect($request->refresh()->state)->toBe(RequestState::Rejected);
});

it('records the handover and announces it', function (): void {
    Event::fake([ApprovalDelegated::class]);

    $approver = user('Approver');
    $delegate = user('Delegate');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $approver, $delegate, 'Away this week.');

    $action = $request->actions()->where('action', 'delegated')->sole();

    expect($action->actor?->getKey())->toBe($approver->getKey())
        ->and($action->getAttributeValue('comment'))->toBe('Away this week.')
        ->and($action->getAttributeValue('metadata'))
        ->toBe(['delegated_to' => $delegate->getMorphClass().':'.$delegate->getKey()]);

    Event::assertDispatched(
        ApprovalDelegated::class,
        fn (ApprovalDelegated $e): bool => $e->delegate->is($delegate) && $e->approver->is($approver),
    );
});

describe('what delegation refuses', function (): void {
    it('refuses delegating to yourself', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::delegate($request, $approver, $approver))
            ->toThrow(DelegationException::class, 'cannot delegate their approval to themselves');
    });

    it('refuses delegating to somebody who already holds a seat here', function (): void {
        $one = user('One');
        $two = user('Two');

        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::delegate($request, $one, $two))
            ->toThrow(DelegationException::class, 'already has an assignment on this stage');
    });

    it('refuses delegating a seat that has already voted', function (): void {
        $one = user('One');
        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::approve($request, $one);

        expect(fn () => Approval::delegate($request, $one, user('Delegate')))
            ->toThrow(AlreadyDecidedException::class, 'has already approved');
    });

    it('refuses delegating a seat that is already lent out', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::delegate($request, $approver, user('First Delegate'));

        expect(fn () => Approval::delegate($request, $approver, user('Second Delegate')))
            ->toThrow(DelegationException::class, 'already delegated');
    });

    it('refuses delegating a seat on a level that is not this one', function (): void {
        $second = user('Second');

        $workflow = workflow([
            ['approvers' => [user('First')]],
            ['approvers' => [$second]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::delegate($request, $second, user('Delegate')))
            ->toThrow(UnauthorizedApproverException::class);
    });

    it('refuses delegating on a closed request', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::approve($request, $approver);

        expect(fn () => Approval::delegate($request, $approver, user('Delegate')))
            ->toThrow(AlreadyDecidedException::class, 'cannot be decided again');
    });
});

describe('delegation and self-approval', function (): void {
    it('does not let the submitter approve through a delegated seat', function (): void {
        $author = user('Author');
        $approver = user('Approver');

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::delegate($request, $approver, $author);

        expect(fn () => Approval::approve($request, $author))
            ->toThrow(SelfApprovalException::class);
    });
});
