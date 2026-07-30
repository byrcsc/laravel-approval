<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;

/**
 * Assignments that changed hands more than once, and assignments that land on the one
 * person who must not use them.
 */
it('lets a delegate hand the assignment on, locking out everyone before them', function (): void {
    $original = user('Original');
    $first = user('First Delegate');
    $second = user('Second Delegate');

    $workflow = workflow([['approvers' => [$original]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $original, $first);

    // First is away too. The assignment keeps Original's name; only the holder moves.
    Approval::delegate($request, $first, $second);

    $assignment = $request->stages()->sole()->assignments()->sole();

    expect($assignment->approver?->is($original))->toBeTrue()
        ->and($assignment->delegatedTo?->is($second))->toBeTrue();

    expect(fn () => Approval::approve($request, $original))
        ->toThrow(UnauthorizedApproverException::class)
        ->and(fn () => Approval::approve($request, $first))
        ->toThrow(UnauthorizedApproverException::class);

    Approval::approve($request, $second);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('reassigns an assignment onto the requester but never lets them decide it', function (): void {
    $requester = user('Requester');
    $leaver = user('Leaver');
    $other = user('Other');

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [$leaver, $other]]]);
    $request = post()->submitForApproval($workflow, null, $requester);

    $assignment = $request->stages()->sole()->assignments()->orderBy('id')->get()->first();
    assert($assignment !== null);

    // The reassign itself is an administrative rewrite and goes through; the
    // self-approval rule bites where it always does, at the decision.
    Approval::reassign($request, $assignment, $requester, user('Admin'));

    expect(fn () => Approval::approve($request, $requester))
        ->toThrow(SelfApprovalException::class);

    Approval::approve($request, $other);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('keeps the decisions already cast when a resync reshapes the stage', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $second = user('Second');

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class], $second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $head);

    // The org chart moves mid-decision: a deputy appears alongside.
    $deputy = user('Deputy');
    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    Approval::resync($request, user('Operator'));

    // Head's decision survived the reshape; the deputy's completes the required approvals.
    Approval::approve($request, $deputy);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->actions()->where('action', 'approved')->count())->toBe(2);
});
