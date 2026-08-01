<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\PurchaseOrder;
use Workbench\App\Models\Team;

/**
 * @extends Factory<PurchaseOrder>
 */
final class PurchaseOrderFactory extends Factory
{
    /** @var class-string<PurchaseOrder> */
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'PO-'.$this->faker->unique()->numberBetween(1_000, 9_999),
            'vendor' => $this->faker->company(),
            // Minor units.
            'amount' => $this->faker->numberBetween(10_000, 400_000),
            'currency' => 'EUR',
            'status' => 'draft',
            'team_id' => Team::query()->value('id') ?? Team::factory(),
        ];
    }
}
