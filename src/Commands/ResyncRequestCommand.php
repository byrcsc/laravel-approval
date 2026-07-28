<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ResyncRequestApprovers;

/**
 * The runbook entry for "an approver left and their requests are stuck".
 *
 * Deliberately a command rather than anything automatic: rebuilding a live
 * request's approver list is a decision somebody makes, and it should appear in
 * a shell history with a name attached to it.
 */
final class ResyncRequestCommand extends Command
{
    protected $signature = 'approval:resync
        {request : The request ULID, or its numeric id}
        {--prune : Also drop approvers the workflow no longer names}';

    protected $description = "Re-run a pending request's approver rules against the current workflow";

    public function handle(ResyncRequestApprovers $resync): int
    {
        $identifier = (string) $this->argument('request');
        $request = $this->findRequest($identifier);

        if ($request === null) {
            $this->components->error("No approval request found for [{$identifier}].");

            return self::FAILURE;
        }

        $changes = $resync($request, null, (bool) $this->option('prune'));

        if ($changes === []) {
            $this->components->info('Nothing to change: the request already matches its workflow.');

            return self::SUCCESS;
        }

        foreach ($changes as $sequence => $change) {
            $this->components->twoColumnDetail(
                "Level {$sequence}",
                sprintf('+%d / -%d', count($change['added']), count($change['removed'])),
            );

            foreach ($change['added'] as $key) {
                $this->line("    <fg=green>+</> {$key}");
            }

            foreach ($change['removed'] as $key) {
                $this->line("    <fg=red>-</> {$key}");
            }
        }

        return self::SUCCESS;
    }
}
