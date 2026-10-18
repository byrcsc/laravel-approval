<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\AttachmentSnapshot;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\CanonicalJson;
use ByRcsc\LaravelApproval\Support\Payload;

final class VerifyAttachments
{
    /**
     * @return list<array{request: string, subject: string, problem: string}>
     */
    public function __invoke(ApprovalRequest $request): array
    {
        $ulid = Attributes::string($request, 'ulid');
        $findings = [];
        $claimed = [];
        $rows = $this->rowsFor($request);

        $recorded = ApprovalModels::action()::query()
            ->where('request_id', $request->getKey())
            ->where('action', ActionType::AttachmentAdded->value)
            ->orderBy('id')
            ->get();

        foreach ($recorded as $action) {
            $metadata = Attributes::object($action, 'metadata');
            $id = Payload::nullableInt($metadata, 'attachment');
            $actionKey = $action->getKey();
            $key = is_scalar($actionKey) ? (string) $actionKey : '?';
            $row = $id === null ? null : ($rows[$id] ?? null);

            if ($row === null) {
                $findings[] = [
                    'request' => $ulid,
                    'subject' => "action #{$key}",
                    'problem' => 'the trail records an attachment whose row is gone',
                ];

                continue;
            }

            $claimed[$id] = true;
            $snapshot = $this->snapshot($row);

            foreach ($snapshot->fields() as $field => $held) {
                $matches = array_key_exists($field, $metadata)
                    && CanonicalJson::normalize($metadata[$field]) === CanonicalJson::normalize($held);

                if ($matches) {
                    continue;
                }

                $findings[] = [
                    'request' => $ulid,
                    'subject' => "attachment #{$id}",
                    'problem' => "its {$field} no longer matches what was chained when it was attached",
                ];
            }
        }

        foreach ($rows as $id => $row) {
            if (isset($claimed[$id])) {
                continue;
            }

            $findings[] = [
                'request' => $ulid,
                'subject' => "attachment #{$id}",
                'problem' => 'the trail never recorded this attachment',
            ];
        }

        return $findings;
    }

    /**
     * @return array<int, ApprovalAttachment>
     */
    private function rowsFor(ApprovalRequest $request): array
    {
        $rows = [];

        foreach (ApprovalModels::attachment()::query()->where('request_id', $request->getKey())->orderBy('id')->get() as $row) {
            $key = $row->getKey();

            if (is_int($key)) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    private function snapshot(ApprovalAttachment $row): AttachmentSnapshot
    {
        $metadata = $row->getAttributeValue('metadata') === null
            ? null
            : Attributes::object($row, 'metadata');

        return new AttachmentSnapshot(
            disk: Attributes::string($row, 'disk'),
            path: Attributes::string($row, 'path'),
            name: Attributes::nullableString($row, 'name'),
            mimeType: Attributes::nullableString($row, 'mime_type'),
            size: Attributes::nullableInt($row, 'size'),
            checksum: Attributes::string($row, 'checksum'),
            metadata: $metadata,
            uploaderType: Attributes::nullableString($row, 'uploader_type'),
            uploaderId: $this->key($row->getAttributeValue('uploader_id')),
            uploaderLabel: Attributes::nullableString($row, 'uploader_label'),
            actionId: $this->key($row->getAttributeValue('action_id')),
            createdAt: Attributes::datetime($row, 'created_at')?->format('Y-m-d H:i:s') ?? '',
        );
    }

    private function key(mixed $key): int|string|null
    {
        return is_int($key) || is_string($key) ? $key : null;
    }
}
