<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Actions\RecordAction;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Support\AttachmentSnapshot;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<ApprovalAttachment>
 */
final class ApprovalAttachmentFactory extends Factory
{
    protected $model = ApprovalAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => ApprovalRequestFactory::new(),
            'action_id' => null,
            'uploader_type' => null,
            'uploader_id' => null,
            'uploader_label' => null,
            'disk' => 'local',
            'path' => 'approval-evidence/'.fake()->uuid().'.pdf',
            'name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1, 10_000),
            'checksum' => hash('sha256', fake()->uuid()),
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function configure(): self
    {
        return $this->afterCreating(function (ApprovalAttachment $attachment): void {
            $request = $attachment->request;
            $key = $attachment->getKey();

            if ($request === null || ! is_int($key)) {
                throw new LogicException('An approval attachment factory requires a persisted request and integer key.');
            }

            $snapshot = new AttachmentSnapshot(
                disk: Attributes::string($attachment, 'disk'),
                path: Attributes::string($attachment, 'path'),
                name: Attributes::nullableString($attachment, 'name'),
                mimeType: Attributes::nullableString($attachment, 'mime_type'),
                size: Attributes::nullableInt($attachment, 'size'),
                checksum: Attributes::string($attachment, 'checksum'),
                metadata: $attachment->getAttributeValue('metadata') === null
                    ? null
                    : Attributes::object($attachment, 'metadata'),
                uploaderType: null,
                uploaderId: null,
                uploaderLabel: null,
                actionId: null,
                createdAt: Attributes::datetime($attachment, 'created_at')?->format('Y-m-d H:i:s') ?? '',
            );

            app(RecordAction::class)(
                request: $request,
                action: ActionType::AttachmentAdded,
                metadata: $snapshot->auditMetadata($key),
            );
        });
    }
}
