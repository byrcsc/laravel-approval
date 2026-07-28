<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use ByRcsc\LaravelApproval\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A workflow with `$levels` levels, each holding one approver.
 *
 * @param  array<int, array<string, mixed>>  $levels
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
        $workflow->levels()->create(array_merge([
            'sequence' => $sequence++,
            'name' => 'Level '.($sequence - 1),
            'required_approvals' => 1,
        ], $level));
    }

    return $workflow;
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

/**
 * A minimal request against `$post`, submitted by `$submitter`.
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
