<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use Closure;
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
