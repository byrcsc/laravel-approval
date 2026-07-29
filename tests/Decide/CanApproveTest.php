<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\Ineligibility;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Policies\ApprovalRequestPolicy;

it('grants the approver whose turn it is', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    $eligibility = Approval::eligibility($request, $approver);

    expect($eligibility->eligible)->toBeTrue()
        ->and($eligibility->reason)->toBeNull()
        ->and(Approval::canApprove($request, $approver))->toBeTrue();
});

it('includes the stage progress and assignment a caller is about to render', function (): void {
    $one = user('One');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]),
        null,
        user(),
    );

    $eligibility = Approval::eligibility($request, $one);

    expect($eligibility->stage?->getAttributeValue('sequence'))->toBe(1)
        ->and($eligibility->stageProgress?->approvalsRequired)->toBe(2)
        ->and($eligibility->stageProgress?->approvalsReceived)->toBe(0)
        ->and($eligibility->assignment)->not->toBeNull();
});

it('names the reason somebody holds no seat', function (): void {
    $request = post()->submitForApproval(workflow([['approvers' => [user('Approver')]]]), null, user());

    expect(Approval::eligibility($request, user('Passer By'))->reason)
        ->toBe(Ineligibility::NotAnApprover);
});

it('names the reason an approver from a later level cannot act yet', function (): void {
    $second = user('Second');
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('First')]], ['approvers' => [$second]]]),
        null,
        user(),
    );

    expect(Approval::eligibility($request, $second)->reason)->toBe(Ineligibility::NotAnApprover);
});

it('tells a delegator their seat is no longer theirs', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    Approval::delegate($request, $approver, $delegate);

    expect(Approval::eligibility($request, $approver)->reason)->toBe(Ineligibility::AssignmentDelegated)
        ->and(Approval::canApprove($request, $delegate))->toBeTrue();
});

it('tells an approver they have already voted', function (): void {
    $one = user('One');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]),
        null,
        user(),
    );

    Approval::approve($request, $one);

    expect(Approval::eligibility($request->refresh(), $one)->reason)
        ->toBe(Ineligibility::DecisionAlreadyRecorded);
});

it('tells a submitter that self-approval is the objection', function (): void {
    $submitter = user('Submitter');
    $request = post()->submitForApproval(workflow([['approvers' => [$submitter]]]), null, $submitter);

    expect(Approval::eligibility($request, $submitter)->reason)->toBe(Ineligibility::SelfApproval);

    config()->set('approval.allow_self_approval', true);

    expect(Approval::canApprove($request, $submitter))->toBeTrue();
});

it('reports a closed request as closed rather than as a seat problem', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    Approval::reject($request, $approver, 'No.');

    $eligibility = Approval::eligibility($request->refresh(), $approver);

    expect($eligibility->reason)->toBe(Ineligibility::RequestClosed)
        ->and($eligibility->stage)->toBeNull();
});

it('reports a conflicted request as closed, since no level is waiting', function (): void {
    $approver = user('Approver');
    $order = purchaseOrder();
    $request = $order->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user(),
    );

    $order->forceFill(['amount' => 750])->save();
    Approval::approve($request, $approver);

    expect(Approval::eligibility($request->refresh(), $approver)->reason)
        ->toBe(Ineligibility::RequestClosed);

    Approval::resolve($request, ConflictResolution::Discard, user('Admin'));
});

it('never grants and refuses the same actor differently', function (): void {
    $approver = user('Approver');
    $stranger = user('Stranger');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    // The refusal the read reports and the exception the vote throws are one
    // calculation; this is the pin that keeps them from drifting apart.
    expect(Approval::canApprove($request, $stranger))->toBeFalse()
        ->and(fn () => Approval::approve($request, $stranger))->toThrow(UnauthorizedApproverException::class)
        ->and(Approval::canApprove($request, $approver))->toBeTrue();

    Approval::approve($request, $approver);

    expect($request->refresh()->state->value)->toBe('approved');
});

it('answers from the model for the record under approval', function (): void {
    $approver = user('Approver');
    $post = post();

    expect($post->canBeApprovedBy($approver))->toBeFalse()
        ->and($post->approvalEligibility($approver))->toBeNull();

    $post->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    expect($post->canBeApprovedBy($approver))->toBeTrue()
        ->and($post->canBeApprovedBy(user('Passer By')))->toBeFalse()
        ->and($post->approvalEligibility(user('Passer By'))?->reason)->toBe(Ineligibility::NotAnApprover);
});

it('counts everybody named on the request as involved in it', function (): void {
    $first = user('First');
    $second = user('Second');
    $submitter = user('Submitter');

    $request = post()->submitForApproval(
        workflow([['approvers' => [$first]], ['approvers' => [$second]]]),
        null,
        $submitter,
    );

    // A later level's approver and a submitter both have standing, and a
    // first-level approver keeps it after voting.
    Approval::approve($request, $first);

    expect($request->involves($submitter))->toBeTrue()
        ->and($request->involves($first))->toBeTrue()
        ->and($request->involves($second))->toBeTrue()
        ->and($request->involves(user('Passer By')))->toBeFalse();
});

it('counts a delegate as involved and keeps the delegator', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    Approval::delegate($request, $approver, $delegate);

    expect($request->involves($delegate))->toBeTrue()
        ->and($request->involves($approver))->toBeTrue();
});

it('registers the shipped policy against the request model', function (): void {
    $approver = user('Approver');
    $stranger = user('Stranger');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    expect($approver->can('approve', $request))->toBeTrue()
        ->and($approver->can('reject', $request))->toBeTrue()
        ->and($approver->can('returnToSubmitter', $request))->toBeTrue()
        ->and($approver->can('view', $request))->toBeTrue()
        ->and($stranger->can('approve', $request))->toBeFalse()
        ->and($stranger->can('view', $request))->toBeFalse();
});

it('lets a host extend the shipped policy rather than fork it', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    Gate::policy($request::class, (new class extends ApprovalRequestPolicy
    {
        public function approve($user, $request): bool
        {
            return parent::approve($user, $request) && $user->name === 'Somebody Else';
        }
    })::class);

    expect($approver->can('approve', $request))->toBeFalse()
        ->and($approver->can('reject', $request))->toBeTrue();
});

it("needs no wiring: Laravel's own guesser finds the shipped policy", function (): void {
    $request = post()->submitForApproval(workflow([['approvers' => [user('Approver')]]]), null, user());

    // Models\ApprovalRequest -> Policies\ApprovalRequestPolicy is the pair the
    // default guesser looks for, which is why there is no config option and
    // nothing to register.
    expect(Gate::getPolicyFor($request))->toBeInstanceOf(ApprovalRequestPolicy::class);
});
