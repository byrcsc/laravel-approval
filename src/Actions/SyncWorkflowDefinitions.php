<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\WorkflowSyncResult;
use ByRcsc\LaravelApproval\Definitions\LevelDefinition;
use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Definitions\WorkflowDefinition;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevelApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SyncWorkflowDefinitions
{
    /**
     * @param  iterable<int|string, mixed>  $definitions
     */
    public function __invoke(iterable $definitions, bool $dryRun = false): WorkflowSyncResult
    {
        $normalized = $this->normalize($definitions);
        $plans = [];
        $created = [];
        $updated = [];
        $unchanged = [];
        $refused = [];

        foreach ($normalized as $definition) {
            $workflow = ApprovalWorkflow::withTrashed()
                ->with(['levels.approvers'])
                ->where('slug', $definition->slug)
                ->first();

            if ($workflow === null) {
                $created[] = "workflow:{$definition->slug}";
                $plans[] = fn (): ApprovalWorkflow => $this->create($definition);

                foreach ($definition->levels as $level) {
                    $created[] = "level:{$definition->slug}:{$level->sequence}";

                    foreach ($level->approvers as $approver) {
                        $created[] = "approver:{$definition->slug}:{$level->sequence}:{$approver->key()}";
                    }
                }

                continue;
            }

            $diff = $this->diff($workflow, $definition);

            if ($diff['refused'] !== []) {
                array_push($refused, ...$diff['refused']);

                continue;
            }

            if ($diff['created'] === [] && $diff['updated'] === []) {
                $unchanged[] = "workflow:{$definition->slug}";

                continue;
            }

            array_push($created, ...$diff['created']);
            array_push($updated, ...$diff['updated']);
            $plans[] = fn (): ApprovalWorkflow => $this->update($workflow, $definition);
        }

        if ($refused !== [] || $dryRun) {
            return new WorkflowSyncResult($created, $updated, $unchanged, $refused, $dryRun);
        }

        DB::transaction(function () use ($plans): void {
            foreach ($plans as $plan) {
                $plan();
            }
        });

        return new WorkflowSyncResult($created, $updated, $unchanged, [], false);
    }

    /**
     * @param  iterable<int|string, mixed>  $definitions
     * @return list<WorkflowDefinition>
     */
    private function normalize(iterable $definitions): array
    {
        $normalized = [];
        $slugs = [];

        foreach ($definitions as $key => $definition) {
            $definition = match (true) {
                $definition instanceof WorkflowDefinition => $definition,
                $definition instanceof WorkflowBuilder => $definition->definition(),
                is_array($definition) => WorkflowDefinition::from($definition, is_string($key) ? $key : null),
                default => throw InvalidWorkflowDefinitionException::because('definitions must be arrays, builders, or WorkflowDefinition objects.'),
            };

            if (isset($slugs[$definition->slug])) {
                throw InvalidWorkflowDefinitionException::because("workflow slug [{$definition->slug}] is defined more than once.");
            }

            $slugs[$definition->slug] = true;
            $normalized[] = $definition;
        }

        return $normalized;
    }

    /**
     * @return array{created: list<string>, updated: list<string>, refused: list<string>}
     */
    private function diff(ApprovalWorkflow $workflow, WorkflowDefinition $definition): array
    {
        $created = [];
        $updated = [];
        $refused = [];
        $existingLevels = $workflow->levels->keyBy('sequence');
        $definedLevels = collect($definition->levels)->keyBy('sequence');

        foreach ($existingLevels as $sequence => $existing) {
            if (! $definedLevels->has($sequence)) {
                $refused[] = "workflow:{$definition->slug}: removing level {$sequence} is not supported";
            }

            $moved = collect($definition->levels)->first(
                fn (LevelDefinition $level): bool => $level->name === Attributes::string($existing, 'name')
                    && $level->sequence !== (int) $sequence,
            );

            if ($moved !== null) {
                $refused[] = "workflow:{$definition->slug}: moving level {$sequence} is not supported";
            }
        }

        if ($refused !== []) {
            return compact('created', 'updated', 'refused');
        }

        if ($workflow->trashed() || $this->different($workflow, $definition->attributes())) {
            $updated[] = "workflow:{$definition->slug}";
        }

        foreach ($definition->levels as $level) {
            /** @var ApprovalWorkflowLevel|null $existing */
            $existing = $existingLevels->get($level->sequence);

            if ($existing === null) {
                $created[] = "level:{$definition->slug}:{$level->sequence}";

                foreach ($level->approvers as $approver) {
                    $created[] = "approver:{$definition->slug}:{$level->sequence}:{$approver->key()}";
                }

                continue;
            }

            if ($this->different($existing, $level->attributes())) {
                $updated[] = "level:{$definition->slug}:{$level->sequence}";
            }

            $definedApprovers = collect($level->approvers)->keyBy->key();
            $existingApprovers = $existing->approvers->keyBy(fn (ApprovalWorkflowLevelApprover $approver): string => $this->approverKey($approver));

            foreach ($existingApprovers as $key => $approver) {
                if (! $definedApprovers->has($key)) {
                    $refused[] = "workflow:{$definition->slug}: removing approver {$key} from level {$level->sequence} is not supported";
                }
            }

            foreach ($definedApprovers as $key => $approver) {
                if (! $existingApprovers->has($key)) {
                    $created[] = "approver:{$definition->slug}:{$level->sequence}:{$key}";
                }
            }
        }

        return compact('created', 'updated', 'refused');
    }

    private function create(WorkflowDefinition $definition): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::create($definition->attributes());

        foreach ($definition->levels as $level) {
            $this->createLevel($workflow, $level);
        }

        return $workflow;
    }

    private function update(ApprovalWorkflow $workflow, WorkflowDefinition $definition): ApprovalWorkflow
    {
        if ($workflow->trashed()) {
            $workflow->restore();
        }

        $workflow->forceFill($definition->attributes())->save();

        foreach ($definition->levels as $level) {
            /** @var ApprovalWorkflowLevel|null $existing */
            $existing = $workflow->levels()->where('sequence', $level->sequence)->first();

            if ($existing === null) {
                $this->createLevel($workflow, $level);

                continue;
            }

            $existing->forceFill($level->attributes())->save();
            $keys = $existing->approvers->mapWithKeys(
                fn (ApprovalWorkflowLevelApprover $approver): array => [$this->approverKey($approver) => true],
            );

            foreach ($level->approvers as $approver) {
                if (! $keys->has($approver->key())) {
                    $existing->approvers()->create($approver->attributes());
                }
            }
        }

        return $workflow->refresh();
    }

    private function createLevel(ApprovalWorkflow $workflow, LevelDefinition $definition): ApprovalWorkflowLevel
    {
        /** @var ApprovalWorkflowLevel $level */
        $level = $workflow->levels()->create($definition->attributes());

        foreach ($definition->approvers as $approver) {
            $level->approvers()->create($approver->attributes());
        }

        return $level;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function different(Model $model, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if (CanonicalJson::normalize($model->getAttributeValue($key)) !== CanonicalJson::normalize($value)) {
                return true;
            }
        }

        return false;
    }

    private function approverKey(ApprovalWorkflowLevelApprover $approver): string
    {
        $resolver = Attributes::nullableString($approver, 'resolver');

        if ($resolver !== null) {
            return 'resolver:'.$resolver.':'.json_encode($approver->getAttributeValue('resolver_config'));
        }

        return 'model:'.Attributes::nullableString($approver, 'approver_type').':'.Attributes::nullableString($approver, 'approver_id');
    }
}
