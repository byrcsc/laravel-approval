<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;

it('refuses somebody who holds no assignment', function (): void {
    $workflow = workflow([['approvers' => [user('Approver')]]]);
    $request = post()->submitForApproval($workflow, null, user());

    expect(fn () => Approval::approve($request, user('Passer By')))
        ->toThrow(UnauthorizedApproverException::class, 'has no assignment on the active stage');
});

it('refuses an approver from a stage that is not this one', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    expect(fn () => Approval::approve($request, $second))
        ->toThrow(UnauthorizedApproverException::class);
});

it('refuses the same approver deciding twice', function (): void {
    $one = user('One');
    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);

    expect(fn () => Approval::approve($request, $one))
        ->toThrow(AlreadyDecidedException::class, 'has already approved the active stage');
});

it('does not let a second decision count toward the required approvals', function (): void {
    $one = user('One');
    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);
    rescue(fn () => Approval::approve($request, $one));

    expect($request->refresh()->state)->toBe(RequestState::Pending)
        ->and($request->actions()->where('action', 'approved')->count())->toBe(1);
});

it('refuses a decision on a request that has closed', function (): void {
    $approver = user();
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver);

    expect(fn () => Approval::approve($request, $approver))
        ->toThrow(AlreadyDecidedException::class, 'was already approved and cannot be decided again');
});

it('refuses the requester approving their own request', function (): void {
    $author = user('Author');
    $workflow = workflow([['approvers' => [$author]]]);
    $request = post()->submitForApproval($workflow, null, $author);

    expect(fn () => Approval::approve($request, $author))
        ->toThrow(SelfApprovalException::class, 'may not approve it');
});

it('allows self-approval when the config says so', function (): void {
    config()->set('approval.allow_self_approval', true);

    $author = user('Author');
    $workflow = workflow([['approvers' => [$author]]]);
    $request = post()->submitForApproval($workflow, null, $author);

    Approval::approve($request, $author);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('lets a workflow permit self-approval on its own', function (): void {
    $author = user('Author');
    $workflow = workflow(
        [['approvers' => [$author]]],
        ['settings' => ['allow_self_approval' => true]],
    );

    $request = post()->submitForApproval($workflow, null, $author);
    Approval::approve($request, $author);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('lets a workflow forbid self-approval the config allows', function (): void {
    config()->set('approval.allow_self_approval', true);

    $author = user('Author');
    $workflow = workflow(
        [['approvers' => [$author]]],
        ['settings' => ['allow_self_approval' => false]],
    );

    $request = post()->submitForApproval($workflow, null, $author);

    expect(fn () => Approval::approve($request, $author))->toThrow(SelfApprovalException::class);
});

it('lets the requester approve a stage they are not the only assignment on', function (): void {
    config()->set('approval.allow_self_approval', false);

    $author = user('Author');
    $colleague = user('Colleague');

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [$author, $colleague]]]);
    $request = post()->submitForApproval($workflow, null, $author);

    // The requester still cannot decide, but the stage is not deadlocked.
    expect(fn () => Approval::approve($request, $author))->toThrow(SelfApprovalException::class);

    Approval::approve($request, $colleague);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('refuses a rejection with no reason', function (): void {
    expect(fn () => ApprovalDecision::reject(user(), '   '))
        ->toThrow(CommentRequiredException::class);
});
