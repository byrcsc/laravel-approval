<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Event;

describe('rejection', function (): void {
    it('closes the whole request on one no', function (): void {
        $first = user('First');
        $workflow = workflow([
            ['approvers' => [$first]],
            ['approvers' => [user('Second')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        Approval::reject($request, $first, 'The numbers do not add up.');

        $stages = $request->refresh()->stages()->get();

        expect($request->state)->toBe(RequestState::Rejected)
            ->and($request->completed_at)->not->toBeNull()
            ->and($stages[0]->state)->toBe(StageStatus::Rejected)
            ->and($stages[1]->state)->toBe(StageStatus::Skipped);
    });

    it('skips rather than rejects the stages that never ran', function (): void {
        $second = user('Second');
        $workflow = workflow([
            ['approvers' => [user('First')]],
            ['approvers' => [$second]],
            ['approvers' => [user('Third')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());
        Approval::approve($request, $request->stages()->get()[0]->assignments()->sole()->approver);
        Approval::reject($request, $second, 'No.');

        expect($request->stages()->get()->pluck('state')->all())->toBe([
            StageStatus::Approved,
            StageStatus::Rejected,
            StageStatus::Skipped,
        ]);
    });

    it('keeps the reason in the audit trail', function (): void {
        $approver = user();
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::reject($request, $approver, 'Missing a purchase order.');

        $action = $request->actions()->where('action', 'rejected')->sole();

        expect($action->getAttributeValue('comment'))->toBe('Missing a purchase order.')
            ->and($action->actor?->getKey())->toBe($approver->getKey());
    });

    it('announces the rejection with its reason', function (): void {
        Event::fake([ApprovalRejected::class]);

        $approver = user();
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::reject($request, $approver, 'Not this quarter.');

        Event::assertDispatched(
            ApprovalRejected::class,
            fn (ApprovalRejected $e): bool => $e->comment === 'Not this quarter.',
        );
    });

    it('holds a rejected request to the same eligibility rules', function (): void {
        $workflow = workflow([['approvers' => [user('Approver')]]]);
        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::reject($request, user('Nobody'), 'No.'))
            ->toThrow(UnauthorizedApproverException::class);

        expect($request->refresh()->state)->toBe(RequestState::Pending);
    });

    it('frees the record for a fresh request', function (): void {
        $post = post();
        $approver = user();
        $workflow = workflow([['approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, null, user());
        Approval::reject($first, $approver, 'Try again.');

        expect($post->isPendingApproval())->toBeFalse();

        $second = $post->submitForApproval($workflow, null, user());

        expect($second->is($first))->toBeFalse();
    });
});

describe('withdrawal', function (): void {
    it('lets the requester withdraw their own request', function (): void {
        $author = user('Author');
        $workflow = workflow([['approvers' => [user('Approver')]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::withdraw($request, $author, 'Sent by mistake.');

        expect($request->refresh()->state)->toBe(RequestState::Withdrawn)
            ->and($request->completed_at)->not->toBeNull()
            ->and($request->stages()->sole()->state)->toBe(StageStatus::Skipped);
    });

    it('refuses somebody else withdrawing it', function (): void {
        $workflow = workflow([['approvers' => [user('Approver')]]]);
        $request = post()->submitForApproval($workflow, null, user('Author'));

        expect(fn () => Approval::withdraw($request, user('Meddler')))
            ->toThrow(UnauthorizedApproverException::class, 'did not submit approval request');
    });

    it('refuses to withdraw one that already closed', function (): void {
        $approver = user();
        $author = user('Author');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::approve($request, $approver);

        expect(fn () => Approval::withdraw($request, $author))
            ->toThrow(AlreadyDecidedException::class, 'cannot be decided again');
    });

    it('withdraws the model\'s unresolved request through the facade', function (): void {
        $post = post();
        $author = user('Author');
        $workflow = workflow([['approvers' => [user('Approver')]]]);

        $post->submitForApproval($workflow, null, $author);
        $request = $post->pendingApprovalRequest();

        expect($request)->not->toBeNull()
            ->and($request?->canBeWithdrawnBy($author))->toBeTrue();

        Approval::withdraw($request, $author);

        expect($post->isPendingApproval())->toBeFalse();
    });
});
