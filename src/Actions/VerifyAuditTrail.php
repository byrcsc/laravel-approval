<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Walks each request's audit chain and says where it no longer holds.
 *
 * Three kinds of finding, in the order they are checked: a line whose content
 * no longer matches its own hash (edited in place), a line whose previous-hash
 * does not point at the line before it (removed, reordered, or inserted
 * history), and an attachment out of step with the trail - a row the trail
 * never recorded, a recorded file whose row is gone, or a row whose location
 * or checksum differs from what was chained at attach time.
 *
 * Findings are reported, never repaired. This reads history; deciding what a
 * broken chain means is the operator's job.
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
