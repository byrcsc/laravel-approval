<?php

declare(strict_types=1);

namespace Workbench\App\Approval\Resolvers;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Team;

/**
 * The group rule: every member of a named team.
 *
 *     'resolver' => TeamMembersResolver::class,
 *     'resolver_config' => ['team' => 'finance', 'except_requester' => true],
 *
 * One entry, several assignments - which is what makes a stage's
 * `required_approvals` mean `1-of-N` or `2-of-N` rather than "this one person".
 *
 * `except_requester` keeps a requester who is also on the team out of their own
 * approval stage. Self-approval is separately refused at decision time, so
 * leaving them assigned would only produce a stage nobody can complete; this
 * excludes them from the count instead.
 */
final class TeamMembersResolver implements ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $requester, array $config): iterable
    {
        $slug = $config['team'] ?? null;

        if (! is_string($slug) || $slug === '') {
            return [];
        }

        $team = Team::query()->where('slug', $slug)->first();

        if (! $team instanceof Team) {
            return [];
        }

        $members = $team->members()->orderBy('users.id')->get();

        if (($config['except_requester'] ?? false) === true) {
            $members = $members->reject(
                fn (Model $member): bool => $member->is($requester),
            );
        }

        return $members->values()->all();
    }
}
