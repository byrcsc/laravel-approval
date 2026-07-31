<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Exceptions\AttachmentException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Database\QueryException;

// Attachments are references into a host-owned disk, chained into the audit
// trail through the line that records them. The attacks here are the ones
// that matter for evidence: swap the file, remove the row, plant a row the
// trail never saw.

/**
 * @return array{ApprovalRequest, User}
 */
function requestWithApprover(): array
{
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user('Author'),
    );

    return [$request, $approver];
}

it('records an attachment as a row and a chained audit line', function (): void {
    [$request] = requestWithApprover();
    $author = $request->requester;

    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'orders/po-1001/quote.pdf',
        checksum: hash('sha256', 'quote bytes'),
        name: 'quote.pdf',
        mimeType: 'application/pdf',
        size: 48_213,
        uploader: $author,
    ));

    $line = $request->actions()->where('action', ActionType::AttachmentAdded->value)->sole();
    $metadata = $line->getAttributeValue('metadata');

    expect($request->attachments()->sole()->is($attachment))->toBeTrue()
        ->and($attachment->getAttributeValue('uploader_label'))->toBe('Author')
        ->and(is_array($metadata) ? ($metadata['checksum'] ?? null) : null)->toBe(hash('sha256', 'quote bytes'))
        ->and(Approval::verifyTrail($request))->toBe([]);
});

it('requires a sha256 checksum so the referenced bytes are signed', function (): void {
    [$request] = requestWithApprover();

    expect(fn () => new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'orders/po-1001/quote.pdf',
        checksum: '',
    ))
        ->toThrow(AttachmentException::class, 'SHA-256 checksum')
        ->and(fn () => new AttachmentEvidence(
            request: $request,
            disk: 'documents',
            path: 'orders/po-1001/quote.pdf',
            checksum: 'not-a-sha256-digest',
        ))->toThrow(AttachmentException::class, 'SHA-256 checksum');
});

it('hangs a signature off the decision it supports', function (): void {
    [$request, $approver] = requestWithApprover();

    Approval::approve($request, $approver);
    $decision = $request->actions()->where('action', ActionType::Approved->value)->sole();

    // The request is closed; evidence about its decision still lands.
    $signature = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'signatures',
        path: 'sig/42.png',
        checksum: hash('sha256', 'signature bytes'),
        uploader: $approver,
        action: $decision,
    ));

    expect($decision->attachments()->sole()->is($signature))->toBeTrue()
        ->and($signature->action?->is($decision))->toBeTrue()
        ->and(Approval::verifyTrail($request))->toBe([]);
});

it('refuses a blank location and a decision from another request', function (): void {
    [$request, $approver] = requestWithApprover();
    [$other, $otherApprover] = [
        purchaseOrder('PO-2002')->submitForApproval(workflow([['approvers' => [user('Else')]]]), null, user('Other Author')),
        $approver,
    ];

    $foreign = $other->actions()->where('action', ActionType::Submitted->value)->sole();

    expect(fn () => new AttachmentEvidence(
        request: $request,
        disk: '',
        path: 'somewhere.pdf',
        checksum: hash('sha256', 'somewhere'),
    ))
        ->toThrow(AttachmentException::class, 'disk and a path')
        ->and(fn () => new AttachmentEvidence(
            request: $request,
            disk: 'documents',
            path: ' ',
            checksum: hash('sha256', 'somewhere'),
        ))
        ->toThrow(AttachmentException::class, 'disk and a path')
        ->and(fn () => new AttachmentEvidence(
            request: $request,
            disk: 'documents',
            path: 'a.pdf',
            checksum: hash('sha256', 'a'),
            uploader: $otherApprover,
            action: $foreign,
        ))
        ->toThrow(AttachmentException::class, 'does not belong')
        ->and($request->attachments()->count())->toBe(0);
});

