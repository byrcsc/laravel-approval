<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\TimelineState;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class TimelineEntry
{
    /**
     * @param  Collection<int, Model>  $approvers
     * @param  Collection<int, ApprovalAction>  $actions
     */
    public function __construct(
        public ApprovalRequestStage $stage,
        public TimelineState $state,
        public Collection $approvers,
        public Collection $actions,
    ) {}
}
