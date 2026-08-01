<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\Team;

/**
 * @extends Factory<Team>
 */
final class TeamFactory extends Factory
{
    /** @var class-string<Team> */
    protected $model = Team::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'lead_id' => null,
        ];
    }
}
