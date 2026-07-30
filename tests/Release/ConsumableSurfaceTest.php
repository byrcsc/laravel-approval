<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Actions\ScanActiveStageHealth;
use ByRcsc\LaravelApproval\Data\ApprovalInteraction;
use ByRcsc\LaravelApproval\Data\DecisionContext;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Enums\TimelineState;
use ByRcsc\LaravelApproval\Enums\WithdrawalIneligibility;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
use ByRcsc\LaravelApproval\Testing\ApprovalFake;
use ByRcsc\LaravelApproval\Tests\Stubs\AutoPurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\ClosingSoftPost;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\SoftPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('exposes withdrawal and delegation eligibility through every public surface', function (): void {
    $submitter = user('Submitter');
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, $submitter);

    expect(Approval::withdrawalEligibility($request, $submitter)->eligible)->toBeTrue()
        ->and($request->canBeWithdrawnBy($submitter))->toBeTrue()
        ->and($request->withdrawalEligibility($approver)->reason)->toBe(WithdrawalIneligibility::NotRequester)
        ->and(Approval::delegationEligibility($request, $approver)->eligible)->toBeTrue()
        ->and($request->canBeDelegatedBy($approver))->toBeTrue()
        ->and($request->delegationEligibility($submitter)->reason)->toBe(DelegationIneligibility::NotAnApprover);
});

it('keeps stage progress public names in the approval domain', function (): void {
    $classes = [
        StageProgress::class,
        Eligibility::class,
        DelegationEligibility::class,
        DecisionContext::class,
        StageStatus::class,
    ];

    $publicNames = collect($classes)->flatMap(fn (string $class): array => [
        (new ReflectionClass($class))->getShortName(),
        ...declaredMethods($class),
        ...declaredProperties($class),
    ])->implode(' ');

    expect(class_exists('ByRcsc\\LaravelApproval\\Data\\Ballot'))->toBeFalse()
        ->and($publicNames)->not->toMatch('/ballot|vote|quorum|seat|slot|carried/i');
});

it('queries approvables by state and current approver without duplicates', function (): void {
    $approver = user('Approver');
    $other = user('Other');
    $pending = post('Pending');
    $rejected = post('Rejected');
    $returned = post('Returned');
    $free = post('Free');
    $flow = workflow([['required_approvals' => 2, 'approvers' => [$approver, $other]]]);

    $pendingRequest = $pending->submitForApproval($flow, null, user('Pending submitter'));
    $rejectedRequest = $rejected->submitForApproval($flow, null, user('Rejected submitter'));
    Approval::reject($rejectedRequest, $approver, 'No');
    $returnedRequest = $returned->submitForApproval($flow, null, user('Returned submitter'));
    Approval::returnForRevision($returnedRequest, $approver, 'Fix it');

    expect(Post::query()->pendingApproval()->pluck('title')->all())->toBe(['Pending'])
        ->and(Post::query()->approvalRejected()->pluck('title')->all())->toBe(['Rejected'])
        ->and(Post::query()->approvalReturned()->pluck('title')->all())->toBe(['Returned'])
        ->and(Post::query()->withoutPendingApproval()->orderBy('id')->pluck('title')->all())
        ->toBe(['Rejected', 'Returned', 'Free'])
        ->and(Post::query()->awaitingApprovalBy($approver)->get()->sole()->is($pending))->toBeTrue()
        ->and(Post::query()->awaitingApprovalBy($other)->get()->sole()->is($pending))->toBeTrue()
        ->and($pendingRequest->state)->toBe(RequestState::Pending);
});

it('returns an eager-loadable progress timeline and pending holders', function (): void {
    $one = user('One');
    $two = user('Two');
    $request = post()->submitForApproval(workflow([
        ['approvers' => [$one]],
        ['approvers' => [$two]],
    ]), null, user('Submitter'));

    expect($request->currentStage()?->sequence)->toBe(1)
        ->and($request->pendingApprovers()[0]->is($one))->toBeTrue()
        ->and($request->timeline()->pluck('state')->all())
        ->toBe([TimelineState::Active, TimelineState::Queued]);

    Approval::approve($request, $one);
    $request = ApprovalRequest::query()->withProgress()->findOrFail($request->getKey());

    expect($request->currentStage()?->sequence)->toBe(2)
        ->and($request->pendingApprovers()[0]->is($two))->toBeTrue()
        ->and($request->timeline()->pluck('state')->all())
        ->toBe([TimelineState::Approved, TimelineState::Active]);
});

it('closes hard deletes while leaving ordinary soft deletes model-configurable', function (): void {
    $flow = workflow([['approvers' => [user('Approver')]]]);

    $hard = post('Hard');
    $hardRequest = $hard->submitForApproval($flow, null, user('Hard submitter'));
    $hard->delete();

    $soft = SoftPost::create(['title' => 'Soft']);
    $softRequest = $soft->submitForApproval($flow, null, user('Soft submitter'));
    $soft->delete();

    $closing = ClosingSoftPost::create(['title' => 'Closing']);
    $closingRequest = $closing->submitForApproval($flow, null, user('Closing submitter'));
    $closing->delete();

    expect($hardRequest->refresh()->state)->toBe(RequestState::Cancelled)
        ->and($hardRequest->actions()->where('action', 'system_closed')->sole()->actor_id)->toBeNull()
        ->and($softRequest->refresh()->state)->toBe(RequestState::Pending)
        ->and($closingRequest->refresh()->state)->toBe(RequestState::Cancelled);
});

