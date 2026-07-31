<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class WorkflowBuilder
{
    /** @var array<string, mixed> */
    private array $definition;

    private function __construct(string $slug, string $name)
    {
        $this->definition = [
            'slug' => $slug,
            'name' => $name,
            'active' => true,
            'stages' => [],
        ];
    }

    public static function make(string $slug, ?string $name = null): self
    {
        return new self($slug, $name ?? Str::headline($slug));
    }

    public function description(?string $description): self
    {
        $this->definition['description'] = $description;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->definition['active'] = $active;

        return $this;
    }

    /**
     * Register this workflow against a model type, which is what lets a model
     * have more than one and lets the package choose between them.
     *
     * @param  Model|class-string<Model>  $approvable
     */
    public function forApprovable(Model|string $approvable): self
    {
        $this->definition['approvable_type'] = $approvable instanceof Model
            ? $approvable->getMorphClass()
            : $approvable;

        return $this;
    }

    /**
     * The condition that decides whether this workflow applies to a submission.
     * Only consulted while a workflow is being chosen.
     *
     * @param  class-string<WorkflowCondition>  $condition
     * @param  array<string, mixed>  $config
     */
    public function when(string $condition, array $config = []): self
    {
        $this->definition['condition'] = $condition;
        $this->definition['condition_config'] = $config === [] ? null : $config;

        return $this;
    }

    /**
     * Which of two applicable workflows wins. Highest first; equal priorities
     * are refused at submit time rather than resolved arbitrarily.
     */
    public function priority(int $priority): self
    {
        $this->definition['priority'] = $priority;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function settings(array $settings): self
    {
        $this->definition['settings'] = $settings;

        return $this;
    }

    /**
     * @param  Closure(StageBuilder): void  $configure
     */
    public function stage(string $name, Closure $configure): self
    {
        /** @var list<array<string, mixed>> $stages */
        $stages = $this->definition['stages'];
        $stage = new StageBuilder($name, count($stages) + 1);
        $configure($stage);
        $stages[] = $stage->toArray();
        $this->definition['stages'] = $stages;

        return $this;
    }

    public function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::from($this->definition);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }
}
