<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'actor_type',
        'actor_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /** A portal user (investor-portal account, reseller) when the action was not done by staff. */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who did it, for the Audit Log screens: the staff user, else the portal user. */
    public function actorLabel(): ?string
    {
        if ($this->user) {
            return $this->user->name;
        }

        $actor = $this->actor;

        if (! $actor) {
            return null;
        }

        $name = method_exists($actor, 'getFilamentName') ? $actor->getFilamentName() : ($actor->name ?? '#'.$actor->getKey());

        return __(':name (portal)', ['name' => $name]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
