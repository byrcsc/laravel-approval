<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\CustomApprovalAction;
use ByRcsc\LaravelApproval\Tests\Stubs\CustomApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

it('uses configured subclasses for writes, queries, and relations', function (): void {
    config()->set('approval.model_classes', [
        'requests' => CustomApprovalRequest::class,
        'actions' => CustomApprovalAction::class,
    ]);

    $approver = user('Approver');
    $post = post();
    $request = $post->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    expect($request)->toBeInstanceOf(CustomApprovalRequest::class)
        ->and(Approval::awaiting($approver)->sole())->toBeInstanceOf(CustomApprovalRequest::class)
        ->and($post->approvalRequests()->sole())->toBeInstanceOf(CustomApprovalRequest::class)
        ->and($request->stages()->sole()->request)->toBeInstanceOf(CustomApprovalRequest::class)
        ->and($request->actions()->sole())->toBeInstanceOf(CustomApprovalAction::class);
});

it('keeps the append-only guard on a subclassed action', function (): void {
    config()->set('approval.model_classes.actions', CustomApprovalAction::class);

    $request = post()->submitForApproval(workflow([['approvers' => [user('Approver')]]]), null, user());
    $action = $request->actions()->sole();

    expect($action)->toBeInstanceOf(CustomApprovalAction::class)
        ->and(fn () => $action->update(['comment' => 'Rewritten.']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $action->delete())->toThrow(LogicException::class, 'append-only');
});

it('refuses to boot with a class that does not extend the package model', function (array $classes, string $message): void {
    $this->bootConfig = ['approval.model_classes' => $classes];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not a subclass' => [['requests' => User::class], 'does not extend'],
    'unknown key' => [['approvals' => CustomApprovalRequest::class], 'not a package model'],
]);
