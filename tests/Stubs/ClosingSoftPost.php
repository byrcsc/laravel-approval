<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

final class ClosingSoftPost extends SoftPost
{
    public function closeApprovalOnSoftDelete(): bool
    {
        return true;
    }
}
