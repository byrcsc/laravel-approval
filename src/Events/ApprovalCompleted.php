<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

/**
 * Every level is carried. Fired before the draft is applied, so a listener sees
 * the record as it still is - {@see DraftApplied} is the one that fires after.
 */
final class ApprovalCompleted extends ApprovalEvent {}
