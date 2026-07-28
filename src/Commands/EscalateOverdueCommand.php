<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\EscalateOverdueSteps;

/**
 * Schedule this. Nothing else moves a deadline along.
 *
 *     Schedule::command('approval:escalate')->everyFifteenMinutes();
 *
 * The interval is a floor on how late "late" is noticed, not a correctness
 * knob - the sweep is idempotent, so running it every minute and running it
 * every hour produce the same escalations, just at different resolutions.
 *
 * Exits non-zero if any step's strategy threw, so a scheduler that watches exit
 * codes says something. The steps that succeeded are still committed; the ones
 * that failed are retried on the next run.
 */
final class EscalateOverdueCommand extends Command
{
    protected $signature = 'approval:escalate';

    protected $description = 'Escalate approval levels that have passed their deadline';

    public function handle(EscalateOverdueSteps $escalate): int
    {
        $result = $escalate();

        $count = count($result['escalated']);

        if ($count === 0 && $result['failed'] === []) {
            $this->components->info('Nothing overdue.');

            return self::SUCCESS;
        }

        if ($count > 0) {
            $this->components->info(sprintf('Escalated %d %s.', $count, $count === 1 ? 'level' : 'levels'));
        }

        foreach ($result['failed'] as $step => $message) {
            $this->components->error("Step {$step} could not be escalated: {$message}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
