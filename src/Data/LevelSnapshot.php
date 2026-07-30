<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Support\Attributes;

/**
 * A workflow level, lifted out of the definition so it can be frozen onto a
 * request. This is the only thing that crosses from the editable side of the
 * schema to the immutable one, which is what makes "edit the workflow, in-flight
 * requests keep their old rules" true rather than aspirational.
 */
final readonly class LevelSnapshot
{
    /**
     * @param  array<string, mixed>  $escalationConfig
     * @param  array<string, mixed>  $conditionConfig
     */
    public function __construct(
        public int $sequence,
        public string $name,
        public ?int $requiredApprovals,
        public RejectionPolicy $rejectionPolicy,
        public ?int $slaMinutes,
        public ?EscalationAction $escalationStrategy,
        public array $escalationConfig = [],
        public ?int $remindEveryMinutes = null,
        public ?string $condition = null,
        public array $conditionConfig = [],
        public bool $conditionPassed = true,
    ) {}

    public static function fromLevel(ApprovalWorkflowLevel $level): self
    {
        return new self(
            sequence: Attributes::int($level, 'sequence'),
            name: Attributes::string($level, 'name'),
            requiredApprovals: Attributes::nullableInt($level, 'required_approvals'),
            rejectionPolicy: self::rejectionPolicy($level),
            slaMinutes: Attributes::nullableInt($level, 'sla_minutes'),
            escalationStrategy: Attributes::enum($level, 'escalation_strategy', EscalationAction::class),
            escalationConfig: Attributes::object($level, 'escalation_config'),
            remindEveryMinutes: Attributes::nullableInt($level, 'remind_every_minutes'),
            condition: Attributes::nullableString($level, 'condition'),
            conditionConfig: Attributes::object($level, 'condition_config'),
        );
    }

    /**
     * Reads the snapshot back off a step, so code that already has the frozen
     * row does not have to reach for the level it came from.
     */
    public static function fromStep(ApprovalRequestStep $step): self
    {
        return new self(
            sequence: Attributes::int($step, 'sequence'),
            name: Attributes::string($step, 'name'),
            requiredApprovals: Attributes::nullableInt($step, 'required_approvals'),
            rejectionPolicy: self::rejectionPolicy($step),
            slaMinutes: Attributes::nullableInt($step, 'sla_minutes'),
            escalationStrategy: Attributes::enum($step, 'escalation_strategy', EscalationAction::class),
            escalationConfig: Attributes::object($step, 'escalation_config'),
            remindEveryMinutes: Attributes::nullableInt($step, 'remind_every_minutes'),
            condition: Attributes::nullableString($step, 'condition'),
            conditionConfig: Attributes::object($step, 'condition_config'),
            conditionPassed: Attributes::bool($step, 'condition_result'),
        );
    }

    public function withConditionResult(bool $passed): self
    {
        return new self(
            sequence: $this->sequence,
            name: $this->name,
            requiredApprovals: $this->requiredApprovals,
            rejectionPolicy: $this->rejectionPolicy,
            slaMinutes: $this->slaMinutes,
            escalationStrategy: $this->escalationStrategy,
            escalationConfig: $this->escalationConfig,
            remindEveryMinutes: $this->remindEveryMinutes,
            condition: $this->condition,
            conditionConfig: $this->conditionConfig,
            conditionPassed: $passed,
        );
    }

    /**
     * How many approvals this level needs, given how many seats it ended up
     * with. A null quorum means all of them, which can only be answered once
     * the resolvers have run.
     */
    public function quorumFor(int $slots): int
    {
        return $this->requiredApprovals ?? $slots;
    }

    /**
     * Steps are born queued; activating one is a transition of its own.
     *
     * @return array<string, mixed>
     */
    public function toStepAttributes(): array
    {
        return [
            'sequence' => $this->sequence,
            'name' => $this->name,
            'required_approvals' => $this->requiredApprovals,
            'rejection_policy' => $this->rejectionPolicy,
            'sla_minutes' => $this->slaMinutes,
            'escalation_strategy' => $this->escalationStrategy,
            'escalation_config' => $this->escalationConfig === [] ? null : $this->escalationConfig,
            'remind_every_minutes' => $this->remindEveryMinutes,
            'condition' => $this->condition,
            'condition_config' => $this->conditionConfig === [] ? null : $this->conditionConfig,
            'condition_result' => $this->conditionPassed,
            'state' => $this->conditionPassed ? StepState::Queued : StepState::Skipped,
        ];
    }

    private static function rejectionPolicy(ApprovalWorkflowLevel|ApprovalRequestStep $stage): RejectionPolicy
    {
        $value = $stage->getRawOriginal('rejection_policy');

        if ($value === null) {
            return RejectionPolicy::ApprovalImpossible;
        }

        if (! is_string($value)) {
            throw new \UnexpectedValueException('The rejection policy must be a string.');
        }

        return RejectionPolicy::tryFrom($value)
            ?? throw new \UnexpectedValueException("Unknown rejection policy [{$value}].");
    }
}
