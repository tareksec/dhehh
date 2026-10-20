<?php

namespace App\Services\Meta;

use App\Models\ConversationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class MetaGraphService
{
    public function version(): string
    {
        $version = (string) config('services.meta.graph_api_version', 'v25.0');

        return preg_match('/\Av\d+\.\d+\z/', $version) === 1 ? $version : 'v25.0';
    }

    public function messengerProfileName(ConversationChannel $channel, string $contactId): ?string
    {
        if ($channel->provider !== 'messenger' || ! $channel->is_active || blank($channel->access_token)) {
            return null;
        }
        $profile = $this->request('GET', rawurlencode($contactId), (string) $channel->access_token, ['fields' => 'first_name,last_name'], retry: false);
        $name = trim((string) ($profile['first_name'] ?? '').' '.(string) ($profile['last_name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    public function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    public function channelHealth(ConversationChannel $channel): array
    {
        $fields = $channel->provider === 'whatsapp'
            ? 'id,display_phone_number,verified_name,quality_rating,status'
            : 'id,name';

        try {
            $this->requireToken($channel);

            $health = $this->request('GET', rawurlencode((string) $channel->external_id), (string) $channel->access_token, [
                'fields' => $fields,
            ]);
            $channel->markHealthChecked();

            return $health;
        } catch (MetaGraphException $exception) {
            $channel->recordDiagnosticError($exception->getMessage(), 'connection');

            throw $exception;
        }
    }

    /** @return array{subscribed: bool, applications: array<int, mixed>} */
    public function whatsappSubscription(ConversationChannel $channel): array
    {
        try {
            $this->requireWhatsAppWaba($channel);

            $response = $this->request(
                'GET',
                rawurlencode((string) $channel->waba_id).'/subscribed_apps',
                (string) $channel->access_token,
            );
            $applications = array_values(array_filter((array) ($response['data'] ?? []), 'is_array'));

            return ['subscribed' => $applications !== [], 'applications' => $applications];
        } catch (MetaGraphException $exception) {
            $channel->recordDiagnosticError($exception->getMessage(), 'connection');

            throw $exception;
        }
    }

    /**
     * Approved message templates for this WABA -- the only templates Meta
     * will actually deliver for a cold, business-initiated send (broadcasts,
     * follow-up reminders). Never surface PENDING/REJECTED templates to a
     * picker; queuing a send against one of those just fails at Meta.
     *
     * @return array<int, array{name: string, language: string, category: ?string}>
     */
    public function listMessageTemplates(ConversationChannel $channel): array
    {
        $this->requireWhatsAppWaba($channel);

        $response = $this->request(
            'GET',
            rawurlencode((string) $channel->waba_id).'/message_templates',
            (string) $channel->access_token,
            ['fields' => 'name,status,language,category', 'limit' => 200],
        );

        return collect((array) ($response['data'] ?? []))
            ->filter(fn (mixed $template): bool => is_array($template) && ($template['status'] ?? null) === 'APPROVED')
            ->map(fn (array $template): array => [
                'name' => (string) $template['name'],
                'language' => (string) ($template['language'] ?? 'en'),
                'category' => $template['category'] ?? null,
            ])
            ->unique(fn (array $template): string => $template['name'].'|'.$template['language'])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function subscribeWhatsApp(ConversationChannel $channel): array
    {
        try {
            $this->requireWhatsAppWaba($channel);

            $response = $this->request(
                'POST',
                rawurlencode((string) $channel->waba_id).'/subscribed_apps',
                (string) $channel->access_token,
            );

            if (($response['success'] ?? false) !== true) {
                throw new MetaGraphException('Meta did not confirm the webhook subscription. Check the WABA ID and token permissions.');
            }

            $channel->markWebhookSubscribed();

            return $response;
        } catch (MetaGraphException $exception) {
            $channel->recordDiagnosticError($exception->getMessage(), 'connection');

            throw $exception;
        }
    }

    /** @return array{health: array<string, mixed>, subscription: array<string, mixed>|null} */
    public function testAndSubscribe(ConversationChannel $channel): array
    {
        try {
            $health = $this->channelHealth($channel);
            $subscription = null;

            if ($channel->provider === 'whatsapp') {
                $subscription = $this->whatsappSubscription($channel);
                $subscription['was_subscribed'] = $subscription['subscribed'];
                $this->subscribeWhatsApp($channel);
                $subscription['subscribed'] = true;
            } elseif ($channel->provider === 'messenger') {
                $path = rawurlencode((string) $channel->external_id).'/subscribed_apps';
                $current = $this->request('GET', $path, (string) $channel->access_token, ['fields' => 'subscribed_fields']);
                $fields = array_values(array_unique(['messages', 'message_echoes', 'message_deliveries', 'message_reads',
                    ...collect($current['data'] ?? [])->flatMap(fn ($app) => $app['subscribed_fields'] ?? [])->all()]));
                $result = $this->request('POST', $path, (string) $channel->access_token, ['subscribed_fields' => implode(',', $fields)], retry: false);
                if (! ($result['success'] ?? false)) {
                    throw new MetaGraphException('Meta did not confirm the Messenger webhook subscription.');
                }
                $channel->forceFill(['webhook_subscribed_at' => now()])->save();
                $subscription = ['subscribed' => true, 'fields' => $fields];
            }

            $channel->clearDiagnosticError('connection');

            return ['health' => $health, 'subscription' => $subscription];
        } catch (MetaGraphException $exception) {
            $message = $exception->getMessage();
            if ($channel->provider === 'messenger' && in_array($exception->graphCode, [10, 200], true)) {
                $message = 'Messenger subscription permission denied (code '.$exception->graphCode.'). Check pages_manage_metadata and pages_messaging permissions and the Page access token for this Page. '.$exception->getMessage();
            }
            $channel->recordDiagnosticError($message, 'connection');
            throw new MetaGraphException($message, $exception->graphCode, $exception->httpStatus);
        }
    }

    /**
     * Exchanges an Embedded Signup authorization code for a long-lived access
     * token. Called once, right after the owner completes the Meta popup, so
     * there is no ConversationChannel yet - the app credentials come from the
     * admin-configurable Embedded Signup settings instead of a channel token.
     */
    public function exchangeEmbeddedSignupCode(string $appId, string $appSecret, string $code): string
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get($this->url('oauth/access_token'), [
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                    'code' => $code,
                ]);
        } catch (Throwable $exception) {
            throw new MetaGraphException('Could not reach Meta to exchange the Embedded Signup code. Check the server network and retry.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response);
        }

        $token = (string) $response->json('access_token', '');

        if ($token === '') {
            throw new MetaGraphException('Meta accepted the Embedded Signup code but returned no access token.');
        }

        return $token;
    }

    public function sendWhatsApp(ConversationChannel $channel, string $to, string $body, ?string $mediaUrl = null): string
    {
        $this->requireToken($channel);
        $mediaUrl = $this->outboundMediaUrl($mediaUrl);

        $payload = $mediaUrl
            ? ['type' => 'image', 'image' => ['link' => $mediaUrl, 'caption' => $body]]
            : ['type' => 'text', 'text' => ['body' => $body, 'preview_url' => true]];

        $response = $this->request('POST', rawurlencode((string) $channel->external_id).'/messages', (string) $channel->access_token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            ...$payload,
        ], retry: false);
        $messageId = (string) data_get($response, 'messages.0.id', '');

        if ($messageId === '') {
            throw new MetaGraphException('Meta accepted the request but returned no message ID. Check the channel in Meta Business Manager.');
        }

        return $messageId;
    }

    public function sendMessenger(ConversationChannel $channel, string $psid, string $body, ?string $mediaUrl = null, ?string $metadata = null): string
    {
        $this->requireToken($channel);
        $mediaUrl = $this->outboundMediaUrl($mediaUrl);

        $messageBody = $mediaUrl !== null ? $body."\n\n".$mediaUrl : $body;

        $response = $this->request('POST', 'me/messages', (string) $channel->access_token, [
            'recipient' => ['id' => $psid],
            'message' => array_filter(['text' => $messageBody, 'metadata' => $metadata], fn ($value) => $value !== null),
            'messaging_type' => 'RESPONSE',
        ], retry: false);
        $messageId = (string) ($response['message_id'] ?? '');

        if ($messageId === '') {
            throw new MetaGraphException('Meta accepted the request but returned no message ID. Check the Page connection.');
        }

        return $messageId;
    }

    public function markWhatsAppRead(ConversationChannel $channel, string $messageId): void
    {
        $this->request('POST', rawurlencode((string) $channel->external_id).'/messages', (string) $channel->access_token, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    /**
     * "Seen" and "typing..." for a Messenger customer (Send API sender
     * actions). typing_on lasts about 20 seconds or until the next message.
     */
    public function sendMessengerAction(ConversationChannel $channel, string $psid, string $action): void
    {
        if (! in_array($action, ['mark_seen', 'typing_on', 'typing_off'], true)) {
            throw new MetaGraphException('That Messenger action is not supported.');
        }
        $this->requireToken($channel);

        $this->request('POST', 'me/messages', (string) $channel->access_token, [
            'recipient' => ['id' => $psid],
            'sender_action' => $action,
        ], retry: false);
    }

    /**
     * Marks the customer's message as read and shows "typing..." for up to 25
     * seconds, or until the next message is sent (WhatsApp Cloud API).
     */
    public function sendWhatsAppTyping(ConversationChannel $channel, string $messageId): void
    {
        $this->requireToken($channel);

        $this->request('POST', rawurlencode((string) $channel->external_id).'/messages', (string) $channel->access_token, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => ['type' => 'text'],
        ], retry: false);
    }

    /**
     * A Messenger carousel (generic template). Meta allows 10 cards; each
     * element is built by the caller: title, subtitle, image_url, buttons.
     *
     * @param  array<int, array<string, mixed>>  $elements
     */
    public function sendMessengerCards(ConversationChannel $channel, string $psid, array $elements, ?string $metadata = null): string
    {
        $this->requireToken($channel);
        $elements = array_slice(array_values($elements), 0, 10);

        if ($elements === []) {
            throw new MetaGraphException('There is no product card to send.');
        }

        $response = $this->request('POST', 'me/messages', (string) $channel->access_token, [
            'recipient' => ['id' => $psid],
            'message' => array_filter([
                'attachment' => ['type' => 'template', 'payload' => ['template_type' => 'generic', 'elements' => $elements]],
                'metadata' => $metadata,
            ], fn ($value) => $value !== null),
            'messaging_type' => 'RESPONSE',
        ], retry: false);
        $messageId = (string) ($response['message_id'] ?? '');

        if ($messageId === '') {
            throw new MetaGraphException('Meta accepted the request but returned no message ID. Check the Page connection.');
        }

        return $messageId;
    }

    /** An absolute public URL Meta can fetch, or null (relative paths are expanded with APP_URL; local addresses are dropped). */
    public function publicMediaUrl(?string $url): ?string
    {
        return $this->outboundMediaUrl($url);
    }

    /** @return array<string, mixed> */
    public function resolveWhatsAppMedia(ConversationChannel $channel, string $mediaId): array
    {
        return $this->request('GET', rawurlencode($mediaId), (string) $channel->access_token);
    }

    public function downloadMedia(ConversationChannel $channel, string $url, ?int $expectedBytes = null): Response
    {
        $this->requireToken($channel);

        if (! $this->isAllowedMediaUrl($url)) {
            throw new MetaGraphException('Meta returned an untrusted media URL. The attachment was not downloaded.');
        }

        $maxBytes = max(1, (int) config('services.meta.max_media_bytes', 26214400));

        if ($expectedBytes !== null && $expectedBytes > $maxBytes) {
            throw new MetaGraphException('The Meta attachment is larger than the configured media limit and was not downloaded.');
        }

        try {
            $response = $this->client((string) $channel->access_token)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                        $length = (int) $response->getHeaderLine('Content-Length');

                        if ($length > $maxBytes) {
                            throw new MetaGraphException('The Meta attachment is larger than the configured media limit and was not downloaded.');
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
                        if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                            throw new MetaGraphException('The Meta attachment is larger than the configured media limit and was not downloaded.');
                        }
                    },
                ])
                ->get($url);
        } catch (MetaGraphException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            for ($cause = $exception->getPrevious(); $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof MetaGraphException) {
                    throw $cause;
                }
            }

            throw new MetaGraphException('Could not download Meta media. Retry after checking the channel token.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response);
        }

        $contentLength = (int) $response->header('Content-Length');

        if (($contentLength > 0 && $contentLength > $maxBytes) || strlen($response->body()) > $maxBytes) {
            throw new MetaGraphException('The Meta attachment is larger than the configured media limit and was not downloaded.');
        }

        return $response;
    }

    /** @param array<int, mixed> $bodyParameters */
    public function sendWhatsAppTemplate(
        string $phoneNumberId,
        string $accessToken,
        string $to,
        string $templateName,
        string $language,
        array $bodyParameters = [],
    ): string {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        if ($bodyParameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value): array => ['type' => 'text', 'text' => $value],
                    $bodyParameters,
                ),
            ]];
        }

        $response = $this->request('POST', rawurlencode($phoneNumberId).'/messages', $accessToken, $payload, retry: false);
        $messageId = (string) data_get($response, 'messages.0.id', '');

        if ($messageId === '') {
            throw new MetaGraphException('Meta accepted the template request but returned no message ID. Check the WhatsApp template and channel setup.');
        }

        return $messageId;
    }

    public function statusErrorMessage(array $status): ?string
    {
        $error = data_get($status, 'errors.0');

        if (! is_array($error)) {
            return null;
        }

        return $this->friendlyError(
            (string) ($error['message'] ?? $error['title'] ?? 'Meta reported a delivery failure.'),
            isset($error['code']) ? (int) $error['code'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function sanitizedStatusMetadata(array $status): array
    {
        return array_filter([
            'status' => isset($status['status']) ? (string) $status['status'] : null,
            'timestamp' => isset($status['timestamp']) ? (string) $status['timestamp'] : null,
            'recipient_id' => isset($status['recipient_id']) ? (string) $status['recipient_id'] : null,
            'conversation_id' => data_get($status, 'conversation.id'),
            'pricing_category' => data_get($status, 'pricing.category'),
            'error' => $this->statusErrorMessage($status),
            'error_code' => data_get($status, 'errors.0.code'),
            'error_subcode' => data_get($status, 'errors.0.error_subcode'),
            'error_title' => $this->safeText((string) data_get($status, 'errors.0.title', '')),
            'error_details' => $this->safeText((string) data_get($status, 'errors.0.error_data.details', '')),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    protected function request(string $method, string $path, string $accessToken, array $data = [], bool $retry = true): array
    {
        if (blank($accessToken)) {
            throw new MetaGraphException('The Meta access token is missing. Add a permanent system-user token in Chat Channels.');
        }

        try {
            $options = strtoupper($method) === 'GET' ? ['query' => $data] : ['json' => $data];
            $response = $this->client($accessToken, $retry)->send(strtoupper($method), $this->url($path), $options);
        } catch (MetaGraphException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MetaGraphException('Could not reach Meta. Check the server network and retry.');
        }

        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function client(string $accessToken, bool $retry = true): PendingRequest
    {
        $client = Http::acceptJson()
            ->withToken($accessToken)
            ->connectTimeout(5)
            ->timeout(20);

        return $retry ? $client->retry(
            [200, 500],
            when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && in_array($exception->response?->status(), [429, 500, 502, 503, 504], true)),
            throw: false,
        ) : $client;
    }

    protected function exceptionFromResponse(Response $response): MetaGraphException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $message = $this->friendlyError((string) ($error['message'] ?? ''), $code);

        return new MetaGraphException($message, $code, $response->status());
    }

    protected function friendlyError(string $message, ?int $code): string
    {
        $safeMessage = $this->safeText($message);

        if ($code === 190 || Str::contains(Str::lower($safeMessage), ['token has expired', 'session has expired', 'invalid oauth access token'])) {
            return 'The Meta access token has expired or is invalid. Generate a new permanent system-user token, save it in Chat Channels, then run Test & Subscribe.';
        }

        if (in_array($code, [10, 200], true)) {
            return 'Meta permission denied (code '.$code.'): '.$safeMessage;
        }

        if ($code === 131047 || Str::contains(Str::lower($safeMessage), ['24 hour', 're-engagement'])) {
            return 'The customer-service reply window is closed. Send an approved WhatsApp template or wait for the customer to message again.';
        }

        if ($safeMessage === '') {
            return 'Meta rejected the request. Check the channel IDs, token permissions, and app mode.';
        }

        return 'Meta rejected the request'.($code ? " (code {$code})" : '').': '.$safeMessage;
    }

    protected function safeText(string $message): string
    {
        return trim(Str::limit(preg_replace([
            '/access[_ -]?token\s*[:=]\s*[^\s,;]+/iu',
            '/bearer\s+[a-z0-9._-]+/iu',
            '/EAA[A-Za-z0-9_-]{20,}/',
        ], ['access token [redacted]', 'Bearer [redacted]', '[redacted token]'], strip_tags($message)) ?? '', 500, ''));
    }

    protected function requireToken(ConversationChannel $channel): void
    {
        if (blank($channel->access_token)) {
            throw new MetaGraphException('The Meta access token is missing. Add a permanent system-user token in Chat Channels.');
        }
    }

    protected function requireWhatsAppWaba(ConversationChannel $channel): void
    {
        $this->requireToken($channel);

        if ($channel->provider !== 'whatsapp') {
            throw new MetaGraphException('WABA subscription is available only for WhatsApp channels.');
        }

        if (blank($channel->waba_id)) {
            throw new MetaGraphException('The WhatsApp Business Account ID is missing. Add the WABA ID, not the Phone Number ID, then retry.');
        }
    }

    protected function isAllowedMediaUrl(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['facebook.com', 'fbcdn.net', 'fbsbx.com'] as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.'.$allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meta must fetch outbound media from a public absolute URL. Local public
     * disk URLs are root-relative, so expand them with APP_URL; when APP_URL
     * still points at a loopback/private IP, omit the image and send the text
     * instead of letting Meta reject the complete message.
     */
    protected function outboundMediaUrl(?string $mediaUrl): ?string
    {
        if (blank($mediaUrl)) {
            return null;
        }

        $candidate = trim((string) $mediaUrl);

        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            $appUrl = rtrim((string) config('app.url'), '/');

            if ($appUrl === '') {
                return null;
            }

            $candidate = $appUrl.$candidate;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        $host = trim(strtolower((string) parse_url($candidate, PHP_URL_HOST)), '[]');

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $candidate;
    }
}
