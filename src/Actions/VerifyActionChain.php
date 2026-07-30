<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\ActionHash;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\AuditHashPayload;

final class VerifyActionChain
{
    private const CHUNK = 200;

    /**
     * @return list<array{request: string, subject: string, problem: string}>
     */
    public function __invoke(ApprovalRequest $request): array
    {
        $ulid = Attributes::string($request, 'ulid');
        $findings = [];
        $previous = null;

        $actions = ApprovalAction::query()
            ->where('request_id', $request->getKey())
            ->lazyById(self::CHUNK);

        foreach ($actions as $action) {
            $key = $action->getKey();
            $id = is_scalar($key) ? (string) $key : '?';
            $storedPrevious = Attributes::nullableString($action, 'previous_hash');
            $storedHash = Attributes::nullableString($action, 'hash');

            if ($storedPrevious !== $previous) {
                $findings[] = [
                    'request' => $ulid,
                    'subject' => "action #{$id}",
                    'problem' => 'the chain is broken: this line does not point at the line before it',
                ];
            }

            if ($storedHash === null || $storedHash !== $this->recompute($ulid, $action)) {
                $findings[] = [
                    'request' => $ulid,
                    'subject' => "action #{$id}",
                    'problem' => 'the content does not match its hash',
                ];
            }

            $previous = $storedHash;
        }

        if ($previous !== Attributes::nullableString($request, 'audit_head_hash')) {
            $findings[] = [
                'request' => $ulid,
                'subject' => 'request',
                'problem' => 'the final action does not match the request’s recorded head',
            ];
        }

        return $findings;
    }

    private function recompute(string $ulid, ApprovalAction $action): string
    {
        $actorId = $action->getAttributeValue('actor_id');
        $rawAction = $action->getRawOriginal('action');
        $metadata = $action->getAttributeValue('metadata') === null
            ? null
            : Attributes::object($action, 'metadata');

        return ActionHash::compute(new AuditHashPayload(
            requestUlid: $ulid,
            previousHash: Attributes::nullableString($action, 'previous_hash'),
            action: is_string($rawAction) ? $rawAction : '',
            actorType: Attributes::nullableString($action, 'actor_type'),
            actorId: is_scalar($actorId) ? (string) $actorId : null,
            actorLabel: Attributes::nullableString($action, 'actor_label'),
            stageId: Attributes::nullableInt($action, 'stage_id'),
            comment: Attributes::nullableString($action, 'comment'),
            metadata: $metadata,
            createdAt: Attributes::datetime($action, 'created_at')?->format('Y-m-d H:i:s') ?? '',
        ));
    }
}
