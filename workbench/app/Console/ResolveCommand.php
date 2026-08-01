<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Clears a conflicted request.
 *
 * A request becomes conflicted when its draft cleared every stage but one of the
 * drafted attributes had changed on the record since submission. Nothing is
 * applied, no completion timestamp is written, and no approved notification is
 * sent: the approvals were given against values that no longer hold, and only a
 * person can say whether they still mean what they meant.
 *
 * There are exactly two resolutions and no merge, because choosing between two
 * competing changes needs meaning the package does not have.
 *
 * To produce one: submit a draft, then change the same attribute on the record
 * behind the request's back - `demo:edit --bypass` does that under guard mode -
 * and approve the last stage.
 */
final class ResolveCommand extends Command
{
    protected $signature = 'demo:resolve
        {request : A conflicted request ULID or id}
        {--as= : Who is resolving it}
        {--discard : Discard the draft instead of reapplying it}
        {--comment= : A note for the audit trail}';

    protected $description = 'Reapply or discard the draft of a conflicted request';

    public function handle(): int
    {
        $identifier = $this->argument('request');
        $request = $this->request(is_scalar($identifier) ? (string) $identifier : '');

        if ($request === null) {
            return self::FAILURE;
        }

        $actor = $this->person($this->text('as'));

        if ($actor === null) {
            return self::FAILURE;
        }

        if ($request->getAttributeValue('state') !== RequestState::Conflicted) {
            $this->components->error(sprintf(
                'That request is %s, not conflicted.',
                (string) ($request->getAttributeValue('state')?->value ?? '?'),
            ));

            return self::FAILURE;
        }

        $this->reportConflict($request);

        $resolution = $this->option('discard') === true
            ? ConflictResolution::Discard
            : ConflictResolution::Reapply;

        try {
            $request = Approval::resolve($request, $resolution, $actor, $this->text('comment'));
        } catch (ApprovalException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Resolved by %s; the request is now %s.',
            $resolution->value,
            (string) ($request->getAttributeValue('state')?->value ?? '?'),
        ));

        return self::SUCCESS;
    }

    /**
     * What the two resolutions would do, which is what an administrator needs
     * in front of them before choosing.
     */
    private function reportConflict(ApprovalRequest $request): void
    {
        $draft = Draft::fromRequest($request);
        $record = $request->approvable;

        if ($draft === null || $record === null) {
            return;
        }

        $this->components->info('The record changed while approval was pending');

        foreach ($draft->changes() as $attribute => $change) {
            $this->components->twoColumnDetail(
                $attribute,
                sprintf(
                    'submitted against %s, now %s, draft says %s',
                    $this->scalar($change['from']),
                    $this->scalar($record->getAttributeValue($attribute)),
                    $this->scalar($change['to']),
                ),
            );
        }

        $this->newLine();
    }
}
