<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Support\Facades\Date;

/**
 * Moves a request's active stage past its deadline, so `approval:escalate` has
 * something to do without anybody waiting an hour.
 *
 * This is a demo aid and nothing else - no application should ever write to
 * these columns. It exists because SLA behavior is otherwise the one part of the
 * package that cannot be driven by hand: deadlines are recorded at submission
 * and nothing acts on them until the scheduled sweep runs.
 *
 * Both timestamps are moved. `deadline_at` is what makes the stage overdue;
 * `last_reminded_at` is what the reminder cadence measures from, so leaving it
 * alone would suppress the reminder that should follow.
 */
final class OverdueCommand extends Command
{
    protected $signature = 'demo:overdue
        {request : A request ULID or id}
        {--minutes=1 : How far into the past to move the deadline}';

    protected $description = 'Backdate the active stage deadline so approval:escalate acts on it now (demo aid)';

    public function handle(): int
    {
        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');

        if ($request === null) {
            return self::FAILURE;
        }

        $stage = $request->currentStage();

        if (! $stage instanceof ApprovalRequestStage) {
            $this->components->error('That request has no active stage.');

            return self::FAILURE;
        }

        if ($stage->getAttributeValue('deadline_at') === null) {
            $this->components->error(sprintf(
                'Stage [%s] carries no SLA, so it can never be overdue. Submit against a workflow whose stage sets sla_minutes.',
                (string) $stage->getAttributeValue('name'),
            ));

            return self::FAILURE;
        }

        $minutes = max(1, (int) ($this->text('minutes') ?? '1'));
        $deadline = Date::now()->subMinutes($minutes);

        $stage->forceFill([
            'deadline_at' => $deadline,
            'last_reminded_at' => $stage->getAttributeValue('last_reminded_at') === null
                ? null
                : $deadline,
        ])->save();

        $this->components->info(sprintf(
            'Stage [%s] is now %d minute(s) overdue.',
            (string) $stage->getAttributeValue('name'),
            $minutes,
        ));
        $this->components->twoColumnDetail(
            'strategy',
            (string) ($stage->getAttributeValue('escalation_strategy')?->value ?? 'none'),
        );
        $this->components->twoColumnDetail(
            'state',
            (string) ($stage->getAttributeValue('state')?->value ?? StageStatus::Queued->value),
        );

        $this->newLine();
        $this->line('  Next: <fg=cyan>vendor/bin/testbench approval:escalate</>');

        return self::SUCCESS;
    }
}
