<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

final class ClosingSoftPost extends SoftPost
{
    public function cancelApprovalOnSoftDelete(): bool
    {
        return true;
    }
}
