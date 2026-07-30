<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

final readonly class AuditHashPayload
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $requestUlid,
        public ?string $previousHash,
        public string $action,
        public ?string $actorType,
        public ?string $actorId,
        public ?string $actorLabel,
        public ?int $stageId,
        public ?string $comment,
        public ?array $metadata,
        public string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function canonicalValues(): array
    {
        return [
            'action' => $this->action,
            'actor' => $this->actorType === null ? null : $this->actorType.':'.($this->actorId ?? ''),
            'actor_label' => $this->actorLabel,
            'comment' => $this->comment,
            'created_at' => $this->createdAt,
            'metadata' => CanonicalJson::normalize($this->metadata),
            'previous' => $this->previousHash,
            'request' => $this->requestUlid,
            'stage' => $this->stageId,
        ];
    }
}
