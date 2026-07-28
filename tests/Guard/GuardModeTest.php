<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\ApprovalGuardException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;

beforeEach(function (): void {
    config()->set('approval.models.'.Post::class, ['mode' => 'guard']);
});

it('rejects Eloquent writes to attributes held in a pending draft', function (): void {
    $approver = user('Approver');
    $post = post();
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    $post->title = 'Bypassed title';

    expect(fn () => $post->save())
        ->toThrow(ApprovalGuardException::class, 'title')
        ->and($request->refresh()->state)->toBe(RequestState::Pending)
        ->and($post->fresh()?->title)->toBe('A draft post');
});

it('allows unrelated attributes to be saved while a draft is pending', function (): void {
    $post = post();
    $post->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    $post->update(['body' => 'Safe side edit']);

    expect($post->fresh()?->body)->toBe('Safe side edit');
});

it('audits the explicit guard bypass', function (): void {
    $post = post();
    $actor = user('Administrator');
    $request = $post->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    $result = $post->withoutApprovalGuard(function (Post $post): string {
        $post->update(['title' => 'Corrected title']);

        return 'done';
    }, $actor, 'Emergency correction');

    $action = $request->actions()->where('action', ActionType::GuardBypassed->value)->sole();

    expect($result)->toBe('done')
        ->and($post->fresh()?->title)->toBe('Corrected title')
        ->and($action->actor?->is($actor))->toBeTrue()
        ->and($action->comment)->toBe('Emergency correction')
        ->and($action->metadata)->toBe(['attributes' => ['title']]);
});

it('lets the engine apply an approved draft without recording a bypass', function (): void {
    $approver = user('Approver');
    $post = post();
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->title)->toBe('Approved title')
        ->and($request->actions()->where('action', ActionType::GuardBypassed->value)->count())->toBe(0);
});

it('documents the observer boundary by leaving query updates to the stale check', function (): void {
    $approver = user('Approver');
    $post = post();
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    Post::query()->whereKey($post->getKey())->update(['title' => 'Raw path']);
    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted)
        ->and($post->fresh()?->title)->toBe('Raw path');
});
