<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;

final class DoctorCommand extends Command
{
    protected $signature = 'approval:doctor {--limit=50 : Maximum stranded levels to list}';

    protected $description = 'Find pending approval levels whose living seat holders cannot meet quorum';

    public function handle(): int
    {
        $stranded = ApprovalRequestStep::query()
            ->where('state', StepState::Active->value)
            ->whereHas('request', fn ($query) => $query->where('state', RequestState::Pending->value))
            ->orderBy('id')
            ->get()
            ->filter(fn (ApprovalRequestStep $step): bool => StepHealth::inspect($step)['stranded'])
            ->take(max(1, (int) $this->option('limit')));

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
                sprintf('%s: %d living / %d required', Attributes::string($step, 'name'), $health['living'], $health['quorum']),
            );
        }

        return self::FAILURE;
    }
}
