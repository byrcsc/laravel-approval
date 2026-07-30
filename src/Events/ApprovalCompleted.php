<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

/**
 * Approval routing completed because every applicable stage was satisfied.
 * This does not mean a draft was applied or the request reached its final
 * approved state; {@see ApprovalApproved} represents that later fact.
 */
final class ApprovalCompleted extends ApprovalEvent {}
