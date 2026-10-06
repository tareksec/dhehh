<?php

namespace App\Services\WhatsAppWeb;

use App\Models\ConversationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the self-hosted whatsapp-bridge service (see whatsapp-bridge/).
 * One bridge session per 'web' ConversationChannel, keyed by its
 * external_id. The bridge signs every webhook it sends back with the
 * channel's app_secret.
 */
class WhatsAppWebBridgeClient
{
    public function __construct(protected WhatsAppWebSettingsService $settings) {}

    /** Starts (or restarts) the session; the bridge then produces a QR code or reconnects. */
    public function connect(ConversationChannel $channel): array
    {
        return $this->call($channel, fn (PendingRequest $http): Response => $http->put($this->sessionPath($channel), [
            'webhook_url' => route('webhooks.whatsapp-web.handle', ['channel' => $channel->getKey()]),
            'webhook_secret' => (string) $channel->app_secret,
        ]));
    }

    /** Live session state: status, QR code data URL, phone, history progress. Null when the bridge does not know it. */
    public function status(ConversationChannel $channel): ?array
    {
        $response = $this->send($channel, fn (PendingRequest $http): Response => $http->get($this->sessionPath($channel)));

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /** Unlinks the device. Everything already archived in the ERP stays. */
    public function logout(ConversationChannel $channel): array
    {
        return $this->call($channel, fn (PendingRequest $http): Response => $http->delete($this->sessionPath($channel)));
    }

    protected function call(ConversationChannel $channel, callable $request): array
    {
        return $this->decode($this->send($channel, $request));
    }

    protected function send(ConversationChannel $channel, callable $request): Response
    {
        $company = $channel->company()->withoutGlobalScopes()->first();
        $settings = $company ? $this->settings->all($company) : ['bridge_url' => '', 'api_key' => null];

        if (blank($settings['bridge_url']) || blank($settings['api_key'])) {
            throw new WhatsAppWebBridgeException(__('Save the WhatsApp Web bridge URL and API key first.'));
        }

        try {
            return $request(Http::baseUrl($settings['bridge_url'])
                ->withToken($settings['api_key'])
                ->acceptJson()
                ->timeout(20));
        } catch (ConnectionException) {
            throw new WhatsAppWebBridgeException(__('Could not reach the WhatsApp Web bridge. Check that it is running and the URL is correct.'));
        }
    }

    protected function decode(Response $response): array
    {
        if ($response->status() === 401) {
            throw new WhatsAppWebBridgeException(__('The WhatsApp Web bridge rejected the API key.'));
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new WhatsAppWebBridgeException(__('The WhatsApp Web bridge returned an error (HTTP :status).', ['status' => $response->status()]));
        }

        return $response->json();
    }

    protected function sessionPath(ConversationChannel $channel): string
    {
        return '/sessions/'.rawurlencode((string) $channel->external_id);
    }
}
