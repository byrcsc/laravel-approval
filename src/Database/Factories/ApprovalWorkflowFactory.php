<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApprovalWorkflow>
 */
final class ApprovalWorkflowFactory extends Factory
{
    protected $model = ApprovalWorkflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'active' => true,
            'settings' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }

    public function withStages(int $count = 1): self
    {
        return $this->has(
            ApprovalWorkflowStageFactory::new()
                ->withApprovers()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => [
                    'sequence' => $sequence->index + 1,
                    'name' => 'Stage '.($sequence->index + 1),
                ]),
            'stages',
        );
    }
}
