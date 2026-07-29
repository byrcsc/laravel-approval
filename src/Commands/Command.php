<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Console\Command as BaseCommand;

/**
 * What this package's commands share: how to find a request from whatever a
 * person typed, and how to fail in a way a cron log can be read.
 */
abstract class Command extends BaseCommand
{
    /**
     * Accepts the public ULID or the numeric key, because both end up in
     * people's terminals - the first from a URL, the second from a query.
     */
    protected function findRequest(string $identifier): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()->where('ulid', $identifier)->first();

        if ($request !== null) {
            return $request;
        }

        return ctype_digit($identifier)
            ? ApprovalRequest::query()->find((int) $identifier)
            : null;
    }
}
