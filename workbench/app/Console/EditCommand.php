<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Exceptions\ApprovalGuardException;
use Workbench\App\Models\PurchaseOrder;

/**
 * Edits a purchase order directly, which is how the demo shows guard mode doing
 * its job and then getting out of the way.
 *
 * `PurchaseOrder` runs in guard mode. While a request holds a draft, an ordinary
 * `save()` touching one of the drafted attributes throws - the point being that
 * an approval in flight is about specific values, and moving them underneath it
 * makes the approvals meaningless.
 *
 * `--bypass` runs the same write through `withoutApprovalGuard()`, the
 * operational escape hatch. The bypass is appended to the open request's audit
 * trail, so a correction made this way is a recorded fact rather than an
 * invisible one.
 *
 * Two things guard mode does not do, both demonstrable here. `status` is not a
 * drafted attribute, so writing it is never blocked. And the guard only sees
 * Eloquent model events - `--raw` goes through the query builder and sails
 * past, which is what the stale check before draft application exists to catch.
 */
final class EditCommand extends Command
{
    protected $signature = 'demo:edit
        {reference : A purchase order reference}
        {--vendor= : Write a new vendor}
        {--amount= : Write a new amount, in minor units}
        {--status= : Write a new status, which no draft may touch}
        {--as= : Who the bypass is attributed to}
        {--comment= : The reason recorded with a bypass}
        {--bypass : Write through withoutApprovalGuard() instead of being refused}
        {--raw : Write through the query builder, which dispatches no model events}';

    protected $description = 'Edit a purchase order directly, to see guard mode refuse it or record the bypass';

    public function handle(): int
    {
        $reference = $this->argument('reference');
        $order = PurchaseOrder::query()
            ->where('reference', is_scalar($reference) ? (string) $reference : '')
            ->first();

        if (! $order instanceof PurchaseOrder) {
            $this->components->error("No purchase order found for [{$reference}].");

            return self::FAILURE;
        }

        $changes = $this->changes();

        if ($changes === []) {
            $this->components->error('Nothing to write. Pass --vendor, --amount, or --status.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            'pending draft',
            $order->pendingDraft() === null
                ? '<fg=gray>none</>'
                : implode(', ', $order->pendingDraft()->keys()),
        );

        if ($this->option('raw') === true) {
            PurchaseOrder::query()->whereKey($order->getKey())->update($changes);

            $this->components->warn(
                'Written through the query builder. No model event fired, so guard mode never saw it - '
                .'the stale check before the draft is applied is what catches this.'
            );

            return self::SUCCESS;
        }

        try {
            if ($this->option('bypass') === true) {
                $actor = $this->person($this->text('as'));

                if ($actor === null) {
                    return self::FAILURE;
                }

                $order->withoutApprovalGuard(
                    fn (PurchaseOrder $subject): bool => $subject->forceFill($changes)->save(),
                    $actor,
                    $this->text('comment') ?? 'Corrected while approval was pending.',
                );

                $this->components->info('Written, and the bypass is on the request\'s audit trail.');

                return self::SUCCESS;
            }

            $order->fill($changes)->save();
        } catch (ApprovalGuardException $exception) {
            $this->components->error($exception->getMessage());
            $this->line('  <fg=gray>Add --bypass to write it anyway, on the record.</>');

            return self::FAILURE;
        }

        $this->components->info('Written.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(): array
    {
        $changes = [];

        foreach (['vendor', 'status'] as $attribute) {
            $value = $this->text($attribute);

            if ($value !== null) {
                $changes[$attribute] = $value;
            }
        }

        $amount = $this->text('amount');

        if ($amount !== null) {
            $changes['amount'] = (int) $amount;
        }

        return $changes;
    }
}