it('is evidence, so the model refuses updates and deletes', function (): void {
    [$request] = requestWithApprover();
    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'a.pdf',
        checksum: hash('sha256', 'a'),
    ));

    expect(fn () => $attachment->update(['path' => 'b.pdf']))
        ->toThrow(LogicException::class, 'cannot be updated')
        ->and(fn () => $attachment->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');
});

it('catches a swapped file', function (): void {
    [$request] = requestWithApprover();
    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'a.pdf',
        checksum: hash('sha256', 'original bytes'),
    ));

    ApprovalAttachment::query()->whereKey($attachment->getKey())
        ->update(['checksum' => hash('sha256', 'different bytes')]);

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['subject'])->toBe('attachment #'.$attachment->getKey())
        ->and($findings[0]['problem'])->toContain('checksum');
});

it('catches decision evidence rebound to another action', function (): void {
    [$request, $approver] = requestWithApprover();
    Approval::approve($request, $approver);

    $submitted = $request->actions()->where('action', ActionType::Submitted->value)->sole();
    $approved = $request->actions()->where('action', ActionType::Approved->value)->sole();
    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'signatures',
        path: 'sig/42.png',
        checksum: hash('sha256', 'signature bytes'),
        uploader: $approver,
        action: $approved,
    ));

    ApprovalAttachment::query()->whereKey($attachment->getKey())
        ->update(['action_id' => $submitted->getKey()]);

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['subject'])->toBe('attachment #'.$attachment->getKey())
        ->and($findings[0]['problem'])->toContain('action');
});

it('catches descriptive attachment evidence changed out of band', function (
    string $column,
    mixed $replacement,
): void {
    [$request] = requestWithApprover();
    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'a.pdf',
        checksum: hash('sha256', 'original bytes'),
        name: 'a.pdf',
        mimeType: 'application/pdf',
        size: 42,
        metadata: ['classification' => 'internal'],
        uploader: user('Uploader'),
    ));

    ApprovalAttachment::query()->whereKey($attachment->getKey())
        ->update([$column => $replacement]);

    expect(Approval::verifyTrail($request))
        ->toHaveCount(1)
        ->and(Approval::verifyTrail($request)[0]['problem'])->toContain($column);
})->with([
    'uploader type' => ['uploader_type', 'planted-uploader'],
    'uploader id' => ['uploader_id', 999_999],
    'uploader label' => ['uploader_label', 'Somebody Else'],
    'name' => ['name', 'replacement.pdf'],
    'mime type' => ['mime_type', 'text/plain'],
    'size' => ['size', 99],
    'metadata' => ['metadata', json_encode(['classification' => 'public'], JSON_THROW_ON_ERROR)],
    'created at' => ['created_at', now()->subDay()],
]);

it('catches a removed attachment row', function (): void {
    [$request] = requestWithApprover();
    $attachment = Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'a.pdf',
        checksum: hash('sha256', 'a'),
    ));

    ApprovalAttachment::query()->whereKey($attachment->getKey())->delete();

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['problem'])->toContain('row is gone');
});

it('catches a planted attachment the trail never recorded', function (): void {
    [$request] = requestWithApprover();

    ApprovalAttachment::query()->insert([
        'request_id' => $request->getKey(),
        'disk' => 'documents',
        'path' => 'planted.pdf',
        'checksum' => hash('sha256', 'planted'),
        'created_at' => now(),
    ]);

    $findings = Approval::verifyTrail($request);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['problem'])->toContain('never recorded');
});

it('anchors evidence at the database: a request with attachments cannot go', function (): void {
    [$request] = requestWithApprover();
    Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'documents',
        path: 'a.pdf',
        checksum: hash('sha256', 'a'),
    ));

    expect(fn () => $request->delete())->toThrow(QueryException::class);

    // Even with the trail erased out-of-band, the attachment row still holds
    // the request in place.
    $request->actions()->getQuery()->delete();

    expect(fn () => $request->fresh()?->delete())->toThrow(QueryException::class);
});
