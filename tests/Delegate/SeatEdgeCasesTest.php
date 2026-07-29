<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;

/**
 * Seats that changed hands more than once, and seats that land on the one
 * person who must not use them.
 */
it('lets a delegate hand the seat on, locking out everyone before them', function (): void {
    $original = user('Original');
    $first = user('First Delegate');
    $second = user('Second Delegate');

    $workflow = workflow([['approvers' => [$original]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $original, $first);

    // First is away too. The seat keeps Original's name; only the holder moves.
    Approval::delegate($request, $first, $second);

    $seat = $request->steps()->sole()->approverSlots()->sole();

    expect($seat->approver?->is($original))->toBeTrue()
        ->and($seat->delegatedTo?->is($second))->toBeTrue();

    expect(fn () => Approval::approve($request, $original))
        ->toThrow(UnauthorizedApproverException::class)
        ->and(fn () => Approval::approve($request, $first))
        ->toThrow(UnauthorizedApproverException::class);

    Approval::approve($request, $second);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('reassigns a seat onto the submitter but never lets them vote from it', function (): void {
    $submitter = user('Submitter');
    $leaver = user('Leaver');
    $other = user('Other');

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [$leaver, $other]]]);
    $request = post()->submitForApproval($workflow, null, $submitter);

    $seat = $request->steps()->sole()->approverSlots()->orderBy('id')->get()->first();
    assert($seat !== null);

    // The reassign itself is an administrative rewrite and goes through; the
    // self-approval rule bites where it always does, at the vote.
    Approval::reassign($request, $seat, $submitter);

    expect(fn () => Approval::approve($request, $submitter))
        ->toThrow(SelfApprovalException::class);

    Approval::approve($request, $other);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('keeps the votes already cast when a resync reshapes the level', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $second = user('Second');

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class], $second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $head);

    // The org chart moves mid-vote: a deputy appears alongside.
    $deputy = user('Deputy');
    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    Approval::resync($request);

    // Head's vote survived the reshape; the deputy's completes the quorum.
    Approval::approve($request, $deputy);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->actions()->where('action', 'approved')->count())->toBe(2);
});
