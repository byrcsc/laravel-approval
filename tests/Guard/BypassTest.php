<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\ApprovalGuardException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\ApprovalGuard;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Support\Facades\DB;

/**
 * The guard is static, process-local state. These are the attacks on it: what
 * leaks out of a bypass, what slips past the observer, and what the counter
 * does when the stack unwinds the hard way.
 */
beforeEach(function (): void {
    config()->set('approval.models.'.Post::class, ['mode' => 'guard']);
});

/**
 * A guard-mode post with `title` held in a pending draft.
 *
 * @return array{Post, ApprovalRequest, User}
 */
function guardedPost(): array
{
    $approver = user('Approver');
    $post = post();
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved title'],
        user('Author'),
    );

    return [$post, $request, $approver];
}

function bypassLines(ApprovalRequest $request): int
{
    return $request->actions()->where('action', ActionType::GuardBypassed->value)->count();
}

it('keeps the guard lifted when the inner of two nested bypasses closes', function (): void {
    [$post, $request] = guardedPost();

    $post->withoutApprovalGuard(function (Post $post): void {
        $post->withoutApprovalGuard(function (Post $post): void {
            $post->update(['title' => 'Inner']);
        });

        // The inner scope has closed. If the counter were a boolean this
        // would throw.
        $post->update(['title' => 'Outer']);
    });

    expect($post->fresh()?->title)->toBe('Outer')
        ->and(ApprovalGuard::bypassing($post))->toBeFalse()
        ->and(bypassLines($request))->toBe(2);
});

it('re-arms the guard when a bypass throws', function (): void {
    [$post, $request] = guardedPost();

    expect(fn () => $post->withoutApprovalGuard(function (Post $post): void {
        $post->update(['title' => 'Half done']);

        throw new RuntimeException('the correction failed');
    }))->toThrow(RuntimeException::class, 'the correction failed');

    // The bypass runs in a transaction, so the write and its own audit line
    // go back together.
    expect($post->fresh()?->title)->toBe('A draft post')
        ->and(bypassLines($request))->toBe(0)
        ->and(ApprovalGuard::bypassing($post))->toBeFalse();

    $post->title = 'After the failure';

    expect(fn () => $post->save())->toThrow(ApprovalGuardException::class, 'title');
});

it('leaves no bypass behind when the surrounding transaction rolls back', function (): void {
    [$post, $request] = guardedPost();

    try {
        DB::transaction(function () use ($post): void {
            $post->withoutApprovalGuard(function (Post $post): void {
                $post->update(['title' => 'Rolled back']);
            });

            throw new RuntimeException('the outer unit of work failed');
        });
    } catch (RuntimeException) {
        // The rollback is the subject; the exception is just how it arrives.
    }

    expect(ApprovalGuard::bypassing($post))->toBeFalse()
        ->and($post->fresh()?->title)->toBe('A draft post')
        ->and(bypassLines($request))->toBe(0);

    $post->title = 'After the rollback';

    expect(fn () => $post->save())->toThrow(ApprovalGuardException::class, 'title');
});

it('lifts the guard for every instance of the same row', function (): void {
    [$post, $request] = guardedPost();
    $twin = Post::query()->findOrFail($post->getKey());

    // The bypass is keyed by row, not by object, so the write can go through
    // whichever instance the caller happens to be holding.
    $post->withoutApprovalGuard(function () use ($twin): void {
        $twin->update(['title' => 'Written through the twin']);
    });

    $action = $request->actions()->where('action', ActionType::GuardBypassed->value)->sole();

    expect($post->fresh()?->title)->toBe('Written through the twin')
        ->and($action->metadata)->toBe(['attributes' => ['title']]);
});

it('leaves other records guarded while one record is bypassed', function (): void {
    [$post] = guardedPost();
    [$other] = guardedPost();

    $post->withoutApprovalGuard(function () use ($other): void {
        $other->title = 'Sneaked in alongside';

        expect(fn () => $other->save())->toThrow(ApprovalGuardException::class, 'title');
    });

    expect($other->fresh()?->title)->toBe('A draft post');
});

it('guards forceFill exactly as it guards a plain save', function (): void {
    [$post, $request] = guardedPost();

    expect(fn () => $post->forceFill(['title' => 'Forced'])->save())
        ->toThrow(ApprovalGuardException::class, 'title')
        ->and($post->fresh()?->title)->toBe('A draft post')
        ->and($request->refresh()->state)->toBe(RequestState::Pending);
});

it('writes nothing when a mass update mixes a guarded attribute with a free one', function (): void {
    [$post] = guardedPost();

    expect(fn () => $post->update(['title' => 'Guarded', 'body' => 'Free']))
        ->toThrow(ApprovalGuardException::class, 'title');

    $fresh = $post->fresh();

    // The guard throws from `saving`, so the whole statement is abandoned -
    // the free attribute does not sneak through on the back of the refusal.
    expect($fresh?->title)->toBe('A draft post')
        ->and($fresh?->body)->toBe('Body copy.');
});

it('documents that saveQuietly slips past the guard and lands on the stale check', function (): void {
    [$post, $request, $approver] = guardedPost();

    // `saveQuietly` suppresses model events, so the observer never runs. Same
    // class of hole as `query()->update()`, and the same backstop.
    $post->title = 'Quietly changed';
    $post->saveQuietly();

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted)
        ->and($post->fresh()?->title)->toBe('Quietly changed');
});

