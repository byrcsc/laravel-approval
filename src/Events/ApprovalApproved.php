<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

/**
 * The request reached its final approved state, including successful draft
 * application when the request held a draft.
 */
final class ApprovalApproved extends ApprovalEvent {}
