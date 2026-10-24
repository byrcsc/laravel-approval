<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Definitions\StageBuilder;
use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

function oneOffWorkflow(User $approver): WorkflowBuilder
{
    return WorkflowBuilder::make('one-off')
        ->stage('Finance', fn (StageBuilder $stage) => $stage->approver($approver))
        ->stage('Director', fn (StageBuilder $stage) => $stage->approver($approver));
}

it('runs a workflow defined at the call site without storing it', function (): void {
    $finance = user('Finance');
    $order = purchaseOrder();

    $request = $order->submitForApproval(oneOffWorkflow($finance), ['amount' => 9_000], user());

    expect(ApprovalWorkflow::query()->exists())->toBeFalse()
        ->and($request->workflow_id)->toBeNull()
        ->and($request->stages()->pluck('name')->all())->toBe(['Finance', 'Director'])
        ->and($request->actions()->sole()->metadata['workflow'])->toBe('one-off');

    Approval::approve($request, $finance);
    Approval::approve($request, $finance);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($order->refresh()->amount)->toBe(9_000);
});

it('refuses settings on an inline workflow rather than ignoring them', function (): void {
    expect(fn () => post()->submitForApproval(oneOffWorkflow(user())->settings(['allow_self_approval' => true]), null, user()))
        ->toThrow(InvalidWorkflowDefinitionException::class, 'runs inline and cannot carry settings');
});

it('needs the definition again to revise and resubmit', function (): void {
    $finance = user('Finance');
    $request = purchaseOrder()->submitForApproval(oneOffWorkflow($finance), ['amount' => 9_000], user());
    Approval::reject($request, $finance, 'Too much.');

    expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::withAttributes(['amount' => 8_000])))
        ->toThrow(WorkflowNotFoundException::class, 'inline definition');

    $replacement = Approval::reviseAndResubmit($request, RevisedDraft::withAttributes(['amount' => 8_000]), workflow: oneOffWorkflow($finance));

    expect($replacement->state)->toBe(RequestState::Pending);
});
