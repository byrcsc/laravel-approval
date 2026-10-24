<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ScanActiveStageHealth;
use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Date;

/**
 * Reports approval state: a count per request state, followed by the requests
 * that need attention.
 *
 * The two lists at the end are actionable. Overdue requests are waiting on an
 * approver. Conflicted requests approved every stage and then found the record
 * had changed; nothing in this package resolves them automatically, so they stay
 * open until an administrator reapplies or discards the draft.
 */
final class StatusCommand extends Command
{
    protected $signature = 'approval:status {--limit=10 : How many requests to list under each heading}';

    protected $description = 'Summarise approval requests by state, and list the ones needing attention';

    public function __construct(private readonly ScanActiveStageHealth $healthScan)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->twoColumnDetail('<fg=gray>State</>', '<fg=gray>Requests</>');

        foreach (RequestState::cases() as $state) {
            $this->components->twoColumnDetail(
                $state->value,
                (string) ApprovalModels::request()::query()->where('state', $state->value)->count(),
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
        $stages = ($this->healthScan)($limit);

        if ($stages->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->error('Stranded, living holders cannot meet the required approvals');

        foreach ($stages as $stage) {
            $health = StageHealth::inspect($stage);
            $request = $stage->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf('%s, %d living / %d required', Attributes::string($stage, 'name'), $health->livingHolders, $health->requiredApprovals),
            );
        }
    }

    private function listOverdue(int $limit): void
    {
        $stages = ApprovalModels::requestStage()::query()
            ->where('state', StageStatus::Active->value)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', Date::now())
            ->whereHas('request', fn ($query) => $query->where('state', RequestState::Pending->value))
            ->orderBy('deadline_at')
            ->limit($limit)
            ->get();

        if ($stages->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Overdue');

        foreach ($stages as $stage) {
            $request = $stage->request;

            $this->components->twoColumnDetail(
                $request === null ? '?' : Attributes::string($request, 'ulid'),
                sprintf(
                    '%s, due %s',
                    Attributes::string($stage, 'name'),
                    Attributes::datetime($stage, 'deadline_at')?->diffForHumans() ?? 'unknown',
                ),
            );
        }
    }

    private function listConflicted(int $limit): void
    {
        $requests = ApprovalModels::request()::query()
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
