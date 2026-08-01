<?php

declare(strict_types=1);

namespace Workbench\App\Approval\Conditions;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether one stage of an already-chosen workflow applies to this submission.
 *
 * The executive workflow's board sign-off stage carries this: everything at or
 * above 10,000 goes through the executive workflow, but only what is at or above
 * 50,000 needs the board. A stage whose condition fails is written onto the
 * request as `skipped` rather than omitted, so the timeline still shows the
 * stage and records that it did not apply.
 *
 * Conditions run once, before the request is written, and the result is frozen
 * with everything else. Changing the threshold afterwards does not reopen a
 * stage that was skipped.
 */
final class AmountAtOrAboveStage implements StageCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(StageSnapshot $stage, Model $approvable, Model $requester, array $config): bool
    {
        $threshold = $config['amount'] ?? null;
        $amount = $approvable->getAttributeValue('amount');

        return is_int($threshold)
            && is_numeric($amount)
            && (int) $amount >= $threshold;
    }
}
