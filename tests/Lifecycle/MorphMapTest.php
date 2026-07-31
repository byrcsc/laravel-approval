<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Hosts that enforce a morph map store aliases in every polymorphic column,
 * and `getMorphClass()` throws for any model left out of the map. The engine
 * touches six morph pairs - approvable, requester, actor, approver, delegate,
 * and the notification recipients - so an alias that leaks or a class name
 * that sneaks into a column would surface as a request nobody can find.
 */
beforeEach(function (): void {
    Relation::morphMap(['user' => User::class, 'post' => Post::class]);
    Relation::requireMorphMap();
});

afterEach(function (): void {
    Relation::requireMorphMap(false);
    Relation::$morphMap = [];
});

it('runs the whole flow on aliases, never class names', function (): void {
    $approver = user('Approver');
    $delegate = user('Delegate');
    $post = post('Original title');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    expect($request->getAttributeValue('approvable_type'))->toBe('post')
        ->and($request->getAttributeValue('requester_type'))->toBe('user')
        ->and($request->stages()->sole()->assignments()->sole()->getAttributeValue('approver_type'))->toBe('user');

    Approval::delegate($request, $approver, $delegate);
    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title')
        ->and($request->approvable?->is($post))->toBeTrue()
        ->and($request->actions()->where('action', 'approved')->sole()->actor?->is($delegate))->toBeTrue();
});

it('finds the request in the approver inbox through the alias', function (): void {
    $approver = user('Approver');
    $bystander = user('Bystander');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    expect(Approval::awaiting($approver)->get()->sole()->is($request))->toBeTrue()
        ->and(Approval::awaiting($bystander)->get())->toBeEmpty();
});

it('chains a resubmission through aliased columns', function (): void {
    $approver = user('Approver');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reject($request, $approver, 'Not yet.');

    $second = Approval::reviseAndResubmit($request, RevisedDraft::inherit());

    expect($second->getAttributeValue('approvable_type'))->toBe('post')
        ->and($second->resubmittedFrom?->is($request))->toBeTrue()
        ->and($second->state)->toBe(RequestState::Pending);
});
