<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\ApprovalInteraction;
use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Testing\ApprovalFake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The retained public mutations, each paired with the request state it needs and
 * a single call site. Every mutation is run twice: once against the real manager
 * to prove the call is valid, and once against the fake to prove it is recorded
 * and writes nothing.
 *
 * @return array<string, array{state: string, run: callable(array<string, mixed>): mixed}>
 */
function retainedMutations(): array
{
    return [
        'submit' => ['state' => 'pending', 'run' => fn (array $it) => Approval::submit(
            $it['fresh'],
            $it['workflow'],
            ['title' => 'Drafted title'],
            $it['requester'],
            'Please review',
            ['source' => 'contract-test'],
        )],
        'approve' => ['state' => 'pending', 'run' => fn (array $it) => Approval::approve($it['request'], $it['approver'], 'Fine')],
        'reject' => ['state' => 'pending', 'run' => fn (array $it) => Approval::reject($it['request'], $it['approver'], 'No')],
        'returnForRevision' => ['state' => 'pending', 'run' => fn (array $it) => Approval::returnForRevision($it['request'], $it['approver'], 'Revise')],
        'approveMany' => ['state' => 'pending', 'run' => fn (array $it) => Approval::approveMany([$it['request']], $it['approver'])],
        'rejectMany' => ['state' => 'pending', 'run' => fn (array $it) => Approval::rejectMany([$it['request']], $it['approver'], 'No')],
        'decideMany' => ['state' => 'pending', 'run' => fn (array $it) => Approval::decideMany(
            [$it['request']],
            fn (): ApprovalDecision => ApprovalDecision::approve($it['approver']),
        )],
        'withdraw' => ['state' => 'pending', 'run' => fn (array $it) => Approval::withdraw($it['request'], $it['requester'], 'Not yet')],
        'delegate' => ['state' => 'pending', 'run' => fn (array $it) => Approval::delegate($it['request'], $it['approver'], $it['delegate'], 'Away')],
        'attach' => ['state' => 'pending', 'run' => fn (array $it) => Approval::attach(new AttachmentEvidence(
            request: $it['request'],
            disk: 'local',
            path: 'evidence/quote.pdf',
            checksum: str_repeat('a', 64),
            name: 'quote.pdf',
            uploader: $it['requester'],
        ))],
        'cancel' => ['state' => 'pending', 'run' => fn (array $it) => Approval::cancel($it['request'], $it['operator'], 'Handled by phone')],
        'reassign' => ['state' => 'pending', 'run' => fn (array $it) => Approval::reassign($it['request'], $it['assignment'], $it['delegate'], $it['operator'])],
        'resync' => ['state' => 'pending', 'run' => fn (array $it) => Approval::resync($it['request'], $it['operator'])],
        'withoutGuard' => ['state' => 'pending', 'run' => fn (array $it) => Approval::withoutGuard(
            $it['approvable'],
            fn (): string => 'ran',
            $it['operator'],
        )],
        'syncDefinitions' => ['state' => 'pending', 'run' => fn (array $it) => Approval::syncDefinitions([], true)],
        'escalateOverdue' => ['state' => 'pending', 'run' => fn (array $it) => Approval::escalateOverdue()],
        'resolve' => ['state' => 'conflicted', 'run' => fn (array $it) => Approval::resolve($it['request'], ConflictResolution::Discard, $it['operator'])],
        'reviseAndResubmit' => ['state' => 'returned', 'run' => fn (array $it) => Approval::reviseAndResubmit(
            $it['request'],
            RevisedDraft::withAttributes(['title' => 'Revised title']),
            $it['requester'],
        )],
    ];
}

/**
 * A request in the given state, plus the actors and the fresh approvable that
 * the submit case needs.
 *
 * @return array<string, mixed>
 */
function contractFixture(string $state): array
{
    $approver = user('Approver');
    $requester = user('Requester');
    $approvable = post('Contract post');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $approvable->submitForApproval($workflow, ['title' => 'Original title'], $requester);

    if ($state === 'conflicted') {
        $approvable->forceFill(['title' => 'Concurrent edit'])->save();
        Approval::approve($request, $approver);
    }

    if ($state === 'returned') {
        Approval::returnForRevision($request, $approver, 'Revise this.');
    }

    return [
        'approver' => $approver,
        'requester' => $requester,
        'delegate' => user('Delegate'),
        'operator' => user('Operator'),
        'approvable' => $approvable,
        'fresh' => post('Fresh post'),
        'workflow' => $workflow,
        'request' => $request->refresh(),
        'assignment' => $request->stages()->sole()->assignments()->sole(),
    ];
}

it('overrides every public mutation the manager exposes', function (): void {
    $mutating = [
        'submit', 'decide', 'decideMany', 'withdraw', 'delegate', 'reviseAndResubmit',
        'attach', 'cancel', 'reassign', 'resync', 'resolve', 'withoutGuard',
        'syncDefinitions', 'escalateOverdue',
    ];

    foreach ($mutating as $method) {
        $fake = new ReflectionMethod(ApprovalFake::class, $method);
        $real = new ReflectionMethod(ApprovalManager::class, $method);

        expect($fake->getDeclaringClass()->getName())
            ->toBe(ApprovalFake::class, "ApprovalFake must override [{$method}].")
            ->and((string) $fake->getReturnType())
            ->toBe((string) $real->getReturnType(), "ApprovalFake::{$method}() must return the same type.")
            ->and(array_map(fn (ReflectionParameter $p): string => $p->getName().':'.$p->getType(), $fake->getParameters()))
            ->toBe(
                array_map(fn (ReflectionParameter $p): string => $p->getName().':'.$p->getType(), $real->getParameters()),
                "ApprovalFake::{$method}() must accept the same parameters.",
            );
    }
});

