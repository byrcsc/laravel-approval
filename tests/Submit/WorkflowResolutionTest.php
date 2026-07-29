<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Exceptions\MissingSubmitterException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;

it('finds a workflow by slug', function (): void {
    $workflow = workflow([], ['slug' => 'expenses']);

    expect(Approval::workflow('expenses')->is($workflow))->toBeTrue()
        ->and(Approval::findWorkflow('expenses')?->is($workflow))->toBeTrue();
});

it('reports a slug nobody registered', function (): void {
    expect(Approval::findWorkflow('nope'))->toBeNull();

    expect(fn () => Approval::workflow('nope'))
        ->toThrow(WorkflowNotFoundException::class, 'No approval workflow is registered under the slug [nope]');
});

it('takes the workflow passed to the call first', function (): void {
    $passed = workflow([], ['slug' => 'passed-in']);
    workflow([], ['slug' => 'purchase-order']);

    expect(Approval::workflowFor(purchaseOrder(), $passed)->is($passed))->toBeTrue();
});

it('accepts a slug in place of the workflow', function (): void {
    $workflow = workflow([], ['slug' => 'passed-in']);

    expect(Approval::workflowFor(post(), 'passed-in')->is($workflow))->toBeTrue();
});

it('falls back to the workflow the model declares', function (): void {
    $declared = workflow([], ['slug' => 'purchase-order']);

    expect(Approval::workflowFor(purchaseOrder())->is($declared))->toBeTrue();
});

it('falls back to the config map when the model declares nothing', function (): void {
    $mapped = workflow([], ['slug' => 'editorial']);
    config()->set('approval.workflows', [Post::class => 'editorial']);

    expect(Approval::workflowFor(post())->is($mapped))->toBeTrue();
});

it('lets the model beat the config map', function (): void {
    $declared = workflow([], ['slug' => 'purchase-order']);
    workflow([], ['slug' => 'from-config']);
    config()->set('approval.workflows', [PurchaseOrder::class => 'from-config']);

    expect(Approval::workflowFor(purchaseOrder())->is($declared))->toBeTrue();
});

it('gives up when no source names a workflow', function (): void {
    expect(fn () => Approval::workflowFor(post()))
        ->toThrow(WorkflowNotFoundException::class, 'No approval workflow could be resolved');
});

it('reports a declared slug that does not exist', function (): void {
    PurchaseOrder::$workflowSlug = 'never-created';

    expect(fn () => Approval::workflowFor(purchaseOrder()))
        ->toThrow(WorkflowNotFoundException::class, '[never-created]');
});

it('takes the submitter the model names', function (): void {
    $buyer = user('Buyer');
    PurchaseOrder::$actingSubmitter = $buyer;

    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    $request = purchaseOrder()->submitForApproval();

    expect($request->submitter?->getKey())->toBe($buyer->getKey());
});

it('takes the submitter passed to the call over the one the model names', function (): void {
    PurchaseOrder::$actingSubmitter = user('Declared');
    $explicit = user('Explicit');

    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    $request = purchaseOrder()->submitForApproval(null, null, $explicit);

    expect($request->submitter?->getKey())->toBe($explicit->getKey());
});

it('falls back to the authenticated user', function (): void {
    $actor = user('Signed In');
    $this->actingAs($actor);

    $workflow = workflow([['approvers' => [user()]]]);

    expect(post()->submitForApproval($workflow)->submitter?->getKey())->toBe($actor->getKey());
});

it('refuses to guess a submitter when nobody is signed in', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);

    expect(fn () => post()->submitForApproval($workflow))
        ->toThrow(MissingSubmitterException::class, 'No submitter could be determined');
});
