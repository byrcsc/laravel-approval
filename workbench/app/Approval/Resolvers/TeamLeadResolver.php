<?php

declare(strict_types=1);

namespace Workbench\App\Approval\Resolvers;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * The reporting-line rule: whoever leads the team the record belongs to.
 *
 * This is the shape most organizational rules take - a question about the
 * record, asked at submission, answered with one persisted actor. The answer is
 * written into the request's assignments there and then, so replacing a team's
 * lead tomorrow does not silently move an in-flight approval to somebody who
 * never saw it.
 *
 * `resolver_config` may name a fallback team by slug, used when the record's own
 * team has no lead. A resolver returning nobody is not an error to the package -
 * the stage simply gets no assignments - so a rule that can come up empty should
 * say what happens then.
 */
final class TeamLeadResolver implements ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $requester, array $config): iterable
    {
        $lead = $this->leadOf($this->teamOf($approvable));

        if ($lead === null) {
            $fallback = $config['fallback_team'] ?? null;

            $lead = is_string($fallback)
                ? $this->leadOf(Team::query()->where('slug', $fallback)->first())
                : null;
        }

        return $lead === null ? [] : [$lead];
    }

    private function teamOf(Model $approvable): ?Team
    {
        $team = $approvable->getAttributeValue('team_id') === null
            ? null
            : $approvable->getAttribute('team');

        return $team instanceof Team ? $team : null;
    }

    private function leadOf(?Team $team): ?User
    {
        $lead = $team?->lead;

        return $lead instanceof User ? $lead : null;
    }
}
