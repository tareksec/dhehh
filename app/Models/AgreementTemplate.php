<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * An agreement an investor or channel partner accepts in the portal
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §5, Phase 3). The text is
 * entered by staff. Once published a version is never changed or deleted;
 * a change is a new version, and people are asked to accept it again.
 */
class AgreementTemplate extends Model
{
    use BelongsToCompany;

    public const KIND_INVESTOR = 'investor_deed';

    public const KIND_PARTNER = 'partner_agreement';

    public const KINDS = [self::KIND_INVESTOR => 'Investor agreement', self::KIND_PARTNER => 'Channel partner agreement'];

    protected $fillable = ['company_id', 'kind', 'version', 'title', 'body', 'created_by'];

    protected $casts = ['published_at' => 'datetime', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->version = (int) self::query()->withoutGlobalScopes()
                ->where('company_id', $template->company_id)
                ->where('kind', $template->kind)
                ->max('version') + 1;
        });

        static::updating(function (self $template): void {
            if ($template->getOriginal('published_at') !== null && $template->isDirty(['kind', 'version', 'title', 'body', 'published_at', 'published_by'])) {
                throw ValidationException::withMessages(['body' => __('A published agreement cannot be changed. Create a new version instead.')]);
            }
        });

        static::deleting(function (self $template): void {
            if ($template->published_at !== null) {
                throw ValidationException::withMessages(['body' => __('A published agreement cannot be deleted.')]);
            }
        });
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(AgreementAcceptance::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function bodyHash(): string
    {
        return hash('sha256', $this->title."\n".$this->body);
    }

    /** The newest published version of a kind for the current company. */
    public static function current(string $kind): ?self
    {
        return self::query()->where('kind', $kind)->whereNotNull('published_at')->orderByDesc('version')->first();
    }

    public static function kindLabel(?string $kind): string
    {
        return __(self::KINDS[$kind] ?? (string) $kind);
    }
}
