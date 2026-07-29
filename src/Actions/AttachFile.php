<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\AttachmentSnapshot;
use ByRcsc\LaravelApproval\Support\Labels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Puts a file in evidence on a request: a reference row, and an audit line
 * that chains what was attached - checksum included - into the trail.
 *
 * Works against a closed request as readily as an open one, on purpose. The
 * commonest attachments arrive at the edges of a request's life: supporting
 * documents at submission, a signature image on the decision that closed it.
 *
 * The row and its audit line are one transaction. An attachment the trail
 * does not mention is exactly the artefact `approval:verify` treats as
 * planted, so there must be no path that writes one without the other.
 */
final class AttachFile
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(AttachmentEvidence $evidence): ApprovalAttachment
    {
        return DB::transaction(function () use ($evidence): ApprovalAttachment {
            ApprovalRequest::query()
                ->whereKey($evidence->request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $createdAt = Date::now();
            $uploaderLabel = $evidence->uploader === null ? null : Labels::for($evidence->uploader);
            $snapshot = new AttachmentSnapshot(
                disk: $evidence->disk,
                path: $evidence->path,
                name: $evidence->name,
                mimeType: $evidence->mimeType,
                size: $evidence->size,
                checksum: $evidence->checksum,
                metadata: $evidence->metadata === [] ? null : $evidence->metadata,
                uploaderType: $evidence->uploader?->getMorphClass(),
                uploaderId: $this->key($evidence->uploader?->getKey()),
                uploaderLabel: $uploaderLabel,
                actionId: $this->key($evidence->action?->getKey()),
                createdAt: $createdAt->format('Y-m-d H:i:s'),
            );

            $attachment = ApprovalAttachment::create([
                'request_id' => $evidence->request->getKey(),
                ...$snapshot->fields(),
                'created_at' => $createdAt,
            ]);

            ($this->recordAction)(
                request: $evidence->request,
                action: ActionType::AttachmentAdded,
                actor: $evidence->uploader,
                metadata: $snapshot->auditMetadata($this->requiredKey($attachment->getKey())),
            );

            return $attachment;
        });
    }

    private function key(mixed $key): int|string|null
    {
        return is_int($key) || is_string($key) ? $key : null;
    }

    private function requiredKey(mixed $key): int|string
    {
        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new LogicException('A persisted approval attachment must have a scalar key.');
    }
}
