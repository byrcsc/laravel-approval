<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

it('refuses an inactive workflow', function (): void {
    $workflow = workflow([['approvers' => [user()]]], ['slug' => 'retired', 'active' => false]);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'The approval workflow [retired] is not active.');
});

it('refuses a workflow with no levels', function (): void {
    $workflow = workflow([], ['slug' => 'empty']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'has no levels');
});

it('refuses a level nobody would be asked to approve', function (): void {
    $workflow = workflow([
        ['approvers' => [user()]],
        ['approvers' => []],
    ], ['slug' => 'gappy']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'Level 2 of the approval workflow [gappy] resolved to no approvers');
});

it('refuses a level whose resolver comes back empty', function (): void {
    HeadOfDepartmentResolver::$heads = [];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ], ['slug' => 'orphaned']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'resolved to no approvers');
});

it('refuses a quorum larger than the level can ever reach', function (): void {
    $workflow = workflow([
        ['required_approvals' => 3, 'approvers' => [user(), user('Second')]],
    ], ['slug' => 'overreaching']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'approval rule requires 3 approvals but only 2 assignments were resolved');
});

it('counts duplicated approvers once when checking the quorum', function (): void {
    $approver = user();

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [$approver, $approver]],
    ], ['slug' => 'double-counted']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'approval rule requires 2 approvals but only 1 assignments were resolved');
});

it('refuses a quorum of zero rather than reading it as none needed', function (): void {
    $workflow = workflow([
        ['required_approvals' => 0, 'approvers' => [user()]],
    ], ['slug' => 'rubber-stamp']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'minimum approvals must be positive');
});

it('reads a null quorum as all of them', function (): void {
    $workflow = workflow([
        ['required_approvals' => null, 'approvers' => [user(), user('Second'), user('Third')]],
    ]);

    $step = post()->submitForApproval($workflow, null, user())->steps()->sole();

    expect($step->getAttributeValue('required_approvals'))->toBeNull()
        ->and($step->approverSlots()->count())->toBe(3);
});

it('refuses an approver entry that is both a record and a rule', function (): void {
    $workflow = workflow([], ['slug' => 'ambiguous']);
    $approver = user();

    $workflow->levels()->create(['sequence' => 1, 'name' => 'Level 1', 'required_approvals' => 1]);

    approverRow($workflow->levels()->sole(), [
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'resolver' => HeadOfDepartmentResolver::class,
    ]);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'sets both a concrete approver and a resolver');
});

it('refuses an approver entry that is neither', function (): void {
    $workflow = workflow([['approvers' => [[]]]], ['slug' => 'blank']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'sets neither a concrete approver nor a resolver');
});

it('refuses a resolver class that does not exist', function (): void {
    $workflow = workflow([
        ['approvers' => [['resolver' => 'App\\Nope']]],
    ], ['slug' => 'missing-resolver']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'names the resolver [App\\Nope], which does not exist');
});

it('refuses a class that is not a resolver', function (): void {
    $workflow = workflow([
        ['approvers' => [['resolver' => ApprovalRequest::class]]],
    ], ['slug' => 'wrong-resolver']);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'which does not implement');
});

it('refuses a resolver that hands back an unsaved model', function (): void {
    HeadOfDepartmentResolver::$heads = [new User(['name' => 'Ghost'])];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'has not been saved');
});

it('refuses a concrete approver that no longer exists', function (): void {
    $approver = user('Deleted approver');
    $workflow = workflow([['approvers' => [$approver]]]);
    $approver->delete();

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class, 'points at an approver that does not exist');

    expect(ApprovalRequest::query()->count())->toBe(0);
});
