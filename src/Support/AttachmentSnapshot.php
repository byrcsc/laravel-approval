<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

final readonly class AttachmentSnapshot
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $name,
        public ?string $mimeType,
        public ?int $size,
        public string $checksum,
        public ?array $metadata,
        public ?string $uploaderType,
        public int|string|null $uploaderId,
        public ?string $uploaderLabel,
        public int|string|null $actionId,
        public string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return [
            'action_id' => $this->actionId,
            'uploader_type' => $this->uploaderType,
            'uploader_id' => $this->uploaderId,
            'uploader_label' => $this->uploaderLabel,
            'disk' => $this->disk,
            'path' => $this->path,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(int|string $attachmentId): array
    {
        return ['attachment' => $attachmentId, ...$this->fields()];
    }
}