it('exports factories for every package model with coherent defaults', function (): void {
    expect(ApprovalWorkflow::factory()->create())->toBeInstanceOf(ApprovalWorkflow::class)
        ->and(ApprovalWorkflowStage::factory()->withApprovers()->create())->toBeInstanceOf(ApprovalWorkflowStage::class)
        ->and(ApprovalWorkflowStageApprover::factory()->create())->toBeInstanceOf(ApprovalWorkflowStageApprover::class)
        ->and(ApprovalRequest::factory()->withStages()->create())->toBeInstanceOf(ApprovalRequest::class)
        ->and(ApprovalRequestStage::factory()->withApprovers()->create())->toBeInstanceOf(ApprovalRequestStage::class)
        ->and(ApprovalRequestAssignment::factory()->create())->toBeInstanceOf(ApprovalRequestAssignment::class)
        ->and(ApprovalAction::factory()->create())->toBeInstanceOf(ApprovalAction::class)
        ->and(ApprovalAttachment::factory()->create())->toBeInstanceOf(ApprovalAttachment::class);
});

it('previews, applies, updates, and refuses destructive workflow definition drift', function (): void {
    $approver = user('Approver');
    $builder = WorkflowBuilder::make('defined-flow', 'Defined flow')
        ->settings(['allow_self_approval' => false])
        ->stage('Manager', fn ($stage) => $stage->approver($approver)->sla(60));

    $preview = Approval::syncDefinitions([$builder], true);

    expect($preview->created)->not->toBeEmpty()
        ->and(Approval::findWorkflow('defined-flow'))->toBeNull();

    $applied = Approval::syncDefinitions([$builder]);
    $unchanged = Approval::syncDefinitions([$builder]);
    $inFlight = post('In flight')->submitForApproval(
        Approval::workflow('defined-flow'),
        null,
        user('In-flight submitter'),
    );
    $changed = $builder->description('Changed')->stage('Finance', fn ($stage) => $stage->approver(user('Finance')));
    $updated = Approval::syncDefinitions([$changed]);

    expect($applied->successful())->toBeTrue()
        ->and($unchanged->changed())->toBeFalse()
        ->and($updated->created)->not->toBeEmpty()
        ->and($updated->updated)->not->toBeEmpty()
        ->and(Approval::workflow('defined-flow')->stages()->count())->toBe(2)
        ->and($inFlight->stages()->count())->toBe(1)
        ->and((string) $inFlight->stages()->sole()->assignments()->sole()->approver_id)->toBe((string) $approver->getKey());

    $refused = Approval::syncDefinitions([[
        'slug' => 'defined-flow',
        'name' => 'Defined flow',
        'stages' => [[
            'name' => 'Manager',
            'approvers' => [$approver],
        ]],
    ]]);

    expect($refused->successful())->toBeFalse()
        ->and(Approval::workflow('defined-flow')->stages()->count())->toBe(2);
});

it('processes bulk outcomes independently and identifies each failure', function (): void {
    $approver = user('Approver');
    $flow = workflow([['approvers' => [$approver]]]);
    $first = post('First')->submitForApproval($flow, null, user('First submitter'));
    $secondSubmitter = user('Second submitter');
    $second = post('Second')->submitForApproval($flow, null, $secondSubmitter);
    Approval::withdraw($second, $secondSubmitter);

    $result = Approval::approveMany([$first, $second], $approver);

    expect($result->successful())->toHaveCount(1)
        ->and($result->failed())->toHaveCount(1)
        ->and($first->refresh()->state)->toBe(RequestState::Approved)
        ->and($second->refresh()->state)->toBe(RequestState::Withdrawn);
});

it('fakes submissions and decisions without mutating workflow state and restores cleanly', function (): void {
    $fake = Approval::fake();
    $post = post();
    $approver = user('Approver');
    $request = approvalRequest($post);

    $post->submitForApproval('missing-flow', null, user('Submitter'), 'Please', ['source' => 'test']);
    Approval::approve($request, $approver, 'Fine');

    expect($fake)->toBeInstanceOf(ApprovalFake::class)
        ->and($request->fresh()?->state)->toBe(RequestState::Pending);

    $fake->assertSubmitted($post, fn (ApprovalInteraction $interaction): bool => $interaction->comment === 'Please');
    $fake->assertApproved($request);
    $fake->restore();

    expect(Approval::fake())->not->toBe($fake);
    Approval::restore();
});

it('auto-submits opted-in approvables once after creation', function (): void {
    $submitter = user('Submitter');
    PurchaseOrder::$actingSubmitter = $submitter;
    workflow([['approvers' => [user('Approver')]]], ['slug' => PurchaseOrder::$workflowSlug]);

    $order = AutoPurchaseOrder::create(['reference' => 'AUTO-1', 'amount' => 100]);

    expect($order->approvalRequests()->count())->toBe(1)
        ->and($order->pendingApprovalRequest())->not->toBeNull();
});

it('bounds active-step health scans and applies the finding limit during scanning', function (): void {
    $living = user('Living holder');

    for ($index = 0; $index < 120; $index++) {
        $request = approvalRequest(attributes: ['ulid' => (string) Str::ulid()]);
        $stage = $request->stages()->create([
            'sequence' => 1,
            'name' => "Health {$index}",
            'required_approvals' => 1,
            'state' => 'active',
        ]);
        $stage->assignments()->create([
            'approver_type' => $living->getMorphClass(),
            'approver_id' => $index < 2 ? 999_000 + $index : $living->getKey(),
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $stranded = app(ScanActiveStageHealth::class)(2, 50);
    $queries = DB::getQueryLog();

    expect($stranded)->toHaveCount(2)
        ->and(count($queries))->toBeLessThan(10);
});
