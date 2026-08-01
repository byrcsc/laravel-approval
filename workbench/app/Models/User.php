<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

/**
 * The demo app's actor.
 *
 * Requesters, approvers, delegates, and operators are all just Eloquent models
 * to the package - it stores a morph key and a readable label, and has no user
 * model of its own. `Notifiable` is the only thing this class needs in order to
 * receive the package's notifications.
 *
 * `approval.user_model` points here. That setting is a default for the places
 * that have to guess - `--operator` on `approval:resync`, and
 * `StaticUsersResolver` when no model is named - not a constraint on who may
 * approve.
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function ledTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'lead_id');
    }

    public function isOn(string $team): bool
    {
        return $this->teams()->where('slug', $team)->exists();
    }
}
