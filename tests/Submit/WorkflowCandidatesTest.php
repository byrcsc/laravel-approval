<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Exceptions\AmbiguousWorkflowException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\AmountAboveWorkflowCondition;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\RequestedByWorkflowCondition;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * More than one workflow per model: the `approvable_type` column registers a
 * workflow against a type, a condition decides whether it applies to the
 * submission in hand, and priority orders whatever is left.
 *
 * The stub declares a workflow of its own, which would win every time. These
 * tests are about the ones nobody named, so the declaration is cleared and put
 * back only where it is the thing being tested.
 */
beforeEach(function (): void {
    PurchaseOrder::$workflowSlug = '';
});

/**
 * A workflow registered against the purchase order type.
 *
 * @param  array<string, mixed>  $attributes
 */
function registeredWorkflow(string $slug, array $attributes = []): void
{
    workflow([['approvers' => [user('Approver')]]], array_merge([
        'slug' => $slug,
        'approvable_type' => PurchaseOrder::class,
    ], $attributes));
}

it('takes the highest priority workflow registered against the model type', function (): void {
    registeredWorkflow('po-standard');
    registeredWorkflow('po-executive', ['priority' => 10]);

    expect(Approval::workflowFor(purchaseOrder())->getAttributeValue('slug'))->toBe('po-executive');
});

