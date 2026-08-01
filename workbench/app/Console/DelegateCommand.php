<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Workbench\App\Models\User;

/**
 * Hands one approver's assignment to somebody else, and its administrative
 * counterpart.
 *
 * The two are deliberately separate operations. `delegate` is the approver
 * acting for themselves - "I am away, Alan is covering" - and the assignment
 * moves to the delegate, who is then the only person it appears for.
 * `--reassign` is somebody else rewriting the assignment because the approver
 * cannot do it themselves, which is why it names an operator and works on any
 * undecided stage rather than only the active one.
 *
 * Both are appended to the audit trail, which is the point: an approval that
 * changed hands should say so.
 */
final class DelegateCommand extends Command
{
    protected $signature = 'demo:delegate
        {request : A request ULID or id}
        {--from= : The approver whose assignment moves}
        {--to= : Who receives it}
        {--reassign : Rewrite the assignment administratively instead}
        {--as= : The operator, when reassigning}
        {--comment= : A note for the audit trail}';

    protected $description = "Delegate an approver's assignment, or reassign it administratively";

    public function handle(): int
    {
        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');
        $from = $this->person($this->text('from'));
        $to = $this->person($this->text('to'));

        if ($request === null || $from === null || $to === null) {
            return self::FAILURE;
        }

        try {
            if ($this->option('reassign') === true) {
                return $this->reassign($request, $from, $to);
            }

            $eligibility = $request->delegationEligibility($from, $to);

            if (! $eligibility->eligible) {
                $this->components->error(sprintf(
                    '%s cannot delegate this: %s.',
                    $this->label($from),
                    $eligibility->reason?->value ?? 'unknown',
                ));

                return self::FAILURE;
            }

            $assignment = Approval::delegate($request, $from, $to, $this->text('comment'));
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s delegated their assignment to %s.',
            $this->label($from),
            $this->label($to),
        ));
        $this->components->twoColumnDetail(
            'source',
            (string) ($assignment->getAttributeValue('source')?->value ?? '?'),
        );

        return self::SUCCESS;
    }

    private function reassign(ApprovalRequest $request, User $from, User $to): int
    {
        $operator = $this->person($this->text('as'));

        if ($operator === null) {
            return self::FAILURE;
        }

        // Any undecided stage, not only the active one - the point of the
        // administrative form is fixing a stage before it is reached.
        $assignment = $request->stages()
            ->with('assignments')
            ->get()
            ->flatMap(fn (ApprovalRequestStage $stage): mixed => $stage->assignments)
            ->first(fn (ApprovalRequestAssignment $candidate): bool => $candidate->getAttributeValue('approver_type') === $from->getMorphClass()
                && (string) $candidate->getAttributeValue('approver_id') === (string) $from->getKey());

        if ($assignment === null) {
            $this->components->error(sprintf('%s holds no assignment on this request.', $this->label($from)));

            return self::FAILURE;
        }

        Approval::reassign($request, $assignment, $to, $operator, $this->text('comment'));

        $this->components->info(sprintf(
            '%s reassigned %s\'s assignment to %s.',
            $this->label($operator),
            $this->label($from),
            $this->label($to),
        ));

        return self::SUCCESS;
    }
}
