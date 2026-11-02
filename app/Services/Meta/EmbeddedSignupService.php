<?php

namespace App\Services\Meta;

use App\Models\Company;
use App\Models\ConversationChannel;

/**
 * Completes a Meta Embedded Signup (Coexistence) connection: exchanges the
 * short-lived authorization code the JS popup returned for a long-lived
 * access token, then creates or updates the ConversationChannel row for the
 * connected WhatsApp Business App number. Reuses the existing manual-entry
 * pipeline entirely from here on - MetaWebhookController/StoreIncomingMessageJob
 * /ConversationMessengerService branch on provider === 'whatsapp', not on how
 * the channel was connected.
 */
class EmbeddedSignupService
{
    public function __construct(
        protected MetaGraphService $graph,
        protected EmbeddedSignupSettingsService $settings,
    ) {}

    public function complete(
        Company $company,
        string $code,
        string $wabaId,
        string $phoneNumberId,
        ?string $displayName = null,
    ): ConversationChannel {
        $settings = $this->settings->all($company);

        if (blank($settings['app_id']) || blank($settings['app_secret'])) {
            throw new MetaGraphException('Save the Meta App ID and App Secret above before connecting a number.');
        }

        $accessToken = $this->graph->exchangeEmbeddedSignupCode(
            (string) $settings['app_id'],
            (string) $settings['app_secret'],
            $code,
        );

        $channel = ConversationChannel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->getKey(), 'provider' => 'whatsapp', 'external_id' => $phoneNumberId],
            [
                'connection_type' => 'coexistence',
                'waba_id' => $wabaId,
                'display_name' => filled($displayName) ? $displayName : 'WhatsApp Business App',
                'access_token' => $accessToken,
                // The app secret/verify token verify inbound webhook signatures
                // and the GET handshake - both are the same app-level values
                // used for the code exchange above, so every channel connected
                // through this app can be verified the same way.
                'app_secret' => $settings['app_secret'],
                'verify_token' => $settings['verify_token'],
                'auto_create_leads' => true,
                'is_active' => true,
            ],
        );

        // The scope-free lookup above also finds this number's row in Trash (the
        // Phone Number ID is unique), so reconnecting the number brings the
        // channel back instead of leaving a live connection that nobody can see.
        if ($channel->trashed()) {
            $channel->restore();
        }

        $this->graph->subscribeWhatsApp($channel);

        return $channel;
    }
}
