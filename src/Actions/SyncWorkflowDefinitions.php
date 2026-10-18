<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Data\WorkflowSyncResult;
use ByRcsc\LaravelApproval\Definitions\StageDefinition;
use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Definitions\WorkflowDefinition;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
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
            $workflow = ApprovalModels::workflow()::withTrashed()
                ->with(['stages.approvers'])
                ->where('slug', $definition->slug)
                ->first();

            if ($workflow === null) {
                $created[] = "workflow:{$definition->slug}";
                $plans[] = fn (): ApprovalWorkflow => $this->create($definition);

                foreach ($definition->stages as $stage) {
                    $created[] = "stage:{$definition->slug}:{$stage->sequence}";

                    foreach ($stage->approvers as $approver) {
                        $created[] = "approver:{$definition->slug}:{$stage->sequence}:{$approver->key()}";
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
        $existingLevels = $workflow->stages->keyBy('sequence');
        $definedLevels = collect($definition->stages)->keyBy('sequence');

        foreach ($existingLevels as $sequence => $existing) {
            if (! $definedLevels->has($sequence)) {
                $refused[] = "workflow:{$definition->slug}: removing stage {$sequence} is not supported";
            }

            $moved = collect($definition->stages)->first(
                fn (StageDefinition $stage): bool => $stage->name === Attributes::string($existing, 'name')
                    && $stage->sequence !== (int) $sequence,
            );

            if ($moved !== null) {
                $refused[] = "workflow:{$definition->slug}: moving stage {$sequence} is not supported";
            }
        }

        if ($refused !== []) {
            return compact('created', 'updated', 'refused');
        }

        if ($workflow->trashed() || $this->different($workflow, $definition->attributes())) {
            $updated[] = "workflow:{$definition->slug}";
        }

        foreach ($definition->stages as $stage) {
            /** @var ApprovalWorkflowStage|null $existing */
            $existing = $existingLevels->get($stage->sequence);

            if ($existing === null) {
                $created[] = "stage:{$definition->slug}:{$stage->sequence}";

                foreach ($stage->approvers as $approver) {
                    $created[] = "approver:{$definition->slug}:{$stage->sequence}:{$approver->key()}";
                }

                continue;
            }

            if ($this->different($existing, $stage->attributes())) {
                $updated[] = "stage:{$definition->slug}:{$stage->sequence}";
            }

            $definedApprovers = collect($stage->approvers)->keyBy->key();
            $existingApprovers = $existing->approvers->keyBy(fn (ApprovalWorkflowStageApprover $approver): string => $this->approverKey($approver));

            foreach ($existingApprovers as $key => $approver) {
                if (! $definedApprovers->has($key)) {
                    $refused[] = "workflow:{$definition->slug}: removing approver {$key} from stage {$stage->sequence} is not supported";
                }
            }

            foreach ($definedApprovers as $key => $approver) {
                if (! $existingApprovers->has($key)) {
                    $created[] = "approver:{$definition->slug}:{$stage->sequence}:{$key}";
                }
            }
        }

        return compact('created', 'updated', 'refused');
    }

    private function create(WorkflowDefinition $definition): ApprovalWorkflow
    {
        $workflow = ApprovalModels::workflow()::create($definition->attributes());

        foreach ($definition->stages as $stage) {
            $this->createLevel($workflow, $stage);
        }

        return $workflow;
    }

    private function update(ApprovalWorkflow $workflow, WorkflowDefinition $definition): ApprovalWorkflow
    {
        if ($workflow->trashed()) {
            $workflow->restore();
        }

        $workflow->forceFill($definition->attributes())->save();

        foreach ($definition->stages as $stage) {
            /** @var ApprovalWorkflowStage|null $existing */
            $existing = $workflow->stages()->where('sequence', $stage->sequence)->first();

            if ($existing === null) {
                $this->createLevel($workflow, $stage);

                continue;
            }

            $existing->forceFill($stage->attributes())->save();
            $keys = $existing->approvers->mapWithKeys(
                fn (ApprovalWorkflowStageApprover $approver): array => [$this->approverKey($approver) => true],
            );

            foreach ($stage->approvers as $approver) {
                if (! $keys->has($approver->key())) {
                    $existing->approvers()->create($approver->attributes());
                }
            }
        }

        return $workflow->refresh();
    }

    private function createLevel(ApprovalWorkflow $workflow, StageDefinition $definition): ApprovalWorkflowStage
    {
        /** @var ApprovalWorkflowStage $stage */
        $stage = $workflow->stages()->create($definition->attributes());

        foreach ($definition->approvers as $approver) {
            $stage->approvers()->create($approver->attributes());
        }

        return $stage;
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

    private function approverKey(ApprovalWorkflowStageApprover $approver): string
    {
        $resolver = Attributes::nullableString($approver, 'resolver');

        if ($resolver !== null) {
            return 'resolver:'.$resolver.':'.json_encode($approver->getAttributeValue('resolver_config'));
        }

        return 'model:'.Attributes::nullableString($approver, 'approver_type').':'.Attributes::nullableString($approver, 'approver_id');
    }
}
