<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\BulkDecisionOutcome;
use ByRcsc\LaravelApproval\Data\BulkDecisionResult;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Throwable;

final class DecideInBulk
{
    public function __construct(private readonly ApprovalManager $approval) {}

    /**
     * @param  iterable<int, mixed>  $requests
     * @param  callable(ApprovalRequest): ApprovalDecision  $decision
     */
    public function __invoke(iterable $requests, callable $decision): BulkDecisionResult
    {
        $items = [];

        foreach ($requests as $request) {
            if (! $request instanceof ApprovalRequest) {
                throw new InvalidArgumentException('Bulk approval decisions accept only ApprovalRequest models.');
            }

            $items[] = $request;
        }

        $collection = new Collection($items);
        $collection->loadMissing([
            'workflow',
            'stages.assignments.approver',
            'stages.assignments.delegatedTo',
            'stages.actions',
        ]);

        $outcomes = [];

        foreach ($collection as $request) {
            try {
                $outcomes[] = BulkDecisionOutcome::succeeded(
                    $request,
                    $this->approval->decide($request, $decision($request)),
                );
            } catch (Throwable $exception) {
                $outcomes[] = BulkDecisionOutcome::failed($request, $exception);
            }
        }

        return new BulkDecisionResult($outcomes);
    }
}
