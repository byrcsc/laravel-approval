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
        'submit' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::submit(
            $fixture['fresh'],
            $fixture['workflow'],
            ['title' => 'Drafted title'],
            $fixture['requester'],
            'Please review',
            ['source' => 'contract-test'],
        )],
        'approve' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::approve($fixture['request'], $fixture['approver'], 'Fine')],
        'reject' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::reject($fixture['request'], $fixture['approver'], 'No')],
        'returnForRevision' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::returnForRevision($fixture['request'], $fixture['approver'], 'Revise')],
        'approveMany' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::approveMany([$fixture['request']], $fixture['approver'])],
        'rejectMany' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::rejectMany([$fixture['request']], $fixture['approver'], 'No')],
        'decideMany' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::decideMany(
            [$fixture['request']],
            fn (): ApprovalDecision => ApprovalDecision::approve($fixture['approver']),
        )],
        'withdraw' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::withdraw($fixture['request'], $fixture['requester'], 'Not yet')],
        'delegate' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::delegate($fixture['request'], $fixture['approver'], $fixture['delegate'], 'Away')],
        'attach' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::attach(new AttachmentEvidence(
            request: $fixture['request'],
            disk: 'local',
            path: 'evidence/quote.pdf',
            checksum: str_repeat('a', 64),
            name: 'quote.pdf',
            uploader: $fixture['requester'],
        ))],
        'cancel' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::cancel($fixture['request'], $fixture['operator'], 'Handled by phone')],
        'reassign' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::reassign($fixture['request'], $fixture['assignment'], $fixture['delegate'], $fixture['operator'])],
        'resync' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::resync($fixture['request'], $fixture['operator'])],
        'withoutGuard' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::withoutGuard(
            $fixture['approvable'],
            fn (): string => 'ran',
            $fixture['operator'],
        )],
        'syncDefinitions' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::syncDefinitions([], true)],
        'escalateOverdue' => ['state' => 'pending', 'run' => fn (array $fixture) => Approval::escalateOverdue()],
        'resolve' => ['state' => 'conflicted', 'run' => fn (array $fixture) => Approval::resolve($fixture['request'], ConflictResolution::Discard, $fixture['operator'])],
        'reviseAndResubmit' => ['state' => 'returned', 'run' => fn (array $fixture) => Approval::reviseAndResubmit(
            $fixture['request'],
            RevisedDraft::withAttributes(['title' => 'Revised title']),
            $fixture['requester'],
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
            ->and(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName().':'.$parameter->getType(), $fake->getParameters()))
            ->toBe(
                array_map(fn (ReflectionParameter $parameter): string => $parameter->getName().':'.$parameter->getType(), $real->getParameters()),
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
    $fixture = contractFixture($mutation['state']);
    $state = $fixture['request']->state;
    $actions = ApprovalAction::query()->count();
    $requests = ApprovalRequest::query()->count();

    $fake = Approval::fake();
    $fake->assertNothingRecorded();

    $mutation['run']($fixture);

    expect($fake->interactions())->toHaveCount(1)
        ->and(fn () => $fake->assertNothingRecorded())->toThrow(AssertionFailedError::class)
        ->and($fixture['request']->fresh()?->state)->toBe($state)
        ->and(ApprovalAction::query()->count())->toBe($actions)
        ->and(ApprovalRequest::query()->count())->toBe($requests);

    Approval::restore();
})->with(array_keys(retainedMutations()));

it('asserts each recorded interaction and distinguishes its actor and input', function (): void {
    $fixture = contractFixture('pending');
    $run = fn (string $name): mixed => retainedMutations()[$name]['run']($fixture);
    $fake = Approval::fake();

    $run('submit');
    $fake->assertSubmitted(
        $fixture['fresh'],
        fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($fixture['requester']) === true
            && $recorded->comment === 'Please review'
            && $recorded->metadata['new_attributes'] === ['title' => 'Drafted title'],
    );
    $fake->assertSubmittedTimes(1, $fixture['fresh']);
    $fake->assertNotSubmitted($fixture['approvable']);

    $run('approve');
    $fake->assertApproved($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->comment === 'Fine');
    $fake->assertApprovedTimes(1, $fixture['request']);

    $run('reject');
    $fake->assertRejected($fixture['request']);
    $fake->assertRejectedTimes(1, $fixture['request']);

    $run('returnForRevision');
    $fake->assertReturnedForRevision($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->comment === 'Revise');

    $run('approveMany');
    $fake->assertApprovedMany(fn (ApprovalInteraction $recorded): bool => $recorded->target === [$fixture['request']]);

    $run('rejectMany');
    $fake->assertRejectedMany();

    $run('withdraw');
    $fake->assertWithdrawn($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($fixture['requester']) === true);

    $run('delegate');
    $fake->assertDelegated($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->recipient?->is($fixture['delegate']) === true);

    $run('reviseAndResubmit');
    $fake->assertResubmitted($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['draft'] instanceof RevisedDraft);

    $run('attach');
    $fake->assertAttached($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['path'] === 'evidence/quote.pdf');

    $run('cancel');
    $fake->assertCancelled($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->actor?->is($fixture['operator']) === true);

    $run('reassign');
    $fake->assertReassigned($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->recipient?->is($fixture['delegate']) === true);

    $run('resync');
    $fake->assertResynced($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['prune'] === false);

    $run('resolve');
    $fake->assertConflictResolved($fixture['request'], fn (ApprovalInteraction $recorded): bool => $recorded->metadata['resolution'] === ConflictResolution::Discard);

    $run('withoutGuard');
    $fake->assertGuardBypassed($fixture['approvable']);

    $run('syncDefinitions');
    $fake->assertDefinitionsSynced(fn (ApprovalInteraction $recorded): bool => $recorded->metadata['dry_run'] === true);

    $run('escalateOverdue');
    $fake->assertOverdueProcessed();

    $fake->assertNotApproved(approvalRequest());

    Approval::restore();
});

it('returns shapes application code can keep using', function (): void {
    $fixture = contractFixture('pending');
    Approval::fake();

    expect(Approval::submit($fixture['fresh'], $fixture['workflow'], null, $fixture['requester']))
        ->toBeInstanceOf(ApprovalRequest::class)
        ->and(Approval::approve($fixture['request'], $fixture['approver']))->toBeInstanceOf(ApprovalRequest::class)
        ->and(Approval::approveMany([$fixture['request']], $fixture['approver'])->successful())->toHaveCount(1)
        ->and(Approval::delegate($fixture['request'], $fixture['approver'], $fixture['delegate'])->delegated_to_id)
        ->toBe($fixture['delegate']->getKey())
        ->and(Approval::attach(new AttachmentEvidence($fixture['request'], 'local', 'a.pdf', str_repeat('b', 64)))->path)
        ->toBe('a.pdf')
        ->and(Approval::resync($fixture['request'], $fixture['operator']))->toBe([])
        ->and(Approval::withoutGuard($fixture['approvable'], fn (): string => 'ran'))->toBe('ran')
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
