<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Data\TimelineEntry;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the package will tell you about one request: the read side of an
 * approval screen, in one place.
 *
 * Each block below maps to something a real interface renders - a status
 * header, a diff of the pending change, a stage timeline, an audit log, and the
 * reason a decision button is disabled.
 */
final class ShowCommand extends Command
{
    protected $signature = 'demo:show
        {request : A request ULID or id}
        {--as= : Also report whether this person may act on it}';

    protected $description = 'Show a request: state, draft, stage timeline, audit trail, and eligibility';

    public function handle(): int
    {
        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');

        if ($request === null) {
            return self::FAILURE;
        }

        $request->loadMissing(['approvable', 'requester', 'workflow']);

        $this->reportHeader($request);
        $this->reportDraft($request);
        $this->reportTimeline($request);
        $this->reportTrail($request);
        $this->reportEligibility($request);

        return self::SUCCESS;
    }

    private function reportHeader(ApprovalRequest $request): void
    {
        $state = $request->getAttributeValue('state');

        $this->components->info('Request');
        $this->components->twoColumnDetail('reference', $this->reference($request));
        $this->components->twoColumnDetail('state', (string) ($state?->value ?? '?'));
        $this->components->twoColumnDetail('workflow', $request->workflowSlug());
        $this->components->twoColumnDetail('record', $this->label($request->approvable));
        $this->components->twoColumnDetail('requested by', $this->label($request->requester));

        $progress = $request->stageProgress();

        if ($progress !== null) {
            $this->components->twoColumnDetail(
                'current stage',
                sprintf(
                    '%d of %d approvals, %d assigned',
                    $progress->approvalsReceived,
                    $progress->approvalsRequired,
                    $progress->assignedApprovers,
                ),
            );
        }
    }

    /**
     * The proposed edit, and the values it was proposed against.
     *
     * No draft is not an empty draft: a record-level request is one where the
     * record itself is what needed approving, and there is nothing to apply.
     */
    private function reportDraft(ApprovalRequest $request): void
    {
        $draft = Draft::fromRequest($request);

        $this->newLine();

        if ($draft === null) {
            $this->components->info('Draft: none - the record itself is what is under approval');

            return;
        }

        $this->components->info('Draft, held until the final approval');

        foreach ($draft->changes() as $attribute => $change) {
            $this->components->twoColumnDetail(
                $attribute,
                sprintf('%s <fg=gray>-></> %s', $this->scalar($change['from']), $this->scalar($change['to'])),
            );
        }
    }

    /**
     * The stage-by-stage view, off the request's own snapshot rather than off
     * the workflow - which is why editing the workflow does not change it.
     */
    private function reportTimeline(ApprovalRequest $request): void
    {
        $this->newLine();
        $this->components->info('Stages');

        $request->timeline()->each(function (TimelineEntry $entry): void {
            $progress = StageProgress::for($entry->stage);
            $approvers = $entry->approvers->map(fn (Model $approver): string => $this->label($approver));

            $this->components->twoColumnDetail(
                sprintf('%d. %s', $entry->stage->getAttributeValue('sequence'), $entry->stage->getAttributeValue('name')),
                sprintf(
                    '<fg=%s>%s</> %d/%d',
                    $this->tint($entry->state->value),
                    $entry->state->value,
                    $progress->approvalsReceived,
                    $progress->approvalsRequired,
                ),
            );

            if ($approvers->isNotEmpty()) {
                $this->line('    <fg=gray>assigned:</> '.$approvers->implode(', '));
            }

            $deadline = $entry->stage->getAttributeValue('deadline_at');

            if ($deadline !== null) {
                $this->line('    <fg=gray>deadline:</> '.$this->scalar((string) $deadline));
            }
        });
    }

    /**
     * The append-only, hash-chained history. Every line carries the hash of the
     * one before it, which is what `approval:verify` walks.
     */
    private function reportTrail(ApprovalRequest $request): void
    {
        $this->newLine();
        $this->components->info('Audit trail');

        foreach ($request->actions as $action) {
            $this->components->twoColumnDetail(
                (string) ($action->getAttributeValue('action')?->value ?? '?'),
                sprintf(
                    '%s%s',
                    $this->scalar($action->getAttributeValue('actor_label') ?? 'system'),
                    ($comment = $action->getAttributeValue('comment')) === null ? '' : ' - '.$this->scalar($comment),
                ),
            );
        }

        $attachments = $request->attachments;

        if ($attachments->isNotEmpty()) {
            $this->newLine();
            $this->components->info('Attachments');

            foreach ($attachments as $attachment) {
                $this->components->twoColumnDetail(
                    $this->scalar($attachment->getAttributeValue('name')),
                    $this->scalar($attachment->getAttributeValue('checksum')),
                );
            }
        }
    }

    /**
     * Why a decision button would be enabled or disabled. The same calculation a
     * decision runs, returned instead of thrown - so one call renders both the
     * control and the explanation beside it.
     */
    private function reportEligibility(ApprovalRequest $request): void
    {
        $identifier = $this->text('as');

        if ($identifier === null) {
            return;
        }

        $actor = $this->person($identifier);

        if ($actor === null) {
            return;
        }

        $this->newLine();
        $this->components->info(sprintf('As %s', $this->label($actor)));

        $eligibility = $request->approvalEligibility($actor);

        $this->components->twoColumnDetail(
            'may decide',
            $eligibility->eligible ? '<fg=green>yes</>' : '<fg=yellow>no</> - '.($eligibility->reason?->value ?? '?'),
        );
        $this->components->twoColumnDetail(
            'may withdraw',
            $request->canBeWithdrawnBy($actor) ? 'yes' : 'no - '.($request->withdrawalEligibility($actor)->reason?->value ?? '?'),
        );
        $this->components->twoColumnDetail(
            'may delegate',
            $request->canBeDelegatedBy($actor) ? 'yes' : 'no - '.($request->delegationEligibility($actor)->reason?->value ?? '?'),
        );

        // Through the demo's own policy, which extends the package's: `cancel`
        // is refused by the package and granted here to the executive team.
        $this->components->twoColumnDetail('may cancel (policy)', $actor->can('cancel', $request) ? 'yes' : 'no');
        $this->components->twoColumnDetail('may view (policy)', $actor->can('view', $request) ? 'yes' : 'no');
    }

    private function tint(string $state): string
    {
        return match ($state) {
            'approved' => 'green',
            'rejected', 'returned' => 'red',
            'active' => 'cyan',
            'skipped', 'condition_skipped' => 'gray',
            default => 'default',
        };
    }
}
