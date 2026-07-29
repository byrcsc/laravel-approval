<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalResumed;
use ByRcsc\LaravelApproval\Events\ApprovalReturned;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\ApprovalInProgressException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\ResumedNoticeNotification;
use ByRcsc\LaravelApproval\Tests\Stubs\ReturnedNoticeNotification;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Support\Facades\Notification;

// The return/resume lifecycle against the rest of the engine: closed-state
// guards, the one-in-flight rule, vanished subjects and workflows, chains,
// scopes, and the notification map.

/**
 * A one-approver request over `amount`, already returned to its submitter.
 *
 * @return array{ApprovalRequest, PurchaseOrder, User}
 */
function returnedRequest(): array
{
    $approver = user('Approver');
    $order = purchaseOrder();
    $request = $order->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 900], user('Author'));

    Approval::returnToSubmitter($request, $approver, 'Correct the total.');

    return [$request->refresh(), $order, $approver];
}

it('refuses to return a conflicted request', function (): void {
    $approver = user('Approver');
    $order = purchaseOrder(amount: 500);
    $request = $order->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 700], user('Author'));

    // The record moves underneath the request, so the approval conflicts.
    $order->newQuery()->whereKey($order->getKey())->update(['amount' => 999]);
    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted)
        ->and(fn () => Approval::returnToSubmitter($request, $approver, 'Send it back.'))
        ->toThrow(AlreadyDecidedException::class, 'conflicted');
});

it('stops a second resume through the one-in-flight rule', function (): void {
    [$returned] = returnedRequest();

    $replacement = Approval::resume($returned, ['amount' => 750]);

    expect(fn () => Approval::resume($returned))
        ->toThrow(ApprovalInProgressException::class)
        ->and(ApprovalRequest::query()->count())->toBe(2)
        ->and($replacement->refresh()->state)->toBe(RequestState::Pending)
        ->and($returned->refresh()->state)->toBe(RequestState::Returned);
});

it('refuses to resume when the record under approval is gone', function (): void {
    [$returned, $order] = returnedRequest();

    $order->delete();

    expect(fn () => Approval::resume($returned))
        ->toThrow(AlreadyDecidedException::class, 'no longer exists');
});

it('reports a soft-deleted workflow slug as an approval exception on resume', function (): void {
    [$returned] = returnedRequest();

    $workflow = $returned->workflow;
    $slug = $workflow?->getAttributeValue('slug');
    $workflow?->delete();

    expect(fn () => Approval::resume($returned, workflow: $slug))
        ->toThrow(WorkflowNotFoundException::class, 'No approval workflow is registered');
});

it('refuses to resume implicitly under a workflow that has been deleted', function (): void {
    [$returned] = returnedRequest();

    $returned->workflow?->delete();

    expect(fn () => Approval::resume($returned))
        ->toThrow(WorkflowNotFoundException::class, 'no longer exists');
});

it('reports an unknown workflow slug as an approval exception on resubmit', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 900], user('Author'));

    Approval::reject($request, $approver, 'No.');

    expect(fn () => Approval::resubmit($request, workflow: 'never-registered'))
        ->toThrow(WorkflowNotFoundException::class, 'never-registered');
});

it('refuses changes on resume when the returned request gated the record itself', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Author'));

    Approval::returnToSubmitter($request, $approver, 'Not yet.');

    expect(fn () => Approval::resume($request, ['amount' => 100]))
        ->toThrow(InvalidDraftException::class)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});

it('traces a return, resume, return chain through resubmitted_from_id', function (): void {
    $approver = user('Approver');
    $order = purchaseOrder();
    $first = $order->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 900], user('Author'));

    Approval::returnToSubmitter($first, $approver, 'Once.');
    $second = Approval::resume($first, ['amount' => 800]);

    Approval::returnToSubmitter($second, $approver, 'Twice.');
    $third = Approval::resume($second, ['amount' => 700]);

    expect($third->resubmittedFrom?->is($second))->toBeTrue()
        ->and($second->refresh()->resubmittedFrom?->is($first))->toBeTrue()
        ->and($first->refresh()->resubmittedFrom)->toBeNull()
        ->and($first->resubmissions()->sole()->is($second))->toBeTrue()
        ->and([$first->state, $second->state, $third->refresh()->state])->toBe([
            RequestState::Returned,
            RequestState::Returned,
            RequestState::Pending,
        ]);
});

it('drops a returned request out of every open-work scope', function (): void {
    $approver = user('Approver');
    $order = purchaseOrder();
    $request = $order->submitForApproval(workflow([[
        'sla_minutes' => 60,
        'approvers' => [$approver],
    ]]), ['amount' => 900], user('Author'));

    expect(Approval::awaiting($approver)->count())->toBe(1);

    Approval::returnToSubmitter($request, $approver, 'Fix it first.');
    $this->travel(2)->hours();

    expect(Approval::awaiting($approver)->count())->toBe(0)
        ->and(Approval::overdue()->count())->toBe(0)
        ->and(Approval::pendingFor($order))->toBeNull()
        ->and($order->isPendingApproval())->toBeFalse();
});

it('notifies through a host-mapped ApprovalReturned entry', function (): void {
    test()->rebootWith([
        'approval.notifications.enabled' => true,
        'approval.notifications.map' => [ApprovalReturned::class => ReturnedNoticeNotification::class],
    ]);
    Notification::fake();

    $approver = user('Approver');
    $author = user('Author');
    $request = purchaseOrder()->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 900], $author);

    Approval::returnToSubmitter($request, $approver, 'Correct the total.');

    Notification::assertSentTo($author, ReturnedNoticeNotification::class);
    Notification::assertNotSentTo($approver, ReturnedNoticeNotification::class);
});

it('notifies through a host-mapped ApprovalResumed entry', function (): void {
    test()->rebootWith([
        'approval.notifications.enabled' => true,
        'approval.notifications.map' => [ApprovalResumed::class => ResumedNoticeNotification::class],
    ]);
    Notification::fake();

    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(workflow([['approvers' => [$approver]]]), ['amount' => 900], user('Author'));

    Approval::returnToSubmitter($request, $approver, 'Correct the total.');
    Approval::resume($request, ['amount' => 750]);

    Notification::assertSentTo($approver, ResumedNoticeNotification::class);
});
