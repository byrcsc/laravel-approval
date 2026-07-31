<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Walks each request's hash chain and reports where it no longer holds.
 *
 * Three kinds of finding, in the order they are checked: an action whose content
 * no longer matches its own hash (edited in place), an action whose
 * previous-hash does not point at the action before it (removed, reordered, or
 * inserted history), and an attachment inconsistent with the history - a row no
 * action recorded, a recorded file whose row is gone, or a row whose location or
 * checksum differs from the metadata recorded at attach time.
 *
 * What a clean result covers: action content, chain linkage, the request's
 * recorded chain head, attachment rows, and recorded attachment metadata.
 *
 * What it does not cover: request state, draft attributes, workflow stage
 * snapshots, and assignment rows, none of which are chained; and the stored
 * files themselves, whose bytes are never read and whose checksums are never
 * recomputed. A checksum row that matches its recorded action is treated as
 * valid.
 *
 * Findings are reported, never repaired. Deciding what a broken chain means is
 * the operator's decision.
 */
final class VerifyAuditTrail
{
    private const CHUNK = 200;

    public function __construct(
        private readonly VerifyActionChain $verifyActionChain,
        private readonly VerifyAttachments $verifyAttachments,
    ) {}

    /**
     * @return list<array{request: string, subject: string, problem: string}>
     */
    public function __invoke(?ApprovalRequest $only = null): array
    {
        $findings = [];

        $query = ApprovalRequest::query()->orderBy('id');

        if ($only !== null) {
            $query->whereKey($only->getKey());
        }

        foreach ($query->lazyById(self::CHUNK) as $request) {
            $requestFindings = [
                ...($this->verifyActionChain)($request),
                ...($this->verifyAttachments)($request),
            ];

            foreach ($requestFindings as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
