<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token an external system (the Tasneem Knitting website, or any
 * future website integration) authenticates with against routes/api.php.
 * Only the SHA-256 hash is ever stored — generate() returns the plaintext
 * token exactly once, at creation time, the same way most API-key UIs work.
 *
 * Looking up a key happens BEFORE any company context exists (this is an
 * unauthenticated inbound request), so ResolveCompanyFromApiKey queries this
 * model with withoutGlobalScopes() — the same precedented pattern
 * ChatOrderController uses for ChatOrderLink. BelongsToCompany is still used
 * here (not skipped) so this model is covered by
 * MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract
 * like every other company-owned model.
 */
class CompanyApiKey extends Model
{
    use BelongsToCompany;

    public const SCOPE_WEBSITE = 'website';

    /**
     * The old IMS data-sync keys (business feed, and the investor feed with NID,
     * phone and bank details). Their API was turned off on 2026-10-19 (see
     * routes/api.php), so such keys open nothing and no new one can be made;
     * the constants stay so keys made before still show their type.
     */
    public const SCOPE_SYNC = 'sync';

    public const SCOPE_INVESTOR_SYNC = 'investor_sync';

    /** Every key type, for showing a key's type in the list. */
    public const SCOPES = [
        self::SCOPE_WEBSITE => 'Website (products, orders, leads)',
        self::SCOPE_SYNC => 'Data sync (old IMS, turned off)',
        self::SCOPE_INVESTOR_SYNC => 'Investor sync (old IMS, turned off)',
    ];

    /** The key types a new key can still be made for. */
    public const CREATABLE_SCOPES = [self::SCOPE_WEBSITE];

    protected $fillable = [
        'company_id',
        'label',
        'scope',
        'key_prefix',
        'key_hash',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Creates a new key for $company and returns [model, plaintext token].
     * The plaintext token is never persisted or retrievable again.
     */
    public static function generate(Company $company, ?string $label = null, ?int $createdBy = null, string $scope = self::SCOPE_WEBSITE): array
    {
        $token = Str::random(40);

        $key = static::query()->create([
            'company_id' => $company->getKey(),
            'label' => $label,
            'scope' => $scope,
            'key_prefix' => substr($token, 0, 8),
            'key_hash' => hash('sha256', $token),
            'created_by' => $createdBy,
        ]);

        return [$key, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('key_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();
    }
}
