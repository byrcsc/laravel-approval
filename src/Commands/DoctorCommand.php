<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ScanActiveStageHealth;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Support\Attributes;

final class DoctorCommand extends Command
{
    protected $signature = 'approval:doctor {--limit=50 : Maximum stranded stages to list}';

    protected $description = 'Find pending approval stages whose living assignment holders cannot meet the required approvals';

    public function __construct(private readonly ScanActiveStageHealth $scan)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stranded = ($this->scan)(max(1, (int) $this->option('limit')));

        if ($stranded->isEmpty()) {
            $this->components->info('No stranded approval stages.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            'Found %d stranded approval %s.',
            $stranded->count(),
            $stranded->count() === 1 ? 'stage' : 'stages',
        ));

        foreach ($stranded as $stage) {
            $health = StageHealth::inspect($stage);
            $request = $stage->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf('%s: %d living / %d required', Attributes::string($stage, 'name'), $health->livingHolders, $health->requiredApprovals),
            );
        }

        return self::FAILURE;
    }
}
