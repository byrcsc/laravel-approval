<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use Illuminate\Database\Eloquent\Model;

final class LevelBuilder
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(string $name, int $sequence)
    {
        $this->definition = [
            'name' => $name,
            'sequence' => $sequence,
            'required_approvals' => null,
            'rejection_policy' => RejectionPolicy::ApprovalImpossible,
            'approvers' => [],
        ];
    }

    public function minimumApprovals(?int $required): self
    {
        $this->definition['required_approvals'] = $required;

        return $this;
    }

    public function quorum(?int $required): self
    {
        $this->minimumApprovals($required);

        return $this;
    }

    public function rejectionPolicy(RejectionPolicy $policy): self
    {
        $this->definition['rejection_policy'] = $policy;

        return $this;
    }

    public function approver(Model $approver): self
    {
        $approvers = $this->approvers();
        $approvers[] = $approver;
        $this->definition['approvers'] = $approvers;

        return $this;
    }

    /**
     * @param  class-string  $resolver
     * @param  array<string, mixed>  $config
     */
    public function resolver(string $resolver, array $config = []): self
    {
        $approvers = $this->approvers();
        $approvers[] = [
            'resolver' => $resolver,
            'resolver_config' => $config,
        ];
        $this->definition['approvers'] = $approvers;

        return $this;
    }

    public function sla(?int $minutes): self
    {
        $this->definition['sla_minutes'] = $minutes;

        return $this;
    }

    public function remindersEvery(?int $minutes): self
    {
        $this->definition['remind_every_minutes'] = $minutes;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function escalate(EscalationAction $strategy, array $config = []): self
    {
        $this->definition['escalation_strategy'] = $strategy;
        $this->definition['escalation_config'] = $config;

        return $this;
    }

    /**
     * @param  class-string  $condition
     * @param  array<string, mixed>  $config
     */
    public function when(string $condition, array $config = []): self
    {
        $this->definition['condition'] = $condition;
        $this->definition['condition_config'] = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }

    /**
     * @return list<mixed>
     */
    private function approvers(): array
    {
        $approvers = $this->definition['approvers'] ?? [];

        return is_array($approvers) ? array_values($approvers) : [];
    }
}
