<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow('2026-07-28 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

describe('escalate', function (): void {
    it('brings in whoever the resolver names', function (): void {
        $duty = user('Duty Officer');
        HeadOfDepartmentResolver::$heads = [$duty];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        $seats = $request->steps()->sole()->approverSlots()->get();

        expect($seats)->toHaveCount(2)
            ->and($seats->last()?->approver?->getKey())->toBe($duty->getKey())
            ->and($seats->last()?->source)->toBe(ApproverSource::Escalated);
    });

    it('lets the escalated approver carry the level', function (): void {
        $duty = user('Duty Officer');
        HeadOfDepartmentResolver::$heads = [$duty];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();
        Approval::approve($request, $duty);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });

    it('leaves the original approver able to decide too', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
        );

        $original = $request->steps()->sole()->approverSlots()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();
        Approval::approve($request, $original);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });

    /**
     * The trap: on an all-of-them level, adding a seat would make an already
     * late level harder to carry.
     */
    it('pins an all-of-them quorum before adding anybody', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
            requiredApprovals: null,
            approvers: 2,
        );

        $step = $request->steps()->sole();

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($step->refresh()->getAttributeValue('required_approvals'))->toBe(2)
            ->and($step->approverSlots()->count())->toBe(3);
    });

    it('leaves a numeric quorum alone', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
            requiredApprovals: 2,
            approvers: 2,
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->steps()->sole()->getAttributeValue('required_approvals'))->toBe(2);
    });

    it('takes an explicit quorum from the level config', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class, 'required_approvals' => 1],
            requiredApprovals: 2,
            approvers: 2,
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->steps()->sole()->getAttributeValue('required_approvals'))->toBe(1);
    });

    it('does not seat somebody who already holds a seat', function (): void {
        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
        );

        $seated = $request->steps()->sole()->approverSlots()->sole()->approver;
        HeadOfDepartmentResolver::$heads = [$seated];

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->steps()->sole()->approverSlots()->count())->toBe(1);
    });

    it('does not rewrite the quorum when the resolver adds nobody', function (): void {
        HeadOfDepartmentResolver::$heads = [];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
            requiredApprovals: null,
            approvers: 2,
        );

        $step = $request->steps()->sole();

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($step->refresh()->getAttributeValue('required_approvals'))->toBeNull();
    });
});

describe('auto-approve', function (): void {
    it('carries the level with no actor behind it', function (): void {
        $request = overdueRequest(EscalationAction::AutoApprove);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        $action = $request->actions()->where('action', 'approved')->sole();

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($request->steps()->sole()->state)->toBe(StepState::Approved)
            ->and($action->getAttributeValue('actor_id'))->toBeNull()
            ->and($action->getAttributeValue('metadata'))->toBe(['automatic' => true])
            ->and($action->getAttributeValue('comment'))->toContain('passed its deadline');
    });

    it('hands the request to the next level rather than finishing it', function (): void {
        $second = user('Second');

        $workflow = workflow([
            ['sla_minutes' => 60, 'escalation_strategy' => EscalationAction::AutoApprove, 'approvers' => [user('First')]],
            ['approvers' => [$second]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->refresh()->state)->toBe(RequestState::Pending)
            ->and($request->getAttributeValue('current_level_sequence'))->toBe(2);

        Approval::approve($request, $second);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });

    it('applies the draft, stale check and all', function (): void {
        $post = post('Original title');

        $workflow = workflow([[
            'sla_minutes' => 60,
            'escalation_strategy' => EscalationAction::AutoApprove,
            'approvers' => [user('Approver')],
        ]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user('Author'));

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($post->fresh()?->getAttributeValue('title'))->toBe('Revised title');
    });

    it('conflicts rather than overwriting an edit made while it waited', function (): void {
        $post = post('Original title');

        $workflow = workflow([[
            'sla_minutes' => 60,
            'escalation_strategy' => EscalationAction::AutoApprove,
            'approvers' => [user('Approver')],
        ]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user('Author'));
        $post->forceFill(['title' => 'Edited by somebody else'])->save();

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->refresh()->state)->toBe(RequestState::Conflicted)
            ->and($post->fresh()?->getAttributeValue('title'))->toBe('Edited by somebody else');
    });

    it('counts as the whole level even when the quorum was several', function (): void {
        $request = overdueRequest(EscalationAction::AutoApprove, requiredApprovals: 2, approvers: 3);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        // The deadline decided the level, not a vote count.
        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });
});

describe('auto-reject', function (): void {
    it('closes the request with no actor behind it', function (): void {
        $request = overdueRequest(EscalationAction::AutoReject);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        $action = $request->actions()->where('action', 'rejected')->sole();

        expect($request->refresh()->state)->toBe(RequestState::Rejected)
            ->and($request->completed_at)->not->toBeNull()
            ->and($action->getAttributeValue('actor_id'))->toBeNull()
            ->and($action->getAttributeValue('comment'))->toContain('passed its deadline');
    });

    it('skips the levels that never ran', function (): void {
        $workflow = workflow([
            ['sla_minutes' => 60, 'escalation_strategy' => EscalationAction::AutoReject, 'approvers' => [user('First')]],
            ['approvers' => [user('Second')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->steps()->get()->pluck('state')->all())
            ->toBe([StepState::Rejected, StepState::Skipped]);
    });

    it('leaves the record untouched and free to resubmit', function (): void {
        $post = post('Original title');

        $workflow = workflow([[
            'sla_minutes' => 60,
            'escalation_strategy' => EscalationAction::AutoReject,
            'approvers' => [user('Approver')],
        ]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user('Author'));

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($post->fresh()?->getAttributeValue('title'))->toBe('Original title')
            ->and($post->isPendingApproval())->toBeFalse()
            ->and(Approval::resubmit($request->refresh())->state)->toBe(RequestState::Pending);
    });

    it('takes the comment the level configured', function (): void {
        $request = overdueRequest(EscalationAction::AutoReject, ['comment' => 'Nobody signed within the SLA.']);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->actions()->where('action', 'rejected')->sole()->getAttributeValue('comment'))
            ->toBe('Nobody signed within the SLA.');
    });
});
