<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Data\ApproverSlot;
use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Bring somebody else in - the approver's manager, the duty officer, whoever
 * the level's config names.
 *
 * Configured on the level, in `escalation_config`:
 *
 *     ['resolver' => App\Org\ManagerOf::class, 'resolver_config' => [...]]
 *
 * There is a trap here worth knowing about. A level with a null
 * `required_approvals` needs *all* its seats, so adding one would make an
 * already-late level harder to carry - the opposite of escalating it. So before
 * adding anyone, a null quorum is pinned to the number of seats the level
 * already had. The people brought in are alternatives to the ones who did not
 * act, not extra hurdles. Set `required_approvals` in the config to say
 * something different.
 *
 * Existing seats are left alone. Somebody who is merely slow may still be about
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
        ApprovalRequestStep $step,
        EscalationAction $action,
        array $config,
    ): void {
        $resolver = $this->resolver($config, $step);
        $approvable = $request->approvable;
        $submitter = $request->submitter;

        if (! $approvable instanceof Model || ! $submitter instanceof Model) {
            return;
        }

        $seated = $this->seated($step);
        $before = $step->approverSlots()->count();
        $health = StepHealth::inspect($step);

        $added = 0;

        /** @var mixed $approver */
        foreach ($resolver->resolve(LevelSnapshot::fromStep($step), $approvable, $submitter, Payload::object($config, 'resolver_config') ?? []) as $approver) {
            if (! $approver instanceof Model || ! $approver->exists) {
                continue;
            }

            $slot = ApproverSlot::forModel($approver, ApproverSource::Escalated);

            if (isset($seated[$slot->key()])) {
                continue;
            }

            $seated[$slot->key()] = true;

            // Pinned lazily: a resolver that turns out to add nobody should not
            // silently rewrite the level's quorum on its way past.
            if ($added === 0) {
                $this->pinQuorum($step, $before, $config);
            }

            $step->approverSlots()->create($slot->toAttributes());

            $added++;
        }

        if ($added > 0) {
            StepHealth::assertNotWorsened($step, $request->workflowName(), $health);
        }
    }

    /**
     * Freezes "all of them" at the count it meant a moment ago, unless the
     * level's config states a number outright.
     *
     * @param  array<string, mixed>  $config
     */
    private function pinQuorum(ApprovalRequestStep $step, int $before, array $config): void
    {
        $override = Payload::nullableInt($config, 'required_approvals');

        if ($override !== null && $override > 0) {
            $step->forceFill(['required_approvals' => $override])->save();

            return;
        }

        if (Attributes::nullableInt($step, 'required_approvals') === null && $before > 0) {
            $step->forceFill(['required_approvals' => $before])->save();
        }
    }

    /**
     * @return array<string, true>
     */
    private function seated(ApprovalRequestStep $step): array
    {
        return Ballot::for($step)->representedIdentities();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolver(array $config, ApprovalRequestStep $step): ApproverResolver
    {
        $class = Payload::nullableString($config, 'resolver');
        $sequence = Attributes::int($step, 'sequence');

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
