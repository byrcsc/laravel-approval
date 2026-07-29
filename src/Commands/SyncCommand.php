<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Support\Payload;

final class SyncCommand extends Command
{
    protected $signature = 'approval:sync {--dry-run : Preview changes without writing them}';

    protected $description = 'Create and reconcile version-controlled approval workflow definitions';

    public function handle(): int
    {
        $configured = config('approval.definitions', []);
        $definitions = Payload::asObject($configured) ?? [];
        $result = Approval::syncDefinitions($definitions, (bool) $this->option('dry-run'));

        foreach ($result->created as $change) {
            $this->components->twoColumnDetail('create', $change);
        }

        foreach ($result->updated as $change) {
            $this->components->twoColumnDetail('update', $change);
        }

        foreach ($result->unchanged as $change) {
            $this->components->twoColumnDetail('unchanged', $change);
        }

        foreach ($result->refused as $change) {
            $this->components->error($change);
        }

        if (! $result->successful()) {
            return self::FAILURE;
        }

        if (! $result->changed()) {
            $this->components->info('Approval workflow definitions are already in sync.');

            return self::SUCCESS;
        }

        $message = $result->dryRun
            ? 'Dry run complete; no approval workflow records were changed.'
            : 'Approval workflow definitions synced.';
        $this->components->info($message);

        return self::SUCCESS;
    }
}
