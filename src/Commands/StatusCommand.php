<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ScanActiveStepHealth;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Date;

/**
 * What the engine currently holds: a count per state, and the requests that
 * need somebody to look at them.
 *
 * The two lists at the end are the ones worth acting on. Overdue requests are
 * waiting on a person. Conflicted ones are waiting on a decision nobody can
 * make automatically - they cleared every level and then found the record had
 * moved, so they sit there until somebody resolves them, and nothing else in
 * this package will chase them.
 */
final class StatusCommand extends Command
{
    protected $signature = 'approval:status {--limit=10 : How many requests to list under each heading}';

    protected $description = 'Summarise approval requests by state, and list the ones needing attention';

    public function __construct(private readonly ScanActiveStepHealth $healthScan)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->twoColumnDetail('<fg=gray>State</>', '<fg=gray>Requests</>');

        foreach (RequestState::cases() as $state) {
            $this->components->twoColumnDetail(
                $state->value,
                (string) ApprovalRequest::query()->where('state', $state->value)->count(),
            );
        }

        $limit = max(1, (int) $this->option('limit'));

        $this->listOverdue($limit);
        $this->listStranded($limit);
        $this->listConflicted($limit);

        return self::SUCCESS;
    }

    private function listStranded(int $limit): void
    {
        $steps = ($this->healthScan)($limit);

        if ($steps->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->error('Stranded, living holders cannot meet quorum');

        foreach ($steps as $step) {
            $health = StepHealth::inspect($step);
            $request = $step->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf('%s, %d living / %d required', Attributes::string($step, 'name'), $health->living, $health->quorum),
            );
        }
    }

    private function listOverdue(int $limit): void
    {
        $steps = ApprovalRequestStep::query()
            ->where('state', StepState::Active->value)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', Date::now())
            ->whereHas('request', fn ($query) => $query->where('state', RequestState::Pending->value))
            ->orderBy('deadline_at')
            ->limit($limit)
            ->get();

        if ($steps->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Overdue');

        foreach ($steps as $step) {
            $request = $step->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf(
                    '%s, due %s',
                    Attributes::string($step, 'name'),
                    Attributes::datetime($step, 'deadline_at')?->diffForHumans() ?? 'unknown',
                ),
            );
        }
    }

    private function listConflicted(int $limit): void
    {
        $requests = ApprovalRequest::query()
            ->where('state', RequestState::Conflicted->value)
            ->orderByDesc('completed_at')
            ->limit($limit)
            ->get();

        if ($requests->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Conflicted, waiting on a person');

        foreach ($requests as $request) {
            $this->components->twoColumnDetail(
                Attributes::string($request, 'ulid'),
                Attributes::datetime($request, 'completed_at')?->diffForHumans() ?? 'unknown',
            );
        }
    }
}
