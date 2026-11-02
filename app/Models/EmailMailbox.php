<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One email address staff read and answer in the Email inbox (owner request 2026-11-02). Mail reaches it through
 * the address behind webhooks/email/{mailbox}/{token} (a Cloudflare Email Routing Worker, see docs/email-inbox-setup.md);
 * it is sent through the SMTP account of the site it belongs to (Integrations → Email Setup). Only a hash of the
 * token is kept, so a token cannot be read back, only replaced.
 */
class EmailMailbox extends Model
{
    use BelongsToCompany;

    public const RECEIVE_WEBHOOK = 'webhook';

    /** The address only sends: no inbox is filled. */
    public const RECEIVE_NONE = 'none';

    /** What a mailbox does until the owner changes it on its settings page. */
    public const DEFAULT_SETTINGS = [
        'undo_send_seconds' => 10,
        'reading_pane' => 'right',
        'match_customers' => true,
        'load_remote_images' => false,
        'notify' => true,
    ];

    protected $fillable = [
        'company_id', 'storefront_setting_id', 'name', 'address', 'receive_mode', 'token_hash', 'token_issued_at',
        'last_received_at', 'signature', 'settings', 'is_active',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_received_at' => 'datetime',
            'token_issued_at' => 'datetime',
        ];
    }

    public function storefrontSetting(): BelongsTo
    {
        return $this->belongsTo(StorefrontSetting::class)->withoutGlobalScopes();
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class, 'mailbox_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'mailbox_id');
    }

    /** One behaviour setting, with the default when the owner never changed it. */
    public function setting(string $key): mixed
    {
        return ((array) $this->settings)[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function receivesMail(): bool
    {
        return $this->is_active && $this->receive_mode === self::RECEIVE_WEBHOOK;
    }

    /** Makes a new secret, keeps its hash (the old secret stops working at once) and returns it: the only time it can be read. */
    public function issueToken(): string
    {
        $token = Str::random(48);

        $this->forceFill(['token_hash' => hash('sha256', $token), 'token_issued_at' => now()])->save();

        return $token;
    }

    public function tokenMatches(string $token): bool
    {
        return $this->token_hash !== null && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function webhookUrl(string $token): string
    {
        return route('webhooks.email', ['mailbox' => $this->getKey(), 'token' => $token]);
    }
}