it('leaves out the workflow whose condition rules it out', function (): void {
    registeredWorkflow('po-standard');
    registeredWorkflow('po-executive', [
        'priority' => 10,
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);

    expect(Approval::workflowFor(purchaseOrder('PO-1', 500))->getAttributeValue('slug'))->toBe('po-standard')
        ->and(Approval::workflowFor(purchaseOrder('PO-2', 20_000))->getAttributeValue('slug'))->toBe('po-executive');
});

it('judges the draft rather than the record behind it', function (): void {
    registeredWorkflow('po-standard');
    registeredWorkflow('po-executive', [
        'priority' => 10,
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);

    // The stored amount is under the threshold; the amount being submitted for
    // approval is over it, and that is what the workflow is chosen on.
    $order = purchaseOrder('PO-1', 500);
    $request = $order->submitForApproval(null, ['amount' => 20_000], user('Buyer'));

    expect($request->workflowSlug())->toBe('po-executive')
        ->and($order->fresh()?->getAttributeValue('amount'))->toBe(500);
});

it('lists the workflows a record could be submitted against, best first', function (): void {
    registeredWorkflow('po-standard');
    registeredWorkflow('po-executive', [
        'priority' => 10,
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);
    registeredWorkflow('po-retired', ['priority' => 20, 'active' => false]);

    expect(Approval::workflowsFor(purchaseOrder('PO-1', 20_000))->pluck('slug')->all())
        ->toBe(['po-executive', 'po-standard'])
        ->and(Approval::workflowsFor(purchaseOrder('PO-2', 500))->pluck('slug')->all())
        ->toBe(['po-standard'])
        ->and(Approval::workflowsFor(purchaseOrder('PO-3', 500), null, ['amount' => 20_000])->pluck('slug')->all())
        ->toBe(['po-executive', 'po-standard']);
});

it('lists nothing for a model no workflow is registered against', function (): void {
    registeredWorkflow('po-standard');

    expect(Approval::workflowsFor(post()))->toBeEmpty();
});

it('refuses to choose between two equally specific workflows', function (): void {
    registeredWorkflow('po-one', ['priority' => 5]);
    registeredWorkflow('po-two', ['priority' => 5]);
    registeredWorkflow('po-three');

    expect(fn () => Approval::workflowFor(purchaseOrder()))
        ->toThrow(AmbiguousWorkflowException::class, 'at priority 5: [po-one], [po-two]');
});

it('reports the workflows it considered and ruled out', function (): void {
    registeredWorkflow('po-retired', ['active' => false]);
    registeredWorkflow('po-large', [
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);

    expect(fn () => Approval::workflowFor(purchaseOrder('PO-1', 500)))
        ->toThrow(WorkflowNotFoundException::class, 'Considered and ruled out as inactive or not applicable: [po-retired], [po-large]');
});

it('still reports having found nothing at all', function (): void {
    expect(fn () => Approval::workflowFor(purchaseOrder()))
        ->toThrow(WorkflowNotFoundException::class, 'No approval workflow could be resolved');
});

it('takes the workflow the model declares over one registered against its type', function (): void {
    PurchaseOrder::$workflowSlug = 'declared';
    workflow([['approvers' => [user('Approver')]]], ['slug' => 'declared']);
    registeredWorkflow('po-executive', ['priority' => 100]);

    expect(Approval::workflowFor(purchaseOrder())->getAttributeValue('slug'))->toBe('declared')
        // The dropdown still shows both, most specific first.
        ->and(Approval::workflowsFor(purchaseOrder())->pluck('slug')->all())
        ->toBe(['declared', 'po-executive']);
});

it('takes a config list in the order it is written, skipping what does not apply', function (): void {
    workflow([['approvers' => [user('Approver')]]], [
        'slug' => 'from-config-large',
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);
    workflow([['approvers' => [user('Approver')]]], ['slug' => 'from-config-standard']);
    config()->set('approval.workflows', [
        PurchaseOrder::class => ['from-config-large', 'from-config-standard'],
    ]);

    expect(Approval::workflowFor(purchaseOrder('PO-1', 20_000))->getAttributeValue('slug'))->toBe('from-config-large')
        ->and(Approval::workflowFor(purchaseOrder('PO-2', 500))->getAttributeValue('slug'))->toBe('from-config-standard');
});

it('gives a condition the requester, and nothing when nobody is submitting yet', function (): void {
    registeredWorkflow('po-buyer', [
        'condition' => RequestedByWorkflowCondition::class,
        'condition_config' => ['name' => 'Buyer'],
    ]);

    $buyer = user('Buyer');

    expect(Approval::workflowFor(purchaseOrder(), null, $buyer)->getAttributeValue('slug'))->toBe('po-buyer')
        ->and(RequestedByWorkflowCondition::$sawRequester)->toBeTrue()
        ->and(Approval::workflowsFor(purchaseOrder()))->toBeEmpty()
        ->and(RequestedByWorkflowCondition::$sawRequester)->toBeFalse();
});

it('resolves the requester before the workflow it may decide', function (): void {
    registeredWorkflow('po-buyer', [
        'condition' => RequestedByWorkflowCondition::class,
        'condition_config' => ['name' => 'Buyer'],
    ]);

    PurchaseOrder::$actingRequester = user('Buyer');

    expect(purchaseOrder()->submitForApproval()->workflowSlug())->toBe('po-buyer');
});

it('uses a workflow named at the call site as named', function (): void {
    $retired = workflow([['approvers' => [user('Approver')]]], [
        'slug' => 'retired',
        'active' => false,
        'approvable_type' => PurchaseOrder::class,
        'condition' => AmountAboveWorkflowCondition::class,
        'condition_config' => ['amount' => 10_000],
    ]);

    // Neither the condition nor the active flag is consulted for a workflow
    // the caller named; the submission itself is where inactive is refused.
    expect(Approval::workflowFor(purchaseOrder('PO-1', 500), $retired)->is($retired))->toBeTrue()
        ->and(fn () => purchaseOrder('PO-2', 500)->submitForApproval($retired, null, user('Buyer')))
        ->toThrow(InvalidWorkflowException::class, 'is not active');
});

it('matches a workflow registered under a morph alias', function (): void {
    Relation::morphMap(['purchase-order' => PurchaseOrder::class]);
    registeredWorkflow('po-aliased', ['approvable_type' => 'purchase-order']);

    expect(Approval::workflowFor(purchaseOrder())->getAttributeValue('slug'))->toBe('po-aliased');
});

it('lists every active workflow by name', function (): void {
    workflow([], ['slug' => 'beta', 'name' => 'Beta']);
    workflow([], ['slug' => 'alpha', 'name' => 'Alpha']);
    workflow([], ['slug' => 'retired', 'name' => 'Aardvark', 'active' => false]);

    expect(Approval::workflows()->pluck('slug')->all())->toBe(['alpha', 'beta']);
});

it('refuses a workflow condition that is not one', function (): void {
    registeredWorkflow('po-broken', ['condition' => PurchaseOrder::class]);

    expect(fn () => Approval::workflowFor(purchaseOrder()))
        ->toThrow(InvalidWorkflowException::class, 'does not implement');
});

it('refuses a workflow condition that does not exist', function (): void {
    registeredWorkflow('po-missing', ['condition' => 'App\\Conditions\\Nope']);

    expect(fn () => Approval::workflowFor(purchaseOrder()))
        ->toThrow(InvalidWorkflowException::class, 'which does not exist');
});

it('refuses a definition whose condition is not a workflow condition', function (): void {
    $builder = WorkflowBuilder::make('po-broken')
        ->when(PurchaseOrder::class)
        ->stage('Finance', fn ($stage) => $stage->approver(user('Finance')));

    expect(fn () => $builder->definition())
        ->toThrow(InvalidWorkflowDefinitionException::class, 'does not implement WorkflowCondition');
});

it('registers a workflow against a type from a definition', function (): void {
    $definition = WorkflowBuilder::make('po-executive', 'Executive purchase order')
        ->forApprovable(PurchaseOrder::class)
        ->when(AmountAboveWorkflowCondition::class, ['amount' => 10_000])
        ->priority(10)
        ->stage('Finance', fn ($stage) => $stage->approver(user('Finance')));

    Approval::syncDefinitions([$definition]);

    $workflow = Approval::workflow('po-executive');

    expect($workflow->getAttributeValue('approvable_type'))->toBe(PurchaseOrder::class)
        ->and($workflow->getAttributeValue('condition'))->toBe(AmountAboveWorkflowCondition::class)
        ->and(canonical($workflow->getAttributeValue('condition_config')))->toBe(canonical(['amount' => 10_000]))
        ->and($workflow->getAttributeValue('priority'))->toBe(10)
        ->and(Approval::workflowFor(purchaseOrder('PO-1', 20_000))->is($workflow))->toBeTrue();
});
