<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\Ineligibility;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Team;
use ByRcsc\LaravelApproval\Tests\Stubs\TeamMembersResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\UnsavedApproverResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

/**
 * A concrete assignment names one exact actor identity. Roles, teams, positions,
 * and other container models are never expanded into their members; that is what
 * approver resolvers are for, and a resolver must return persisted actor models.
 */

/**
 * @param  list<User>  $members
 */
function team(string $name, array $members): Team
{
    $team = Team::create(['name' => $name]);
    $team->members()->attach(array_map(fn (User $member): int|string => $member->getKey(), $members));

    return $team;
}

it('assigns a container model as itself and never as its members', function (): void {
    $member = user('Team member');
    $finance = team('Finance', [$member, user('Other member')]);

    $request = post()->submitForApproval(
        workflow([['approvers' => [$finance]]]),
        null,
        user('Requester'),
    );

    $assignment = $request->stages()->sole()->assignments()->sole();

    expect($request->stages()->sole()->assignments()->count())->toBe(1)
        ->and($assignment->approver_type)->toBe($finance->getMorphClass())
        ->and((string) $assignment->approver_id)->toBe((string) $finance->getKey())
        ->and($assignment->source)->toBe(ApproverSource::Assigned)
        ->and(Approval::canApprove($request, $finance))->toBeTrue()
        ->and(Approval::eligibility($request, $member)->reason)->toBe(Ineligibility::NotAnApprover);
});

it('expands a container into persisted member approvers only through a resolver', function (): void {
    $first = user('First member');
    $second = user('Second member');
    $finance = team('Finance', [$first, $second]);

    $request = post()->submitForApproval(
        workflow([[
            'required_approvals' => 2,
            'approvers' => [[
                'resolver' => TeamMembersResolver::class,
                'resolver_config' => ['team' => $finance->getKey()],
            ]],
        ]]),
        null,
        user('Requester'),
    );

    $assignments = $request->stages()->sole()->assignments()->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->pluck('approver_type')->unique()->all())->toBe([$first->getMorphClass()])
        ->and($assignments->pluck('approver_id')->all())->toBe([$first->getKey(), $second->getKey()])
        ->and($assignments[0]->source)->toBe(ApproverSource::Resolved)
        ->and(Approval::canApprove($request, $first))->toBeTrue()
        ->and(Approval::canApprove($request, $finance))->toBeFalse();
});

it('refuses approver identities that are not persisted actor models', function (): void {
    $unsaved = new User(['name' => 'Never saved']);

    expect(fn () => Approval::syncDefinitions([[
        'slug' => 'unsaved-approver',
        'name' => 'Unsaved approver',
        'stages' => [['name' => 'Stage', 'approvers' => [$unsaved]]],
    ]]))->toThrow(InvalidWorkflowDefinitionException::class, 'persisted');

    $request = fn () => post()->submitForApproval(
        workflow([['approvers' => [['resolver' => UnsavedApproverResolver::class]]]]),
        null,
        user('Requester'),
    );

    expect($request)->toThrow(InvalidWorkflowException::class, 'has not been saved');
});

it('refuses a concrete approver whose record has been deleted', function (): void {
    $approver = user('Departed');
    $flow = workflow([['approvers' => [$approver]]]);
    $approver->delete();

    expect(fn () => post()->submitForApproval($flow, null, user('Requester')))
        ->toThrow(InvalidWorkflowException::class);
});
