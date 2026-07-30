<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * The supported way to assign a container's members: expands a team into the
 * persisted users belonging to it.
 */
class TeamMembersResolver implements ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $requester, array $config): iterable
    {
        /** @var Team $team */
        $team = Team::query()->findOrFail($config['team']);

        return $team->members()->orderBy('id')->get()->all();
    }
}
