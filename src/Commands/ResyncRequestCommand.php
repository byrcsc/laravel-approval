<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Commands;

use ByRcsc\LaravelApproval\Actions\ResyncRequestApprovers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The runbook entry for "an approver left and their requests are stuck".
 *
 * Deliberately a command rather than anything automatic: rebuilding a live
 * request's approver assignments is a decision somebody makes. The operator is
 * a required, resolvable identity recorded on the audit trail - shell history
 * is not attribution.
 */
final class ResyncRequestCommand extends Command
{
    protected $signature = 'approval:resync
        {request : The request ULID, or its numeric id}
        {--operator= : Who this rebuild is attributed to: a key of the configured user model, or type:id}
        {--prune : Also drop approvers the workflow no longer names}';

    protected $description = "Re-run a pending request's approver rules against the current workflow";

    public function handle(ResyncRequestApprovers $resync): int
    {
        // Read through the raw input: `argument()` types differently depending
        // on whether static analysis could boot the application, and this has
        // to hold in environments where it cannot.
        $argument = $this->input->getArgument('request');
        $identifier = is_scalar($argument) ? (string) $argument : '';
        $request = $this->findRequest($identifier);

        if ($request === null) {
            $this->components->error("No approval request found for [{$identifier}].");

            return self::FAILURE;
        }

        $operator = $this->operator();

        if ($operator === null) {
            return self::FAILURE;
        }

        $changes = $resync($request, $operator, (bool) $this->option('prune'));

        if ($changes === []) {
            $this->components->info('Nothing to change: the request already matches its workflow.');

            return self::SUCCESS;
        }

        foreach ($changes as $sequence => $change) {
            $this->components->twoColumnDetail(
                "Stage {$sequence}",
                sprintf('+%d / -%d', count($change['added']), count($change['removed'])),
            );

            foreach ($change['added'] as $key) {
                $this->line("    <fg=green>+</> {$key}");
            }

            foreach ($change['removed'] as $key) {
                $this->line("    <fg=red>-</> {$key}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * The attributable operator, or null after printing why there is none.
     * Accepts a key of the configured user model, or an explicit `type:id`
     * pair for any other model.
     */
    private function operator(): ?Model
    {
        $option = $this->option('operator');
        $identifier = is_scalar($option) ? (string) $option : '';

        if ($identifier === '') {
            $this->components->error('An attributable operator is required. Pass --operator with a user key or type:id.');

            return null;
        }

        [$class, $key] = $this->operatorReference($identifier);

        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->components->error("Operator [{$identifier}] does not name an Eloquent model.");

            return null;
        }

        $operator = (new $class)->newQuery()->whereKey($key)->first();

        if (! $operator instanceof Model) {
            $this->components->error("No operator found for [{$identifier}].");

            return null;
        }

        return $operator;
    }

    /**
     * @return array{string|null, string}
     */
    private function operatorReference(string $identifier): array
    {
        if (str_contains($identifier, ':')) {
            [$type, $key] = explode(':', $identifier, 2);

            return [Relation::getMorphedModel($type) ?? $type, $key];
        }

        /** @var mixed $configured */
        $configured = config('approval.user_model');

        return [is_string($configured) ? $configured : null, $identifier];
    }
}
