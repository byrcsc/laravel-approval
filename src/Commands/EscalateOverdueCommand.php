<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\EscalateOverdueStages;

/**
 * Schedule this. Nothing else moves a deadline along.
 *
 *     Schedule::command('approval:escalate')->everyFifteenMinutes();
 *
 * The interval is a floor on how late "late" is noticed, not a correctness
 * knob - the sweep is idempotent, so running it every minute and running it
 * every hour produce the same escalations, just at different resolutions.
 *
 * Exits non-zero if any stage's strategy threw, so a scheduler that watches exit
 * codes says something. The stages that succeeded are still committed; the ones
 * that failed are retried on the next run.
 */
final class EscalateOverdueCommand extends Command
{
    protected $signature = 'approval:escalate';

    protected $description = 'Escalate approval stages that have passed their deadline';

    public function handle(EscalateOverdueStages $escalate): int
    {
        $result = $escalate();

        $count = count($result['escalated']);
        $reminders = count($result['reminded']);

        if ($count === 0 && $reminders === 0 && $result['stranded'] === [] && $result['failed'] === []) {
            $this->components->info('Nothing overdue.');

            return self::SUCCESS;
        }

        if ($count > 0) {
            $this->components->info(sprintf('Escalated %d %s.', $count, $count === 1 ? 'stage' : 'stages'));
        }

        if ($reminders > 0) {
            $this->components->info(sprintf('Sent %d overdue %s.', $reminders, $reminders === 1 ? 'reminder' : 'reminders'));
        }

        if ($result['stranded'] !== []) {
            $this->components->warn(sprintf(
                'Detected %d newly stranded %s.',
                count($result['stranded']),
                count($result['stranded']) === 1 ? 'stage' : 'stages',
            ));
        }

        foreach ($result['failed'] as $stage => $message) {
            $this->components->error("Stage {$stage} could not be escalated: {$message}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
