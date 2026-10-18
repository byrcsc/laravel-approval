<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

it('runs against an empty database', function (): void {
    $this->artisan('approval:status')->assertSuccessful();
});

it('counts requests by state', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);

    $approved = post('One')->submitForApproval($workflow, null, user());
    Approval::approve($approved, $approver);

    $rejected = post('Two')->submitForApproval($workflow, null, user());
    Approval::reject($rejected, $approver, 'No.');

    post('Three')->submitForApproval($workflow, null, user());

    expect(Artisan::call('approval:status'))->toBe(0);

    // Every state is listed, so the count beside each one is what matters.
    expect(Artisan::output())
        ->toMatch('/^\s*pending \.+ 1\s*$/m')
        ->toMatch('/^\s*approved \.+ 1\s*$/m')
        ->toMatch('/^\s*rejected \.+ 1\s*$/m')
        ->toMatch('/^\s*withdrawn \.+ 0\s*$/m');
});

it('lists the overdue requests', function (): void {
    Date::setTestNow('2026-07-28 09:00:00');

    $request = overdueRequest();

    Date::setTestNow('2026-07-28 12:00:00');

    $this->artisan('approval:status')
        ->expectsOutputToContain('Overdue')
        ->expectsOutputToContain((string) $request->getAttributeValue('ulid'))
        ->assertSuccessful();

    Date::setTestNow();
});

it('lists the conflicted requests', function (): void {
    $approver = user('Approver');
    $post = post('Original title');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());
    $post->forceFill(['title' => 'Moved'])->save();
    Approval::approve($request, $approver);

    $this->artisan('approval:status')
        ->expectsOutputToContain('Conflicted')
        ->expectsOutputToContain((string) $request->getAttributeValue('ulid'))
        ->assertSuccessful();
});
