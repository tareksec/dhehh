<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A channel in Trash (owner request 2026-11-01, plan T4b) behaves exactly like a
 * deleted one did: it receives nothing and sends nothing, and the conversations
 * and messages it archived stay. Every webhook, job and service that looks a
 * channel up with `withoutGlobalScopes()` therefore adds `->withoutTrashed()`
 * (tests/Feature/CrmTrashTest guards this by scanning the code). The relations
 * from a conversation, a contact or a broadcast stay on the default scope, so
 * `$conversation->channel` is null while the channel is in Trash and the
 * existing "no active channel" guards apply; Restore brings it all back.
 */
class ConversationChannel extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const PROVIDERS = [
        'whatsapp' => 'WhatsApp Cloud API',
        'messenger' => 'Facebook Messenger',
    ];

    // 'cloud_api' (manual credential-entry, today's default) | 'coexistence'
    // (connected via Meta Embedded Signup - phone app keeps working too).
    public const CONNECTION_TYPES = [
        'cloud_api' => 'Cloud API (manual)',
        'coexistence' => 'WhatsApp Business App (Coexistence)',
        'web' => 'WhatsApp Web (QR login archive)',
    ];

    // Lifecycle reported by the whatsapp-bridge service for 'web' channels.
    public const WEB_STATUSES = [
        'pending' => 'Waiting for QR scan',
        'qr' => 'Scan the QR code',
        'qr_expired' => 'QR code expired',
        'connected' => 'Connected',
        'logged_out' => 'Logged out',
        'banned' => 'Banned by WhatsApp',
        'replaced' => 'Opened on another bridge',
        'error' => 'Bridge error',
    ];

    protected $fillable = [
        'company_id', 'provider', 'connection_type',
        'web_status', 'web_phone', 'web_connected_at', 'web_disconnected_at', 'web_history_synced_at',
        'external_id', 'waba_id', 'display_name',
        'access_token', 'app_secret', 'verify_token',
        'auto_create_leads', 'is_active',
        'webhook_verified_at', 'webhook_subscribed_at', 'last_webhook_at',
        'last_inbound_at', 'last_outbound_at', 'last_health_at',
        'last_error_source', 'last_error_at', 'last_error',
    ];

    protected $hidden = [
        'access_token',
        'app_secret',
        'verify_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'app_secret' => 'encrypted',
        'auto_create_leads' => 'boolean',
        'is_active' => 'boolean',
        'webhook_verified_at' => 'datetime',
        'webhook_subscribed_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'last_health_at' => 'datetime',
        'last_error_at' => 'datetime',
        'web_connected_at' => 'datetime',
        'web_disconnected_at' => 'datetime',
        'web_history_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ConversationChannel $channel): void {
            if ($channel->isDirty(['provider', 'external_id', 'waba_id', 'access_token'])) {
                $channel->webhook_subscribed_at = null;
            }

            if ($channel->isDirty(['provider', 'app_secret', 'verify_token'])) {
                $channel->webhook_verified_at = null;
            }

            if ($channel->isDirty(['provider', 'external_id', 'waba_id'])) {
                $channel->last_webhook_at = null;
                $channel->last_inbound_at = null;
                $channel->last_outbound_at = null;
                $channel->last_health_at = null;
                $channel->last_error_source = null;
                $channel->last_error_at = null;
                $channel->last_error = null;
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'channel_id');
    }

    public function isCoexistence(): bool
    {
        return $this->connection_type === 'coexistence';
    }

    /** Linked by WhatsApp Web QR login: a read-only archive of the phone app's chats. */
    public function isWeb(): bool
    {
        return $this->connection_type === 'web';
    }

    public function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        if (blank($this->app_secret) || blank($signatureHeader)) {
            return false;
        }

        if (preg_match('/\Asha256=([a-f0-9]{64})\z/D', (string) $signatureHeader, $matches) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, (string) $this->app_secret), $matches[1]);
    }

    public function markWebhookVerified(): void
    {
        $this->forceFill(['webhook_verified_at' => now()])->saveQuietly();
    }

    public function markWebhookSubscribed(): void
    {
        $this->forceFill(['webhook_subscribed_at' => now()])->saveQuietly();
    }

    public function markWebhookReceived(): void
    {
        $this->forceFill(['last_webhook_at' => now()])->saveQuietly();
    }

    public function markInboundReceived(): void
    {
        $this->forceFill(['last_inbound_at' => now()])->saveQuietly();
        $this->clearDiagnosticError('webhook');
    }

    public function markOutboundSent(): void
    {
        $this->forceFill(['last_outbound_at' => now()])->saveQuietly();
        $this->clearDiagnosticError('outbound');
    }

    public function markHealthChecked(): void
    {
        $this->forceFill(['last_health_at' => now()])->saveQuietly();
        $this->clearDiagnosticError('connection');
    }

    public function recordDiagnosticError(string $message, string $source = 'general'): void
    {
        $safe = trim(Str::limit(preg_replace([
            '/access[_ -]?token\s*[:=]\s*[^\s,;]+/iu',
            '/bearer\s+[a-z0-9._-]+/iu',
            '/EAA[A-Za-z0-9_-]{20,}/',
        ], ['access token [redacted]', 'Bearer [redacted]', '[redacted token]'], $message) ?? 'Meta request failed.', 2000, ''));

        $this->forceFill([
            'last_error_source' => Str::limit($source, 50, ''),
            'last_error_at' => now(),
            'last_error' => $safe !== '' ? $safe : 'Meta request failed. Check the channel setup.',
        ])->saveQuietly();
    }

    public function clearDiagnosticError(?string $source = null): void
    {
        $this->refresh();

        if ($source !== null && $this->last_error_source !== $source) {
            return;
        }

        $this->forceFill([
            'last_error_source' => null,
            'last_error_at' => null,
            'last_error' => null,
        ])->saveQuietly();
    }

    public function diagnosticStatus(): string
    {
        if (! $this->is_active) {
            return 'Inactive';
        }

        if ($this->isWeb()) {
            return self::WEB_STATUSES[$this->web_status ?? 'pending'] ?? 'Not tested';
        }

        if (filled($this->last_error)) {
            return 'Needs attention';
        }

        if ($this->provider === 'whatsapp') {
            if ($this->last_inbound_at) {
                return 'Inbound confirmed';
            }

            if ($this->webhook_verified_at && $this->webhook_subscribed_at) {
                return 'Configured';
            }

            if ($this->webhook_subscribed_at) {
                return 'Verify callback';
            }

            if ($this->webhook_verified_at) {
                return 'Subscribe app';
            }
        }

        if ($this->last_inbound_at) {
            return 'Inbound confirmed';
        }

        if ($this->last_health_at || $this->last_webhook_at || $this->last_outbound_at) {
            return 'Connected';
        }

        if ($this->webhook_verified_at) {
            return 'Callback verified';
        }

        return 'Not tested';
    }
}
