<?php

namespace App\Http\Controllers;

use App\Jobs\StoreIncomingMessageJob;
use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\Meta\MetaGraphService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class MetaWebhookController extends Controller
{
    /**
     * 'messages' is the standard live-message flow; the other three only
     * ever arrive for a channel connected via Coexistence Embedded Signup -
     * contacts sync, historical message backfill, and phone-app message
     * echoes, respectively. All four route/verify identically.
     */
    protected const WHATSAPP_FIELDS = ['messages', 'smb_app_state_sync', 'history', 'smb_message_echoes'];

    /** Meta webhook subscription handshake (hub.challenge). */
    public function verify(Request $request): Response
    {
        $mode = $this->hubQuery($request, 'mode');
        $token = $this->hubQuery($request, 'verify_token');
        $challenge = $this->hubQuery($request, 'challenge');

        $channels = $mode === 'subscribe' && $token !== ''
            ? ConversationChannel::withoutGlobalScopes()->withoutTrashed()
                ->where('is_active', true)
                ->where('verify_token', $token)
                ->get()
            : collect();

        if ($channels->isEmpty()) {
            $this->logRejectedRequest($request, 'Meta webhook verification rejected.', [
                'mode' => $mode,
                'verify_token_present' => $token !== '',
            ]);

            return response('Verification failed. Check the callback URL and verify token.', 403);
        }

        $channels->each->markWebhookVerified();

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Verifies the exact raw request bytes, routes every entry/change to its
     * own channel, and persists core messages/statuses before acknowledging.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();
        $routes = $this->routePayload($payload);

        if ($routes === []) {
            if ($this->containsOnlyUnsupportedWhatsAppChanges($payload)) {
                return response('IGNORED_EVENT', 200);
            }

            Log::warning('Meta webhook had no configured active channel.', [
                'object' => isset($payload['object']) ? (string) $payload['object'] : null,
            ]);

            return response('CHANNEL_NOT_FOUND', 404);
        }

        $signature = $request->header('X-Hub-Signature-256');
        $rawPayload = $request->getContent();

        foreach ($this->uniqueChannels($routes) as $channel) {
            if (! $channel->verifySignature($rawPayload, $signature)) {
                $this->logRejectedRequest($request, 'Meta webhook signature rejected.', [
                    'channel_id' => $channel->getKey(),
                    'provider' => $channel->provider,
                ]);

                return response('INVALID_SIGNATURE', 403);
            }
        }

        foreach ($routes as $route) {
            /** @var ConversationChannel $channel */
            $channel = $route['channel'];
            $channel->markWebhookReceived();

            try {
                (new StoreIncomingMessageJob($channel->getKey(), $route['payload']))
                    ->handle(app(CompanyContext::class), app(MetaGraphService::class));
            } catch (Throwable $exception) {
                $channel->recordDiagnosticError('Webhook processing failed. Check the application logs and retry the Meta test event.', 'webhook');
                Log::error('Meta webhook processing failed.', [
                    'channel_id' => $channel->getKey(),
                    'provider' => $channel->provider,
                    'exception' => $exception::class,
                ]);

                throw $exception;
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /** @return array<int, array{channel: ConversationChannel, payload: array<string, mixed>}> */
    protected function routePayload(array $payload): array
    {
        return match ($payload['object'] ?? null) {
            'whatsapp_business_account' => $this->routeWhatsApp($payload),
            'page' => $this->routeMessenger($payload),
            default => [],
        };
    }

    protected function containsOnlyUnsupportedWhatsAppChanges(array $payload): bool
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return false;
        }

        $hasChange = false;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $hasChange = true;

                if (in_array($change['field'] ?? null, self::WHATSAPP_FIELDS, true)) {
                    return false;
                }
            }
        }

        return $hasChange;
    }

    /** @return array<int, array{channel: ConversationChannel, payload: array<string, mixed>}> */
    protected function routeWhatsApp(array $payload): array
    {
        $routes = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $wabaId = (string) ($entry['id'] ?? '');

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ! in_array($change['field'] ?? null, self::WHATSAPP_FIELDS, true)) {
                    continue;
                }

                $phoneNumberId = (string) data_get($change, 'value.metadata.phone_number_id', '');

                if ($phoneNumberId === '') {
                    continue;
                }

                $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()
                    ->where('provider', 'whatsapp')
                    ->where('external_id', $phoneNumberId)
                    ->where('is_active', true)
                    ->first();

                if (! $channel || (filled($channel->waba_id) && ! hash_equals((string) $channel->waba_id, $wabaId))) {
                    continue;
                }

                $routes[] = [
                    'channel' => $channel,
                    'payload' => [
                        'object' => 'whatsapp_business_account',
                        'entry' => [[
                            'id' => $wabaId,
                            'changes' => [$change],
                        ]],
                    ],
                ];
            }
        }

        return $routes;
    }

    /** @return array<int, array{channel: ConversationChannel, payload: array<string, mixed>}> */
    protected function routeMessenger(array $payload): array
    {
        $routes = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = (string) ($entry['id'] ?? '');
            $channel = $pageId !== '' ? ConversationChannel::withoutGlobalScopes()->withoutTrashed()
                ->where('provider', 'messenger')
                ->where('external_id', $pageId)
                ->where('is_active', true)
                ->first() : null;

            if (! $channel) {
                continue;
            }

            $routes[] = [
                'channel' => $channel,
                'payload' => [
                    'object' => 'page',
                    'entry' => [$entry],
                ],
            ];
        }

        return $routes;
    }

    /** @param array<int, array{channel: ConversationChannel, payload: array<string, mixed>}> $routes */
    protected function uniqueChannels(array $routes): array
    {
        $channels = [];

        foreach ($routes as $route) {
            $channels[$route['channel']->getKey()] = $route['channel'];
        }

        return array_values($channels);
    }

    protected function hubQuery(Request $request, string $name): string
    {
        foreach (["hub.{$name}", "hub_{$name}"] as $key) {
            $value = $request->query($key);

            if ($value !== null) {
                return (string) $value;
            }
        }

        return '';
    }

    protected function logRejectedRequest(Request $request, string $message, array $context): void
    {
        $key = 'meta-webhook-rejection:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        RateLimiter::hit($key, 60);
        Log::warning($message, $context);
    }
}
