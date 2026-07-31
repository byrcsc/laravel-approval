<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Which workflows a record could be submitted against, best first.
 *
 * Candidates come from three places, and where one comes from is what makes it
 * more specific than another: the workflow the model declares, then the
 * `approval.workflows` config map in the order it is written, then every
 * workflow registered against the model's type. A workflow reached from more
 * than one of them is considered at its most specific position only.
 *
 * Whatever is gathered is then filtered: an inactive workflow is out, and so is
 * one whose condition says it does not apply to this submission. What survives
 * is ordered by where it came from, then by priority, highest first.
 *
 * The tier this returns alongside each workflow is how specific it is. Two
 * workflows sharing a tier and a priority are the ambiguous case the caller has
 * to refuse, and only the type-registered tier can hold more than one.
 */
final class ResolveWorkflows
{
    public function __construct(private readonly EvaluateWorkflowCondition $evaluateCondition) {}

    /**
     * @param  array<string, mixed>|null  $newAttributes  the draft the submission would hold, which conditions judge
     * @return array{applicable: list<array{workflow: ApprovalWorkflow, tier: int}>, considered: list<string>}
     */
    public function __invoke(Model $approvable, ?Model $requester = null, ?array $newAttributes = null): array
    {
        $proposed = Attributes::proposed($approvable, $newAttributes);
        $candidates = $this->candidates($approvable);

        $applicable = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => Attributes::bool($candidate['workflow'], 'active')
                && ($this->evaluateCondition)($candidate['workflow'], $proposed, $requester),
        ));

        usort($applicable, fn (array $left, array $right): int => $this->rank($left) <=> $this->rank($right));

        return [
            'applicable' => $applicable,
            'considered' => array_map(
                fn (array $candidate): string => Attributes::string($candidate['workflow'], 'slug'),
                $candidates,
            ),
        ];
    }

    /**
     * Every workflow this model could reach, tiered by where it was named and
     * with the less specific duplicate of any workflow dropped.
     *
     * @return list<array{workflow: ApprovalWorkflow, tier: int}>
     */
    private function candidates(Model $approvable): array
    {
        $candidates = [];
        $tier = 0;

        // Each named source is a tier of its own: naming a workflow is an
        // ordered preference, not a set to choose between.
        foreach ($this->named($approvable) as $workflow) {
            $candidates[] = ['workflow' => $workflow, 'tier' => $tier++];
        }

        // The type-registered workflows share the last tier, which is what
        // makes them a set that conditions and priority choose between.
        foreach ($this->registered($approvable) as $workflow) {
            $candidates[] = ['workflow' => $workflow, 'tier' => $tier];
        }

        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = Attributes::string($candidate['workflow'], 'id');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * The workflows named for this model, most specific first. A slug that
     * names nothing fails here rather than being quietly skipped: a typo in a
     * model or in config is a mistake, not a fallback.
     *
     * @return list<ApprovalWorkflow>
     */
    private function named(Model $approvable): array
    {
        $named = [];
        $declared = $this->declared($approvable);

        if ($declared instanceof ApprovalWorkflow) {
            $named[] = $declared;
        } elseif (is_string($declared) && $declared !== '') {
            $named[] = $this->bySlug($declared);
        }

        foreach ($this->mapped($approvable) as $slug) {
            $named[] = $this->bySlug($slug);
        }

        return $named;
    }

    private function declared(Model $approvable): ApprovalWorkflow|string|null
    {
        if (! method_exists($approvable, 'approvalWorkflow')) {
            return null;
        }

        /** @var mixed $declared */
        $declared = $approvable->approvalWorkflow();

        return $declared instanceof ApprovalWorkflow || is_string($declared) ? $declared : null;
    }

    /**
     * The `approval.workflows` map, keyed by model class. The class name is
     * matched first and the morph alias second, so configuration can be written
     * in either form. One slug names one workflow; a list of them is a
     * preference order.
     *
     * @return list<string>
     */
    private function mapped(Model $approvable): array
    {
        /** @var mixed $map */
        $map = config('approval.workflows');

        if (! is_array($map)) {
            return [];
        }

        /** @var mixed $entry */
        $entry = $map[$approvable::class] ?? $map[$approvable->getMorphClass()] ?? null;
        $slugs = [];

        foreach (is_array($entry) ? $entry : [$entry] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return list<ApprovalWorkflow>
     */
    private function registered(Model $approvable): array
    {
        return array_values(
            ApprovalWorkflow::query()
                ->forApprovable($approvable)
                ->inResolutionOrder()
                ->get()
                ->all()
        );
    }

    private function bySlug(string $slug): ApprovalWorkflow
    {
        /** @var ApprovalWorkflow|null $workflow */
        $workflow = ApprovalWorkflow::query()->where('slug', $slug)->first();

        return $workflow ?? throw WorkflowNotFoundException::forSlug($slug);
    }

    /**
     * @param  array{workflow: ApprovalWorkflow, tier: int}  $candidate
     * @return array{int, int, int}
     */
    private function rank(array $candidate): array
    {
        return [
            $candidate['tier'],
            -Attributes::int($candidate['workflow'], 'priority'),
            Attributes::int($candidate['workflow'], 'id'),
        ];
    }
}
