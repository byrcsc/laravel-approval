<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalCancelled;
use ByRcsc\LaravelApproval\Events\ApprovalSystemClosed;
use ByRcsc\LaravelApproval\Events\ApprovalWithdrawn;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Policies\ApprovalRequestPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

it('lets a requester explicitly withdraw their own unresolved request', function (): void {
    Event::fake([ApprovalWithdrawn::class]);
    $requester = user('Requester');
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        $requester,
    );

    Approval::withdraw($request, $requester, 'No longer needed.');

    expect($request->refresh()->state)->toBe(RequestState::Withdrawn)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->actions()->where('action', 'withdrawn')->sole()->actor?->is($requester))->toBeTrue();

    Event::assertDispatched(ApprovalWithdrawn::class);
});

it('does not let another actor or an omitted actor withdraw a request', function (): void {
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Requester'),
    );

    expect(fn () => Approval::withdraw($request, user('Other')))
        ->toThrow(UnauthorizedApproverException::class)
        ->and(fn () => Approval::withdraw($request))
        ->toThrow(ArgumentCountError::class);
});

it('requires an attributable host-authorized actor for administrative cancellation', function (): void {
    Event::fake([ApprovalCancelled::class]);
    Gate::before(
        fn ($actor, string $ability): ?bool => $ability === 'cancel' ? $actor->name === 'Admin' : null,
    );

    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Requester'),
    );
    $admin = user('Admin');

    Approval::cancel($request, $admin, 'Duplicate request.');

    expect($request->refresh()->state)->toBe(RequestState::Cancelled)
        ->and($request->actions()->where('action', 'cancelled')->sole()->actor?->is($admin))->toBeTrue();

    Event::assertDispatched(ApprovalCancelled::class);
});

it('does not substitute requester authority for administrative authorization', function (): void {
    Gate::policy(ApprovalRequest::class, ApprovalRequestPolicy::class);
    $requester = user('Requester');
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        $requester,
    );

    expect(fn () => Approval::cancel($request, $requester))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Approval::cancel($request))
        ->toThrow(ArgumentCountError::class);
});

it('requires host authorization when administrative cancellation discards a conflict', function (): void {
    Gate::policy(ApprovalRequest::class, ApprovalRequestPolicy::class);
    $approver = user('Approver');
    $post = post('Original title');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Revised title'],
        user('Requester'),
    );

    $post->forceFill(['title' => 'Edited elsewhere'])->save();
    Approval::approve($request, $approver);

    expect(fn () => Approval::resolve(
        $request->refresh(),
        ConflictResolution::Discard,
        user('Unprivileged operator'),
    ))->toThrow(AuthorizationException::class);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted);
});

it('uses an internal system closure when the approvable is deleted', function (): void {
    Event::fake([ApprovalSystemClosed::class, ApprovalCancelled::class, ApprovalWithdrawn::class]);
    $post = post();
    $request = $post->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Requester'),
    );

    $post->delete();

    expect($request->refresh()->state)->toBe(RequestState::Cancelled)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->actions()->where('action', 'system_closed')->sole()->actor_id)->toBeNull()
        ->and($request->actions()->whereIn('action', ['withdrawn', 'cancelled'])->count())->toBe(0);

    Event::assertDispatched(ApprovalSystemClosed::class);
    Event::assertNotDispatched(ApprovalCancelled::class);
    Event::assertNotDispatched(ApprovalWithdrawn::class);
});
