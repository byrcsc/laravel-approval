<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
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

        $assignments = $request->stages()->sole()->assignments()->get();

        expect($assignments)->toHaveCount(2)
            ->and($assignments->last()?->approver?->getKey())->toBe($duty->getKey())
            ->and($assignments->last()?->source)->toBe(ApproverSource::Escalated);
    });

    it('lets the escalated approver carry the stage', function (): void {
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

        $original = $request->stages()->sole()->assignments()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();
        Approval::approve($request, $original);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });

    it('leaves a numeric required approvals alone', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
            requiredApprovals: 2,
            approvers: 2,
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->stages()->sole()->getAttributeValue('required_approvals'))->toBe(2);
    });

    it('takes an explicit required approvals from the stage config', function (): void {
        HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class, 'required_approvals' => 1],
            requiredApprovals: 2,
            approvers: 2,
        );

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->stages()->sole()->getAttributeValue('required_approvals'))->toBe(1);
    });

    it('does not add somebody who already holds an assignment', function (): void {
        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
        );

        $represented = $request->stages()->sole()->assignments()->sole()->approver;
        HeadOfDepartmentResolver::$heads = [$represented];

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->stages()->sole()->assignments()->count())->toBe(1);
    });

    it('does not rewrite the required approvals when the resolver adds nobody', function (): void {
        HeadOfDepartmentResolver::$heads = [];

        $request = overdueRequest(
            EscalationAction::Escalate,
            ['resolver' => HeadOfDepartmentResolver::class],
            requiredApprovals: null,
            approvers: 2,
        );

        $stage = $request->stages()->sole();

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($stage->refresh()->getAttributeValue('required_approvals'))->toBeNull();
    });
});

describe('auto-approve', function (): void {
    it('carries the stage with no actor behind it', function (): void {
        $request = overdueRequest(EscalationAction::AutoApprove);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        $action = $request->actions()->where('action', 'approved')->sole();

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($request->stages()->sole()->state)->toBe(StageStatus::Approved)
            ->and($action->getAttributeValue('actor_id'))->toBeNull()
            ->and($action->getAttributeValue('metadata'))->toBe(['automatic' => true])
            ->and($action->getAttributeValue('comment'))->toContain('passed its deadline');
    });

    it('hands the request to the next stage rather than finishing it', function (): void {
        $second = user('Second');

        $workflow = workflow([
            ['sla_minutes' => 60, 'escalation_strategy' => EscalationAction::AutoApprove, 'approvers' => [user('First')]],
            ['approvers' => [$second]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->refresh()->state)->toBe(RequestState::Pending)
            ->and($request->getAttributeValue('current_stage_sequence'))->toBe(2);

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

    it('counts as the whole stage even when the required approvals was several', function (): void {
        $request = overdueRequest(EscalationAction::AutoApprove, requiredApprovals: 2, approvers: 3);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        // The deadline decided the stage, not a decision count.
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

    it('skips the stages that never ran', function (): void {
        $workflow = workflow([
            ['sla_minutes' => 60, 'escalation_strategy' => EscalationAction::AutoReject, 'approvers' => [user('First')]],
            ['approvers' => [user('Second')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user());

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->stages()->get()->pluck('state')->all())
            ->toBe([StageStatus::Rejected, StageStatus::Skipped]);
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
            ->and(Approval::reviseAndResubmit($request->refresh(), RevisedDraft::inherit())->state)->toBe(RequestState::Pending);
    });

    it('takes the comment the stage configured', function (): void {
        $request = overdueRequest(EscalationAction::AutoReject, ['comment' => 'Nobody signed within the SLA.']);

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->actions()->where('action', 'rejected')->sole()->getAttributeValue('comment'))
            ->toBe('Nobody signed within the SLA.');
    });
});
