<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ScanActiveStepHealth;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Support\Attributes;

final class DoctorCommand extends Command
{
    protected $signature = 'approval:doctor {--limit=50 : Maximum stranded levels to list}';

    protected $description = 'Find pending approval levels whose living seat holders cannot meet quorum';

    public function __construct(private readonly ScanActiveStepHealth $scan)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stranded = ($this->scan)(max(1, (int) $this->option('limit')));

        if ($stranded->isEmpty()) {
            $this->components->info('No stranded approval levels.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            'Found %d stranded approval %s.',
            $stranded->count(),
            $stranded->count() === 1 ? 'level' : 'levels',
        ));

        foreach ($stranded as $step) {
            $health = StepHealth::inspect($step);
            $request = $step->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf('%s: %d living / %d required', Attributes::string($step, 'name'), $health->living, $health->quorum),
            );
        }

        return self::FAILURE;
    }
}
