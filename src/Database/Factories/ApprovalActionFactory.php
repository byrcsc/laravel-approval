<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Support\ActionHash;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\AuditHashPayload;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<ApprovalAction>
 */
final class ApprovalActionFactory extends Factory
{
    protected $model = ApprovalAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => ApprovalRequestFactory::new(),
            'stage_id' => null,
            'actor_type' => null,
            'actor_id' => null,
            'actor_label' => null,
            'action' => ActionType::Submitted,
            'comment' => null,
            'metadata' => null,
            'hash' => str_repeat('0', 64),
            'previous_hash' => null,
            'created_at' => now(),
        ];
    }

    public function configure(): self
    {
        return $this
            ->afterMaking(function (ApprovalAction $action): void {
                $request = $action->request;

                if ($request === null) {
                    throw new LogicException('An approval action factory requires a request.');
                }

                $createdAt = Attributes::datetime($action, 'created_at') ?? now();

                $action->forceFill([
                    'hash' => ActionHash::compute(new AuditHashPayload(
                        requestUlid: Attributes::string($request, 'ulid'),
                        previousHash: Attributes::nullableString($action, 'previous_hash'),
                        action: Attributes::string($action, 'action'),
                        actorType: Attributes::nullableString($action, 'actor_type'),
                        actorId: Attributes::nullableString($action, 'actor_id'),
                        actorLabel: Attributes::nullableString($action, 'actor_label'),
                        stageId: Attributes::nullableInt($action, 'stage_id'),
                        comment: Attributes::nullableString($action, 'comment'),
                        metadata: $action->getAttributeValue('metadata') === null
                            ? null
                            : Attributes::object($action, 'metadata'),
                        createdAt: $createdAt->format('Y-m-d H:i:s'),
                    )),
                ]);
            })
            ->afterCreating(function (ApprovalAction $action): void {
                $request = $action->request;

                if ($request === null) {
                    throw new LogicException('An approval action factory requires a request.');
                }

                $request->forceFill(['audit_head_hash' => Attributes::string($action, 'hash')])->save();
            });
    }
}
