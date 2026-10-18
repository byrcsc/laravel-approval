<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\SweepDelegations;
use ByRcsc\LaravelApproval\Support\DelegationsTable;

/**
 * Schedule this when delegations are granted ahead of time or with an end date.
 *
 *     Schedule::command('approval:delegations')->everyFifteenMinutes();
 *
 * Granting and revoking apply at once. This catches the windows that open or
 * close on their own, and is safe to run as often as you like.
 */
final class ApplyDelegationsCommand extends Command
{
    protected $signature = 'approval:delegations';

    protected $description = 'Move approval assignments to and from delegates as delegation windows open and close';

    public function handle(SweepDelegations $sweep): int
    {
        if (! DelegationsTable::installed()) {
            $this->components->warn('The delegations migration has not run, so there is nothing to apply.');

            return self::SUCCESS;
        }

        $moved = $sweep();

        $this->components->info($moved === 0
            ? 'No assignments to move.'
            : sprintf('Moved %d %s.', $moved, $moved === 1 ? 'assignment' : 'assignments'));

        return self::SUCCESS;
    }
}
