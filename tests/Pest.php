<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\Invoice;
use ByRcsc\LaravelApproval\Tests\Stubs\InvoiceStatus;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use ByRcsc\LaravelApproval\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

uses(TestCase::class)->in(__DIR__);

// The stubs carry state so tests can stand in for an org chart and an
// authenticated user. Neither should leak into the next test.
uses()->beforeEach(function (): void {
    HeadOfDepartmentResolver::$heads = [];
    PurchaseOrder::$actingSubmitter = null;
    PurchaseOrder::$workflowSlug = 'purchase-order';
})->in(__DIR__);

/**
 * A workflow, built from a list of level specs.
 *
 * Alongside the level's own columns, a spec may carry `approvers` (models, or
 * `['resolver' => ..., 'resolver_config' => [...]]` entries) which become that
 * level's approver rows.
 *
 * @param  array<int, array<string, mixed>>  $levels
 * @param  array<string, mixed>  $attributes
 */
function workflow(array $levels = [], array $attributes = []): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::create(array_merge([
        'name' => 'Expense approval',
        'slug' => 'expense-approval-'.uniqid(),
        'active' => true,
    ], $attributes));

    $sequence = 1;

    foreach ($levels as $level) {
        $approvers = $level['approvers'] ?? [];
        unset($level['approvers']);

        $created = $workflow->levels()->create(array_merge([
            'sequence' => $sequence++,
            'name' => 'Level '.($sequence - 1),
            'required_approvals' => 1,
        ], $level));

        assert($created instanceof ApprovalWorkflowLevel);
        assert(is_array($approvers));

        foreach ($approvers as $approver) {
            approverRow($created, $approver);
        }
    }

    return $workflow;
}

/**
 * @param  Model|array<string, mixed>  $approver
 */
function approverRow(ApprovalWorkflowLevel $level, Model|array $approver): void
{
    $level->approvers()->create($approver instanceof Model
        ? ['approver_type' => $approver->getMorphClass(), 'approver_id' => $approver->getKey()]
        : $approver);
}

function user(string $name = 'Ada Lovelace'): User
{
    return User::create([
        'name' => $name,
        'email' => str_replace(' ', '.', strtolower($name)).'.'.uniqid().'@example.test',
    ]);
}

function post(string $title = 'A draft post'): Post
{
    return Post::create(['title' => $title, 'body' => 'Body copy.']);
}

function purchaseOrder(string $reference = 'PO-1001', int $amount = 500): PurchaseOrder
{
    return PurchaseOrder::create(['reference' => $reference, 'amount' => $amount]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function invoice(array $attributes = []): Invoice
{
    return Invoice::create(array_merge([
        'reference' => 'INV-2001',
        'issued_on' => '2026-07-01',
        'sent_at' => '2026-07-02 08:30:00',
        'status' => InvoiceStatus::Draft,
        'account_number' => 'GB00 1234 5678',
        'total' => '125.50',
        'rate' => 0.2,
        'quantity' => 3,
        'settled' => false,
        'lines' => [['sku' => 'A1', 'qty' => 2]],
        'meta' => ['source' => 'portal'],
    ], $attributes));
}

/**
 * A minimal request against `$post`, submitted by `$submitter`. Written
 * directly rather than through the engine: tests that are about the schema
 * should not go through the thing being tested on top of it.
 *
 * @param  array<string, mixed>  $attributes
 */
function approvalRequest(?Post $post = null, ?User $submitter = null, array $attributes = []): ApprovalRequest
{
    $post ??= post();
    $submitter ??= user();

    return ApprovalRequest::create(array_merge([
        'approvable_type' => $post->getMorphClass(),
        'approvable_id' => $post->getKey(),
        'submitter_type' => $submitter->getMorphClass(),
        'submitter_id' => $submitter->getKey(),
        'submitted_at' => now(),
    ], $attributes));
}

/**
 * A one-level request with a 60 minute SLA and the given policy.
 *
 * A null `$requiredApprovals` is the all-of-them quorum, which is the case
 * escalation has to be careful with.
 *
 * @param  array<string, mixed>  $escalationConfig
 */
function overdueRequest(
    EscalationAction $strategy = EscalationAction::Notify,
    array $escalationConfig = [],
    ?int $requiredApprovals = 1,
    int $approvers = 1,
): ApprovalRequest {
    $seats = [];

    for ($i = 0; $i < $approvers; $i++) {
        $seats[] = user('Approver '.($i + 1));
    }

    $workflow = workflow([[
        'sla_minutes' => 60,
        'required_approvals' => $requiredApprovals,
        'escalation_strategy' => $strategy,
        'escalation_config' => $escalationConfig === [] ? null : $escalationConfig,
        'approvers' => $seats,
    ]]);

    return post()->submitForApproval($workflow, null, user('Author'));
}