it('guards nothing when the request gates the record rather than an edit', function (): void {
    $post = post();
    $post->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Author'),
    );

    // Nothing is drafted, so nothing is held back.
    $post->update(['title' => 'Edited while gated']);

    expect($post->fresh()?->title)->toBe('Edited while gated');
});

it('records a bypass that names no actor', function (): void {
    [$post, $request] = guardedPost();

    $post->withoutApprovalGuard(function (Post $post): void {
        $post->update(['title' => 'Anonymous correction']);
    });

    $action = $request->actions()->where('action', ActionType::GuardBypassed->value)->sole();

    expect($action->actor)->toBeNull()
        ->and($action->comment)->toBeNull()
        ->and($action->metadata)->toBe(['attributes' => ['title']]);
});

it('records a bypass that touched nothing drafted', function (): void {
    [$post, $request] = guardedPost();

    $post->withoutApprovalGuard(function (Post $post): void {
        $post->update(['body' => 'A side edit that needed no bypass at all']);
    });

    $action = $request->actions()->where('action', ActionType::GuardBypassed->value)->sole();

    expect($action->metadata)->toBe(['attributes' => []]);
});

it('runs a bypass with no open request as a plain callback', function (): void {
    $post = post();

    $result = $post->withoutApprovalGuard(function (Post $post): string {
        $post->update(['title' => 'No request to bypass']);

        return 'ran';
    });

    expect($result)->toBe('ran')
        ->and($post->fresh()?->title)->toBe('No request to bypass')
        ->and(ApprovalAction::query()->where('action', ActionType::GuardBypassed->value)->count())->toBe(0);
});

it('audits a bypass that deletes the record out from under the request', function (): void {
    [$post, $request] = guardedPost();

    $post->withoutApprovalGuard(function (Post $post): void {
        $post->delete();
    });

    $action = $request->actions()->where('action', ActionType::GuardBypassed->value)->sole();

    // The record is gone, which is not the same thing as "changed nothing".
    expect(Post::query()->whereKey($post->getKey())->exists())->toBeFalse()
        ->and(canonical($action->metadata))->toBe(canonical(['attributes' => ['title'], 'deleted' => true]));
});

it('files a bypass under object identity while the model has no key', function (): void {
    $unsaved = new Post(['title' => 'Unsaved', 'body' => 'Body copy.']);
    $other = new Post(['title' => 'Also unsaved', 'body' => 'Body copy.']);

    ApprovalGuard::without($unsaved, function () use ($unsaved, $other): void {
        expect(ApprovalGuard::bypassing($unsaved))->toBeTrue()
            ->and(ApprovalGuard::bypassing($other))->toBeFalse();
    });

    expect(ApprovalGuard::bypassing($unsaved))->toBeFalse();
});

it('keeps hold of a model that gains its key inside its own bypass', function (): void {
    $post = new Post(['title' => 'Unsaved', 'body' => 'Body copy.']);

    ApprovalGuard::without($post, function () use ($post): void {
        $post->save();

        // The entry was filed under object identity, and the model now answers
        // to a primary key as well. Both are registered, so the insert does not
        // drop it out of its own bypass halfway through.
        expect(ApprovalGuard::bypassing($post))->toBeTrue();
    });

    expect(ApprovalGuard::bypassing($post))->toBeFalse();
});

it('keeps an object id from colliding with a real key of the same class', function (): void {
    [$post] = guardedPost();
    $unsaved = new Post(['title' => 'Unsaved', 'body' => 'Body copy.']);

    // The two namespaces are separate, so no object id can stand in for the
    // primary key of a real row.
    ApprovalGuard::without($unsaved, function () use ($post): void {
        expect(ApprovalGuard::bypassing($post))->toBeFalse();
    });
});

it('permits direct edits while a request sits returned', function (): void {
    [$post, $request, $approver] = guardedPost();

    Approval::returnToSubmitter($request, $approver, 'Fix the title and send it back');

    // A returned request is closed, and the guard only ever claimed to hold
    // while one is in flight. Going and fixing the record is the whole point
    // of returning it.
    $post->update(['title' => 'Fixed by the submitter']);

    expect($post->fresh()?->title)->toBe('Fixed by the submitter')
        ->and($request->refresh()->state)->toBe(RequestState::Returned);
});

it('resumes a returned request from a snapshot taken after the submitter edited', function (): void {
    [$post, $request, $approver] = guardedPost();

    Approval::returnToSubmitter($request, $approver, 'Fix the title and send it back');

    $post->update(['title' => 'Fixed by the submitter']);

    $replacement = Approval::resume($request->refresh());

    Approval::approve($replacement, $approver);

    // Two halves of one decision. The fresh snapshot means the submitter's own
    // edit is not drift, so the draft applies rather than conflicting - and
    // because the draft is unchanged, applying it puts the *approved* value
    // back over the fix. A submitter who edits directly resumes with
    // `changes`; see the test below.
    expect($replacement->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->title)->toBe('Approved title');
});

it('carries a direct fix forward when the resume names it as a change', function (): void {
    [$post, $request, $approver] = guardedPost();

    Approval::returnToSubmitter($request, $approver, 'Fix the title and send it back');

    $post->update(['title' => 'Fixed by the submitter']);

    $replacement = Approval::resume($request->refresh(), ['title' => 'Fixed by the submitter']);

    Approval::approve($replacement, $approver);

    expect($replacement->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->title)->toBe('Fixed by the submitter');
});
