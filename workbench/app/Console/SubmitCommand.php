<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\PurchaseOrder;

/**
 * Puts a record under approval, which in a real application is a controller
 * action behind a "Submit for approval" button.
 *
 * Three things are worth watching here.
 *
 * **The draft.** Passing proposed attributes holds them on the request instead
 * of writing them to the record. Passing none - `--record` - submits the record
 * itself, and approving applies nothing.
 *
 * **Workflow choice.** `Approval::workflowsFor()` is printed before anything is
 * submitted, which is the query a form builds its choice from. It judges the
 * draft, not the record, so raising an amount can move the submission to a
 * different workflow before it has changed anything.
 *
 * **Revision.** `--revise` resubmits a closed request as a new linked one,
 * starting from a fresh workflow snapshot rather than reopening the old request.
 */
final class SubmitCommand extends Command
{
    protected $signature = 'demo:submit
        {record? : A purchase order reference, or an article slug; omitted when revising}
        {--as= : Who is submitting}
        {--vendor= : Propose a new vendor}
        {--amount= : Propose a new amount, in minor units}
        {--currency= : Propose a new currency}
        {--record : Submit the record itself, holding no draft}
        {--workflow= : Name a workflow outright, bypassing the choice}
        {--comment= : A note for the audit trail}
        {--revise= : Resubmit this closed request instead of submitting fresh}';

    protected $description = 'Submit a record for approval, optionally with the attribute changes it is for';

    public function handle(): int
    {
        $requester = $this->person($this->text('as'));

        if ($requester === null) {
            return self::FAILURE;
        }

        $revising = $this->text('revise');

        if ($revising !== null) {
            return $this->revise($revising, $requester);
        }

        $identifier = $this->argument('record');

        if (! is_scalar($identifier) || (string) $identifier === '') {
            $this->components->error('Name a purchase order reference or article slug, or pass --revise.');

            return self::FAILURE;
        }

        $record = $this->approvable((string) $identifier);

        if ($record === null) {
            return self::FAILURE;
        }

        $draft = $this->draft($record);

        $this->reportCandidates($record, $requester, $draft);

        try {
            $request = Approval::submit(
                approvable: $record,
                workflow: $this->text('workflow'),
                newAttributes: $draft,
                requester: $requester,
                comment: $this->text('comment'),
                metadata: ['channel' => 'console'],
            );
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportSubmission($request);

        return self::SUCCESS;
    }

    /**
     * The proposed attribute values, or null for a record-level request.
     *
     * A purchase order's draft is limited to what `approvableAttributes()`
     * allows; proposing `status` here would be refused by the package rather
     * than silently dropped.
     *
     * @return array<string, mixed>|null
     */
    private function draft(Model $record): ?array
    {
        if ($this->option('record') === true || ! $record instanceof PurchaseOrder) {
            return null;
        }

        $draft = [];

        foreach (['vendor', 'currency'] as $attribute) {
            $value = $this->text($attribute);

            if ($value !== null) {
                $draft[$attribute] = $value;
            }
        }

        $amount = $this->text('amount');

        if ($amount !== null) {
            $draft['amount'] = (int) $amount;
        }

        // No proposed change is a record-level request, not an empty edit. The
        // two are different states and the package keeps them apart.
        return $draft === [] ? null : $draft;
    }

    /**
     * @param  array<string, mixed>|null  $draft
     */
    private function reportCandidates(Model $record, Model $requester, ?array $draft): void
    {
        $candidates = Approval::workflowsFor($record, $requester, $draft);

        $this->components->info(sprintf(
            'Applicable workflows for %s, best first',
            $this->label($record),
        ));

        if ($candidates->isEmpty()) {
            $this->components->warn('None. The submission below will fail unless --workflow names one.');

            return;
        }

        foreach ($candidates as $workflow) {
            $this->components->twoColumnDetail(
                (string) $workflow->getAttributeValue('slug'),
                sprintf('priority %s', $this->scalar($workflow->getAttributeValue('priority'))),
            );
        }
    }

    private function revise(string $identifier, Model $requester): int
    {
        $previous = $this->request($identifier);

        if ($previous === null) {
            return self::FAILURE;
        }

        // Three distinct choices, and null cannot carry all of them: keep the
        // old draft, replace it, or drop it and submit the record itself.
        $approvable = $previous->approvable;
        $replacement = $approvable instanceof Model ? $this->draft($approvable) : null;

        $draft = match (true) {
            $this->option('record') === true => RevisedDraft::none(),
            $replacement !== null => RevisedDraft::withAttributes($replacement),
            default => RevisedDraft::inherit(),
        };

        try {
            $request = Approval::reviseAndResubmit($previous, $draft, $requester, $this->text('workflow'));
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Resubmitted %s as a new request; the original keeps its own history.',
            $this->reference($previous),
        ));

        $this->reportSubmission($request);

        return self::SUCCESS;
    }

    private function reportSubmission(ApprovalRequest $request): void
    {
        $workflow = $request->workflow;

        $this->newLine();
        $this->components->info('Submitted');
        $this->components->twoColumnDetail('reference', $this->reference($request));
        $this->components->twoColumnDetail(
            'workflow',
            $workflow instanceof ApprovalWorkflow ? (string) $workflow->getAttributeValue('slug') : '-',
        );

        // Stages, their conditions, and the resolved assignments are all frozen
        // onto the request now. Editing the workflow after this point changes
        // nothing about what follows.
        foreach ($request->stages as $stage) {
            $holders = $stage->assignments->map(
                fn ($assignment): string => $this->label($assignment->approver),
            )->implode(', ');

            $this->components->twoColumnDetail(
                sprintf('  %d. %s', $stage->getAttributeValue('sequence'), $stage->getAttributeValue('name')),
                sprintf(
                    '%s%s',
                    (string) ($stage->getAttributeValue('state')?->value ?? '?'),
                    $holders === '' ? '' : ' - '.$holders,
                ),
            );
        }

        $this->newLine();
        $this->line("  Next: <fg=cyan>vendor/bin/testbench demo:show {$this->reference($request)}</>");
    }
}
