<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Tests\Stubs\FlickeringLabelUser;

// The audit trail's tamper-evidence: every line hashes its content and the
// line before it, RecordAction is the only maintainer, and approval:verify
// walks the chains. These tests attack the trail the way an insider with
// database access would - the model layer already refuses politely.

/**
 * A request with a trail worth attacking: submitted with a justification,
 * delegated, and decided across two stages.
 */
function auditedRequest(): ApprovalRequest
{
    [$first, $second, $stand] = [user('First'), user('Second'), user('Stand-in')];
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$first]], ['approvers' => [$second]]]),
        ['amount' => 900],
        user('Author'),
        'Q3 restock.',
        ['cost_centre' => 'CC-7', 'urgency' => 'high'],
    );

    Approval::delegate($request, $first, $stand, 'Out this week.');
    Approval::approve($request, $stand);
    Approval::approve($request, $second, 'Within budget.');

    return $request->refresh();
}

it('writes a chain that verifies clean across a full lifecycle', function (): void {
    $request = auditedRequest();

    $actions = $request->actions()->get();

    expect($actions->first()?->previous_hash)->toBeNull()
        ->and($actions->skip(1)->every(fn (ApprovalAction $action): bool => $action->previous_hash !== null))->toBeTrue()
        ->and(Approval::verifyTrail())->toBe([]);
});

it('carries the submission comment and metadata on the submitted line', function (): void {
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        ['amount' => 900],
        user('Author'),
        'Q3 restock.',
        ['cost_centre' => 'CC-7'],
    );

    $submitted = $request->actions()->where('action', ActionType::Submitted->value)->sole();
    $metadata = $submitted->getAttributeValue('metadata');

    expect($submitted->getAttributeValue('comment'))->toBe('Q3 restock.')
        ->and(is_array($metadata) ? ($metadata['cost_centre'] ?? null) : null)->toBe('CC-7')
        ->and(is_array($metadata) ? ($metadata['workflow'] ?? null) : null)->not->toBeNull();
});

it('catches a line edited in place', function (): void {
    $request = auditedRequest();
    $approved = $request->actions()->where('action', ActionType::Approved->value)->orderBy('id')->first();

    ApprovalAction::query()->whereKey($approved?->getKey())
        ->update(['comment' => 'Reluctantly, under protest.']);

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['subject'])->toBe('action #'.$approved?->getKey())
        ->and($findings[0]['problem'])->toContain('does not match its hash');
});

it('catches a line removed from the middle of the chain', function (): void {
    $request = auditedRequest();
    $delegated = $request->actions()->where('action', ActionType::Delegated->value)->sole();

    ApprovalAction::query()->whereKey($delegated->getKey())->delete();

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['problem'])->toContain('chain is broken');
});

it('catches the final line being removed from the chain', function (): void {
    $request = auditedRequest();
    $last = $request->actions()->reorder()->orderByDesc('id')->firstOrFail();

    ApprovalAction::query()->whereKey($last->getKey())->delete();

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['subject'])->toBe('request')
        ->and($findings[0]['problem'])->toContain('recorded head');
});

it('anchors each append on the request', function (): void {
    $request = auditedRequest();
    $last = $request->actions()->reorder()->orderByDesc('id')->firstOrFail();

    expect($request->refresh()->getAttributeValue('audit_head_hash'))
        ->toBe($last->getAttributeValue('hash'));
});

it('confines a tampered chain to its own request', function (): void {
    $tampered = auditedRequest();
    $clean = purchaseOrder('PO-2002')->submitForApproval(
        workflow([['approvers' => [user('Other Approver')]]]),
        ['amount' => 100],
        user('Other Author'),
    );

    ApprovalAction::query()
        ->whereKey($tampered->actions()->orderBy('id')->first()?->getKey())
        ->update(['comment' => 'Nothing to see.']);

    expect(Approval::verifyTrail($clean))->toBe([])
        ->and(collect(Approval::verifyTrail())->pluck('request')->unique()->all())
        ->toBe([$tampered->getAttributeValue('ulid')]);
});

it('names the actor in words that outlive the account', function (): void {
    $approver = user('Grace Hopper');
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user('Author'),
    );

    Approval::approve($request, $approver);

    $approver->forceFill(['name' => 'G. Murray'])->save();
    $line = $request->actions()->where('action', ActionType::Approved->value)->sole();

    expect($line->getAttributeValue('actor_label'))->toBe('Grace Hopper');

    $approver->delete();

    expect($line->fresh()?->getAttributeValue('actor_label'))->toBe('Grace Hopper')
        ->and($line->fresh()?->actor)->toBeNull();
});

it('captures an actor label once for both the row and its hash', function (): void {
    $requester = FlickeringLabelUser::query()->create([
        'name' => 'Changing',
        'email' => 'changing@example.com',
    ]);

    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        ['amount' => 900],
        $requester,
    );

    expect($request->actions()->where('action', ActionType::Submitted->value)->sole()->actor_label)
        ->toBe('Changing read 1')
        ->and(Approval::verifyTrail($request))->toBe([]);
});

it('survives a step deletion with the loss on the record, not erased', function (): void {
    $request = auditedRequest();
    $stage = $request->stages()->first();
    $stageActions = $request->actions()->where('stage_id', $stage?->getKey())->count();

    expect($stageActions)->toBeGreaterThan(0);

    // Steps are deleted by nothing in the engine; this is the host taking a
    // shortcut. The lines survive with the reference nulled - and because the
    // hash covered the reference, the loss shows up in verify instead of
    // passing silently.
    $request->stages()->getQuery()->whereKey($stage?->getKey())->delete();

    expect($request->actions()->count())->toBeGreaterThanOrEqual($stageActions)
        ->and(ApprovalRequestStage::query()->whereKey($stage?->getKey())->exists())->toBeFalse()
        ->and(count(Approval::verifyTrail($request)))->toBe($stageActions);
});

it('reports through the artisan command with a failing exit code', function (): void {
    $request = auditedRequest();

    $this->artisan('approval:verify')->assertSuccessful();

    ApprovalAction::query()
        ->whereKey($request->actions()->orderBy('id')->first()?->getKey())
        ->update(['comment' => 'Edited.']);

    $this->artisan('approval:verify')->assertFailed();
    $this->artisan('approval:verify', ['request' => $request->getAttributeValue('ulid')])->assertFailed();
    $this->artisan('approval:verify', ['request' => 'nonexistent'])->assertFailed();
});
