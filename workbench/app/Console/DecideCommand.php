<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\BulkDecisionOutcome;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Workbench\App\Models\User;

/**
 * Records a decision, which is the one thing an approval package exists to do.
 *
 * Written the way a single controller action serving several buttons is: the
 * verdict arrives as data and is dispatched through `Approval::decide()`. An
 * application with one button per verdict would call `approve()`, `reject()`,
 * or `returnForRevision()` directly and should.
 *
 * The gate is checked first through Laravel's own `can()`, which reaches the
 * demo's policy, which extends the package's. Deciding without checking is not
 * unsafe - every decision re-runs the same eligibility calculation and throws -
 * but an interface wants the answer before it draws the button.
 *
 * `--inbox` decides everything waiting on that person at once. Bulk decisions
 * isolate failures: one request that cannot be decided does not roll back the
 * rest, and the result says which was which.
 */
final class DecideCommand extends Command
{
    protected $signature = 'demo:decide
        {request? : A request ULID or id}
        {--as= : Who is deciding}
        {--verdict=approve : approve, reject, return, withdraw, or cancel}
        {--comment= : Required for reject and return}
        {--inbox : Decide every request waiting on this person}';

    protected $description = 'Approve, reject, return, withdraw, or cancel an approval request';

    public function handle(): int
    {
        $actor = $this->person($this->text('as'));

        if ($actor === null) {
            return self::FAILURE;
        }

        $verdict = $this->text('verdict') ?? 'approve';

        if ($this->option('inbox') === true) {
            return $this->decideInbox($actor, $verdict);
        }

        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');

        if ($request === null) {
            return self::FAILURE;
        }

        try {
            $request = $this->decide($request, $actor, $verdict);
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportOutcome($request);

        return self::SUCCESS;
    }

    private function decide(ApprovalRequest $request, User $actor, string $verdict): ApprovalRequest
    {
        $comment = $this->text('comment');

        $this->reportGate($request, $actor, $verdict);

        return match ($verdict) {
            // Withdrawal is the requester's, cancellation is administrative;
            // neither is a stage decision, so neither goes through `decide()`.
            'withdraw' => Approval::withdraw($request, $actor, $comment),
            'cancel' => Approval::cancel($request, $actor, $comment),
            default => Approval::decide($request, $this->decision($actor, $verdict, $comment)),
        };
    }

    private function decision(User $actor, string $verdict, ?string $comment): ApprovalDecision
    {
        return match ($verdict) {
            'reject' => ApprovalDecision::reject($actor, $comment ?? 'Rejected from the demo console.'),
            'return' => ApprovalDecision::return($actor, $comment ?? 'Returned from the demo console.'),
            'approve' => ApprovalDecision::approve($actor, $comment),
            default => throw new \InvalidArgumentException(
                "Unknown verdict [{$verdict}]. Use approve, reject, return, withdraw, or cancel.",
            ),
        };
    }

    /**
     * What the interface would ask before drawing the control.
     */
    private function reportGate(ApprovalRequest $request, User $actor, string $verdict): void
    {
        $ability = match ($verdict) {
            'reject' => 'reject',
            'return' => 'returnForRevision',
            'withdraw' => 'withdraw',
            'cancel' => 'cancel',
            default => 'approve',
        };

        $this->components->twoColumnDetail(
            sprintf('policy: %s may %s', $this->label($actor), $ability),
            $actor->can($ability, $request) ? '<fg=green>yes</>' : '<fg=yellow>no</>',
        );
    }

    private function decideInbox(User $actor, string $verdict): int
    {
        if (! in_array($verdict, [Verdict::Approve->value, Verdict::Reject->value], true)) {
            $this->components->error('Bulk decisions cover approve and reject only.');

            return self::FAILURE;
        }

        $requests = Approval::awaiting($actor)->get();

        if ($requests->isEmpty()) {
            $this->components->info('Nothing is waiting on this person.');

            return self::SUCCESS;
        }

        $comment = $this->text('comment');

        $result = $verdict === Verdict::Reject->value
            ? Approval::rejectMany($requests, $actor, $comment ?? 'Rejected in bulk from the demo console.')
            : Approval::approveMany($requests, $actor, $comment);

        foreach ($result->outcomes as $outcome) {
            $this->reportBulkOutcome($outcome);
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d decided, %d skipped.',
            count($result->successful()),
            count($result->failed()),
        ));

        return $result->failed() === [] ? self::SUCCESS : self::FAILURE;
    }

    private function reportBulkOutcome(BulkDecisionOutcome $outcome): void
    {
        $this->components->twoColumnDetail(
            $this->reference($outcome->request),
            $outcome->successful()
                ? '<fg=green>decided</>'
                : '<fg=yellow>skipped</> - '.($outcome->exception?->getMessage() ?? 'unknown'),
        );
    }

    private function reportOutcome(ApprovalRequest $request): void
    {
        $request->refresh();

        $state = $request->getAttributeValue('state');

        $this->components->info(sprintf(
            '%s is now %s.',
            $this->reference($request),
            (string) ($state?->value ?? '?'),
        ));

        $progress = $request->stageProgress();

        if ($progress !== null) {
            $this->components->twoColumnDetail(
                'current stage',
                sprintf('%d of %d approvals', $progress->approvalsReceived, $progress->approvalsRequired),
            );
        }

        $waiting = $request->pendingApprovers()->map(fn ($approver): string => $this->label($approver));

        if ($waiting->isNotEmpty()) {
            $this->components->twoColumnDetail('waiting on', $waiting->implode(', '));
        }
    }
}
