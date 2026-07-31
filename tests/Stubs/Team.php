<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A container model, for pinning the rule that the package never expands one
 * into its members. Assigning this team names the team itself; naming its
 * members requires an approver resolver.
 */
class Team extends Model
{
    protected $table = 'teams';

    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user');
    }
}
