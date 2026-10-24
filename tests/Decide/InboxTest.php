<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Facades\Approval;

it('lists the requests waiting on a person', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);

    $mine = post('Mine')->submitForApproval($workflow, null, user());
    post('Not mine')->submitForApproval(
        workflow([['approvers' => [user('Someone Else')]]]),
        null,
        user(),
    );

    expect(Approval::awaiting($approver)->pluck('id')->all())->toBe([$mine->getKey()]);
});

it('leaves a request off the list until the stage is theirs', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    expect(Approval::awaiting($second)->count())->toBe(0);

    Approval::approve($request, $first);

    expect(Approval::awaiting($second)->count())->toBe(1)
        ->and(Approval::awaiting($first)->count())->toBe(0);
});

it('drops a request off the list once the person has decided', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    expect(Approval::awaiting($one)->count())->toBe(1);

    Approval::approve($request, $one);

    expect(Approval::awaiting($one)->count())->toBe(0)
        ->and(Approval::awaiting($two)->count())->toBe(1);
});

it('drops closed requests off the list entirely', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = post()->submitForApproval($workflow, null, user());
    Approval::reject($request, $approver, 'No.');

    expect(Approval::awaiting($approver)->count())->toBe(0);
});
