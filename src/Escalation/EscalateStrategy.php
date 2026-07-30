<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Data\AssignmentSnapshot;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Events\ApproversEscalated;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Bring somebody else in - the approver's manager, the duty officer, whoever
 * the stage's config names.
 *
 * Configured on the stage, in `escalation_config`:
 *
 *     ['resolver' => App\Org\ManagerOf::class, 'resolver_config' => [...]]
 *
 * There is a trap here worth knowing about. A stage with a null
 * `required_approvals` needs *all* its assignments, so adding one would make an
 * already-late stage harder to complete - the opposite of escalating it. So
 * before adding anyone, a null rule is pinned to the number of assignments the stage
 * already had. The people brought in are alternatives to the ones who did not
 * act, not extra hurdles. Set `required_approvals` in the config to say
 * something different.
 *
 * Existing assignments are left alone. Somebody who is merely slow may still be about
 * to approve, and taking the request off their desk mid-thought helps nobody.
 */
final class EscalateStrategy implements EscalationStrategy
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void {
        $resolver = $this->resolver($config, $stage);
        $approvable = $request->approvable;
        $submitter = $request->submitter;

        if (! $approvable instanceof Model || ! $submitter instanceof Model) {
            return;
        }

        $represented = $this->representedIdentities($stage);
        $before = $stage->assignments()->count();
        $health = StageHealth::inspect($stage);

        $added = 0;
        $created = [];

        /** @var mixed $approver */
        foreach ($resolver->resolve(StageSnapshot::fromRequestStage($stage), $approvable, $submitter, Payload::object($config, 'resolver_config') ?? []) as $approver) {
            if (! $approver instanceof Model || ! $approver->exists) {
                continue;
            }

            $assignment = AssignmentSnapshot::forModel($approver, ApproverSource::Escalated);

            if (isset($represented[$assignment->key()])) {
                continue;
            }

            $represented[$assignment->key()] = true;

            // Pinned lazily: a resolver that turns out to add nobody should not
            // silently rewrite the stage's approval rule on its way past.
            if ($added === 0) {
                $this->pinRequiredApprovals($stage, $before, $config);
            }

            $created[] = $stage->assignments()->create($assignment->toAttributes());

            $added++;
        }

        if ($added > 0) {
            StageHealth::assertNotWorsened($stage, $request->workflowSlug(), $health);

            // Dispatched after the assignments exist, so recipient calculation
            // can only ever see the new holders.
            Event::dispatch(new ApproversEscalated($request, $stage, $created));
        }
    }

    /**
     * Freezes "all of them" at the count it meant a moment ago, unless the
     * stage's config states a number outright.
     *
     * @param  array<string, mixed>  $config
     */
    private function pinRequiredApprovals(ApprovalRequestStage $stage, int $before, array $config): void
    {
        $override = Payload::nullableInt($config, 'required_approvals');

        if ($override !== null && $override > 0) {
            $stage->forceFill(['required_approvals' => $override])->save();

            return;
        }

        if (Attributes::nullableInt($stage, 'required_approvals') === null && $before > 0) {
            $stage->forceFill(['required_approvals' => $before])->save();
        }
    }

    /**
     * @return array<string, true>
     */
    private function representedIdentities(ApprovalRequestStage $stage): array
    {
        return StageProgress::for($stage)->representedApproverIdentities();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolver(array $config, ApprovalRequestStage $stage): ApproverResolver
    {
        $class = Payload::nullableString($config, 'resolver');
        $sequence = Attributes::int($stage, 'sequence');

        if ($class === null || $class === '') {
            throw InvalidWorkflowException::escalationNeedsResolver($sequence);
        }

        if (! class_exists($class)) {
            throw InvalidWorkflowException::unknownResolver('escalation', $sequence, $class);
        }

        if (! is_subclass_of($class, ApproverResolver::class)) {
            throw InvalidWorkflowException::notAResolver('escalation', $sequence, $class);
        }

        /** @var ApproverResolver */
        return $this->container->make($class);
    }
}
