<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\Database\Factories\TeamFactory;

/**
 * A container model, and the reason the demo has approver resolvers.
 *
 * Naming a team as a stage's approver would assign the team record itself - one
 * identity that can never sign in and decide. The package refuses to guess what
 * a container means, so `TeamLeadResolver` and `TeamMembersResolver` are what
 * turn this table into approvers.
 */
final class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
