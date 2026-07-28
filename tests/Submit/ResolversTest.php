<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Resolvers\CallbackResolver;
use ByRcsc\LaravelApproval\Resolvers\StaticUsersResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Model;

it('expands a resolver into slots marked as resolved', function (): void {
    $head = user('Department Head');
    $deputy = user('Deputy');

    HeadOfDepartmentResolver::$heads = [$head, $deputy];

    $workflow = workflow([
        ['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $slots = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->get();

    expect($slots)->toHaveCount(2)
        ->and($slots->pluck('approver_id')->all())->toBe([$head->getKey(), $deputy->getKey()])
        ->and($slots[0]->source)->toBe(ApproverSource::Resolved);
});

it('hands the resolver its own config', function (): void {
    HeadOfDepartmentResolver::$heads = [user('One'), user('Two'), user('Three')];

    $workflow = workflow([
        ['approvers' => [[
            'resolver' => HeadOfDepartmentResolver::class,
            'resolver_config' => ['limit' => 2],
        ]]],
    ]);

    expect(post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->count())->toBe(2);
});

it('keeps the answer a resolver gave even after the org chart moves', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['approvers' => [['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    // The org chart changes the day after submission.
    HeadOfDepartmentResolver::$heads = [user('Successor')];

    expect($request->steps()->sole()->approverSlots()->pluck('approver_id')->all())->toBe([$head->getKey()]);
});

it('merges a resolver and a concrete approver into one level', function (): void {
    $cfo = user('CFO');
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [$cfo, ['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $slots = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->get();

    expect($slots->pluck('approver_id')->all())->toBe([$cfo->getKey(), $head->getKey()])
        ->and($slots->pluck('source')->all())->toBe([ApproverSource::Assigned, ApproverSource::Resolved]);
});

it('lets a concrete assignment win over the same person resolved', function (): void {
    $both = user('In Two Places');
    HeadOfDepartmentResolver::$heads = [$both];

    $workflow = workflow([
        ['approvers' => [$both, ['resolver' => HeadOfDepartmentResolver::class]]],
    ]);

    $slots = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->get();

    expect($slots)->toHaveCount(1)
        ->and($slots[0]->source)->toBe(ApproverSource::Assigned);
});

describe('StaticUsersResolver', function (): void {
    it('resolves the listed keys against the configured user model', function (): void {
        $one = user('One');
        $two = user('Two');
        user('Not Listed');

        $workflow = workflow([
            ['required_approvals' => 2, 'approvers' => [[
                'resolver' => StaticUsersResolver::class,
                'resolver_config' => ['ids' => [$one->getKey(), $two->getKey()]],
            ]]],
        ]);

        $slots = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->get();

        expect($slots->pluck('approver_id')->all())->toBe([$one->getKey(), $two->getKey()])
            ->and($slots->pluck('approver_type')->unique()->all())->toBe([(new User)->getMorphClass()]);
    });

    it('takes an explicit model over the configured one', function (): void {
        $order = purchaseOrder();

        $workflow = workflow([
            ['approvers' => [[
                'resolver' => StaticUsersResolver::class,
                'resolver_config' => ['ids' => [$order->getKey()], 'model' => $order::class],
            ]]],
        ]);

        $slot = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->sole();

        expect($slot->getAttributeValue('approver_type'))->toBe($order->getMorphClass());
    });

    it('silently skips keys that no longer exist', function (): void {
        $one = user('One');

        $workflow = workflow([
            ['approvers' => [[
                'resolver' => StaticUsersResolver::class,
                'resolver_config' => ['ids' => [$one->getKey(), 9999]],
            ]]],
        ]);

        expect(post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->count())->toBe(1);
    });
});

describe('CallbackResolver', function (): void {
    it('calls a class out of the container', function (): void {
        $head = user('Head');
        HeadOfDepartmentResolver::$heads = [$head];

        $workflow = workflow([
            ['approvers' => [[
                'resolver' => CallbackResolver::class,
                'resolver_config' => ['callback' => HeadOfDepartmentResolver::class.'@resolve'],
            ]]],
        ]);

        $slot = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->sole();

        expect($slot->getAttributeValue('approver_id'))->toBe($head->getKey());
    });

    it('defaults to __invoke when no method is named', function (): void {
        $approver = user('Invoked');
        InvokableLookup::$approvers = [$approver];

        $workflow = workflow([
            ['approvers' => [[
                'resolver' => CallbackResolver::class,
                'resolver_config' => ['callback' => InvokableLookup::class],
            ]]],
        ]);

        $slot = post()->submitForApproval($workflow, null, user())->steps()->sole()->approverSlots()->sole();

        expect($slot->getAttributeValue('approver_id'))->toBe($approver->getKey());

        InvokableLookup::$approvers = [];
    });
});

final class InvokableLookup
{
    /** @var list<Model> */
    public static array $approvers = [];

    /**
     * @return list<Model>
     */
    public function __invoke(): array
    {
        return self::$approvers;
    }
}
