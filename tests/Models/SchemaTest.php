<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
use ByRcsc\LaravelApproval\Support\TableNames;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates every table the package needs', function () {
    foreach (TableNames::defaults() as $name) {
        expect(Schema::hasTable($name))->toBeTrue("Missing table [{$name}].");
    }
});

it('gives every model a table that exists', function (string $model) {
    /** @var Model $instance */
    $instance = new $model;

    expect(Schema::hasTable($instance->getTable()))->toBeTrue();
})->with([
    ApprovalWorkflow::class,
    ApprovalWorkflowStage::class,
    ApprovalWorkflowStageApprover::class,
    ApprovalRequest::class,
    ApprovalRequestStage::class,
    ApprovalRequestAssignment::class,
    ApprovalAction::class,
    ApprovalAttachment::class,
    ApprovalDelegation::class,
]);

it('types the approvable key from config, independently of user keys', function (string $keyType, bool $integer) {
    $this->rebootWith(['approval.approvable_key_type' => $keyType]);

    // Compared with an integer user column rather than a type name, which
    // differs between the drivers CI runs against.
    $userColumn = Schema::getColumnType('approval_request_assignments', 'approver_id');

    expect(Schema::getColumnType('approval_requests', 'approvable_id') === $userColumn)->toBe($integer);
})->with([
    'int' => ['int', true],
    'uuid' => ['uuid', false],
    'ulid' => ['ulid', false],
    'string' => ['string', false],
]);

it('describes a workflow and its stages', function () {
    expect(Schema::hasColumns('approval_workflows', [
        'id', 'name', 'slug', 'description', 'active',
        'approvable_type', 'condition', 'condition_config', 'priority', 'settings',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('approval_workflow_stages', [
        'id', 'workflow_id', 'sequence', 'name', 'required_approvals', 'rejection_policy',
        'sla_minutes', 'escalation_strategy', 'escalation_config',
        'remind_every_minutes', 'condition', 'condition_config',
    ]))->toBeTrue();

    expect(Schema::hasColumns('approval_workflow_stage_approvers', [
        'id', 'stage_id', 'approver_type', 'approver_id', 'resolver', 'resolver_config',
    ]))->toBeTrue();
});

it('describes a request, its steps, and its approver assignments', function () {
    expect(Schema::hasColumns('approval_requests', [
        'id', 'ulid', 'approvable_type', 'approvable_id', 'workflow_id', 'state',
        'current_stage_sequence', 'new_attributes', 'original_attributes',
        'requester_type', 'requester_id', 'resubmitted_from_id',
        'submitted_at', 'completed_at', 'audit_head_hash',
    ]))->toBeTrue();

    expect(Schema::hasColumns('approval_request_stages', [
        'id', 'request_id', 'sequence', 'name', 'required_approvals', 'rejection_policy', 'sla_minutes',
        'escalation_strategy', 'escalation_config', 'remind_every_minutes',
        'condition', 'condition_config', 'condition_result',
        'deadline_at', 'escalated_at', 'last_reminded_at', 'stranded_at', 'state',
    ]))->toBeTrue();

    expect(Schema::hasColumns('approval_request_assignments', [
        'id', 'stage_id', 'approver_type', 'approver_id',
        'delegated_to_type', 'delegated_to_id', 'source',
    ]))->toBeTrue();
});

it('describes the audit trail without an updated_at', function () {
    expect(Schema::hasColumns('approval_actions', [
        'id', 'request_id', 'stage_id', 'actor_type', 'actor_id',
        'action', 'comment', 'metadata', 'created_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('approval_actions', 'updated_at'))->toBeFalse();
});

it('keeps two stages from claiming the same position in a workflow', function () {
    $workflow = workflow([['name' => 'Manager']]);

    expect(fn () => $workflow->stages()->create([
        'sequence' => 1,
        'name' => 'Also first',
    ]))->toThrow(QueryException::class);
});

it('keeps two workflows from sharing a slug', function () {
    workflow(attributes: ['slug' => 'purchase-order']);

    expect(fn () => workflow(attributes: ['slug' => 'purchase-order']))
        ->toThrow(QueryException::class);
});

it('takes the stages of a workflow down with it', function () {
    $workflow = workflow([['name' => 'Manager'], ['name' => 'Finance']]);

    expect(ApprovalWorkflowStage::query()->count())->toBe(2);

    $workflow->forceDelete();

    expect(ApprovalWorkflowStage::query()->count())->toBe(0);
});

it('keeps a request when the workflow that produced it is deleted', function () {
    $workflow = workflow([['name' => 'Manager']]);
    $request = approvalRequest(attributes: ['workflow_id' => $workflow->getKey()]);

    $workflow->forceDelete();

    // The steps hold everything needed to finish, so losing the definition
    // must not lose the history.
    expect($request->fresh())->not->toBeNull()
        ->and($request->fresh()?->getAttribute('workflow_id'))->toBeNull();
});

it('refuses to take the audit trail down with a request', function () {
    $request = approvalRequest();
    $stage = $request->stages()->create(['sequence' => 1, 'name' => 'Manager']);
    $stage->assignments()->create([
        'approver_type' => (new User)->getMorphClass(),
        'approver_id' => user()->getKey(),
    ]);
    $request->actions()->create(['action' => 'submitted', 'hash' => str_repeat('0', 64)]);

    // The trail outlives everything: a request with history cannot be
    // hard-deleted, so erasing it takes two deliberate acts, not one cascade.
    expect(fn () => $request->delete())->toThrow(QueryException::class)
        ->and(ApprovalAction::query()->count())->toBe(1);

    // Erasing history is done against the trail itself, on purpose, with a
    // query - the model refuses. Only then may the request go, taking its
    // steps and assignments with it.
    ApprovalAction::query()->where('request_id', $request->getKey())->delete();
    $request->delete();

    expect(ApprovalRequestStage::query()->count())->toBe(0)
        ->and(ApprovalRequestAssignment::query()->count())->toBe(0)
        ->and(ApprovalAction::query()->count())->toBe(0);
});

it('creates its tables under whatever names the config gives them', function () {
    $renamed = [
        'workflows' => 'sign_off_flows',
        'workflow_stages' => 'sign_off_flow_stages',
        'workflow_stage_approvers' => 'sign_off_flow_stage_approvers',
        'requests' => 'sign_off_requests',
        'request_stages' => 'sign_off_request_stages',
        'assignments' => 'sign_off_request_assignments',
        'actions' => 'sign_off_actions',
        'attachments' => 'sign_off_attachments',
        'delegations' => 'sign_off_delegations',
    ];

    config()->set('approval.table_names', $renamed);

    $this->runApprovalMigration();

    foreach ($renamed as $name) {
        expect(Schema::hasTable($name))->toBeTrue("Missing renamed table [{$name}].");
    }

    // The models have to follow the rename, or the config is a lie that only
    // surfaces at query time.
    expect((new ApprovalWorkflow)->getTable())->toBe('sign_off_flows')
        ->and((new ApprovalRequest)->getTable())->toBe('sign_off_requests')
        ->and((new ApprovalAction)->getTable())->toBe('sign_off_actions');

    $workflow = ApprovalWorkflow::create([
        'name' => 'Renamed',
        'slug' => 'renamed',
        'active' => true,
    ]);

    expect(ApprovalWorkflow::query()->whereKey($workflow->getKey())->exists())->toBeTrue();
});

it('falls back to the packaged table name when the config has no entry for it', function () {
    config()->set('approval.table_names', []);

    expect(TableNames::for('requests'))->toBe('approval_requests');
});