it('accepts every retained mutation through the real manager', function (string $name): void {
    // Administrative cancellation is host-authorized and denied by the shipped
    // policy, so the host grant is part of the call site being exercised.
    Gate::before(fn (Model $actor, string $ability): ?bool => $ability === 'cancel' ? true : null);

    $mutation = retainedMutations()[$name];

    expect($mutation['run'](contractFixture($mutation['state'])))->not->toBeNull();
})->with(array_keys(retainedMutations()));

it('records every retained mutation on the fake without writing approval state', function (string $name): void {
    $mutation = retainedMutations()[$name];
    $it = contractFixture($mutation['state']);
    $state = $it['request']->state;
    $actions = ApprovalAction::query()->count();
    $requests = ApprovalRequest::query()->count();

    $fake = Approval::fake();
    $fake->assertNothingRecorded();

    $mutation['run']($it);

    expect($fake->interactions())->toHaveCount(1)
        ->and(fn () => $fake->assertNothingRecorded())->toThrow(AssertionFailedError::class)
        ->and($it['request']->fresh()?->state)->toBe($state)
        ->and(ApprovalAction::query()->count())->toBe($actions)
        ->and(ApprovalRequest::query()->count())->toBe($requests);

    Approval::restore();
})->with(array_keys(retainedMutations()));

it('asserts each recorded interaction and distinguishes its actor and input', function (): void {
    $it = contractFixture('pending');
    $run = fn (string $name): mixed => retainedMutations()[$name]['run']($it);
    $fake = Approval::fake();

    $run('submit');
    $fake->assertSubmitted(
        $it['fresh'],
        fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($it['requester']) === true
            && $recorded->comment === 'Please review'
            && $recorded->metadata['new_attributes'] === ['title' => 'Drafted title'],
    );
    $fake->assertSubmittedTimes(1, $it['fresh']);
    $fake->assertNotSubmitted($it['approvable']);

    $run('approve');
    $fake->assertApproved($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->comment === 'Fine');
    $fake->assertApprovedTimes(1, $it['request']);

    $run('reject');
    $fake->assertRejected($it['request']);
    $fake->assertRejectedTimes(1, $it['request']);

    $run('returnForRevision');
    $fake->assertReturnedForRevision($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->comment === 'Revise');

    $run('approveMany');
    $fake->assertApprovedMany(fn (ApprovalInteraction $recorded): bool => $recorded->target === [$it['request']]);

    $run('rejectMany');
    $fake->assertRejectedMany();

    $run('withdraw');
    $fake->assertWithdrawn($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($it['requester']) === true);

    $run('delegate');
    $fake->assertDelegated($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->recipient?->is($it['delegate']) === true);

    $run('reviseAndResubmit');
    $fake->assertResubmitted($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['draft'] instanceof RevisedDraft);

    $run('attach');
    $fake->assertAttached($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['path'] === 'evidence/quote.pdf');

    $run('cancel');
    $fake->assertCancelled($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($it['operator']) === true);

    $run('reassign');
    $fake->assertReassigned($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->recipient?->is($it['delegate']) === true);

    $run('resync');
    $fake->assertResynced($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['prune'] === false);

    $run('resolve');
    $fake->assertConflictResolved($it['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['resolution'] === ConflictResolution::Discard);

    $run('withoutGuard');
    $fake->assertGuardBypassed($it['approvable']);

    $run('syncDefinitions');
    $fake->assertDefinitionsSynced(fn (ApprovalInteraction $recorded): bool => $recorded->metadata['dry_run'] === true);

    $run('escalateOverdue');
    $fake->assertOverdueProcessed();

    $fake->assertNotApproved(approvalRequest());

    Approval::restore();
});

it('returns shapes application code can keep using', function (): void {
    $it = contractFixture('pending');
    Approval::fake();

    expect(Approval::submit($it['fresh'], $it['workflow'], null, $it['requester']))
        ->toBeInstanceOf(ApprovalRequest::class)
        ->and(Approval::approve($it['request'], $it['approver']))->toBeInstanceOf(ApprovalRequest::class)
        ->and(Approval::approveMany([$it['request']], $it['approver'])->successful())->toHaveCount(1)
        ->and(Approval::delegate($it['request'], $it['approver'], $it['delegate'])->delegated_to_id)
        ->toBe($it['delegate']->getKey())
        ->and(Approval::attach(new AttachmentEvidence($it['request'], 'local', 'a.pdf', str_repeat('b', 64)))->path)
        ->toBe('a.pdf')
        ->and(Approval::resync($it['request'], $it['operator']))->toBe([])
        ->and(Approval::withoutGuard($it['approvable'], fn (): string => 'ran'))->toBe('ran')
        ->and(Approval::syncDefinitions([])->changed())->toBeFalse()
        ->and(Approval::escalateOverdue())->toBe([
            'escalated' => [],
            'reminded' => [],
            'stranded' => [],
            'failed' => [],
        ]);

    Approval::restore();
});

it('swaps the container binding and restores the real manager', function (): void {
    $fake = Approval::fake();

    expect(app(ApprovalManager::class))->toBe($fake)
        ->and($fake->restore())->not->toBeInstanceOf(ApprovalFake::class)
        ->and(app(ApprovalManager::class))->not->toBeInstanceOf(ApprovalFake::class);
});
