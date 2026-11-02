<?php

namespace App\Http\Controllers;

use App\Jobs\ImportWhatsAppWebMessagesJob;
use App\Jobs\RefreshWhatsAppWebNamesJob;
use App\Jobs\SyncWhatsAppWebContactsJob;
use App\Models\ConversationChannel;
use App\Services\CompanyContext;
use App\Services\WhatsAppWeb\WhatsAppWebArchiveService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives what the self-hosted whatsapp-bridge syncs from a WhatsApp Web
 * (QR login) linked device. Every request is HMAC-SHA256 signed with the
 * channel's app_secret (same "sha256=<hex>" format Meta uses).
 *
 * Inactive channels are still archived on purpose: the point of this
 * channel is that no customer message is ever lost.
 */
class WhatsAppWebWebhookController extends Controller
{
    /** Live batches up to this size are saved immediately instead of through the queue. */
    protected const INLINE_LIVE_BATCH = 25;

    public function handle(Request $request, int $channel): Response
    {
        $channel = $this->channel($channel);

        if (! $channel->verifySignature($request->getContent(), $request->header('X-Bridge-Signature'))) {
            Log::warning('WhatsApp Web bridge signature rejected.', ['channel_id' => $channel->getKey(), 'ip' => $request->ip()]);

            return response('INVALID_SIGNATURE', 401);
        }

        $payload = $request->json()->all();
        $data = (array) ($payload['data'] ?? []);

        match ((string) ($payload['type'] ?? '')) {
            'connection' => $this->applyConnection($channel, $data),
            'messages' => $this->importMessages($channel, $data),
            'contacts' => SyncWhatsAppWebContactsJob::dispatch(
                $channel->getKey(),
                array_values(array_filter((array) ($data['contacts'] ?? []), 'is_array')),
            ),
            'history' => $this->applyHistoryProgress($channel, $data),
            default => null,
        };

        $channel->markWebhookReceived();

        return response('OK', 200);
    }

    /** Raw media bytes for one archived message; signature covers "<message id>\n<bytes>". */
    public function media(Request $request, int $channel, WhatsAppWebArchiveService $archive, CompanyContext $context): Response
    {
        $channel = $this->channel($channel);
        $messageId = trim((string) $request->header('X-Message-Id'));
        $contents = $request->getContent();

        if ($messageId === '' || ! $channel->verifySignature($messageId."\n".$contents, $request->header('X-Bridge-Signature'))) {
            return response('INVALID_SIGNATURE', 401);
        }

        if ($contents === '') {
            return response('EMPTY_MEDIA', 422);
        }

        $context->set($channel->company);

        try {
            $stored = $archive->attachMedia(
                $channel,
                $messageId,
                $contents,
                $request->header('X-Media-Mime'),
                rawurldecode((string) $request->header('X-File-Name')),
            );
        } finally {
            $context->clear();
        }

        // 409 tells the bridge the message batch is still queued here; it retries later.
        return $stored ? response('OK', 200) : response('MESSAGE_NOT_ARCHIVED_YET', 409);
    }

    /**
     * New messages are saved while the bridge waits, so they show in the Inbox
     * without any queue delay. If saving fails the bridge gets an error and
     * retries from its own outbox. History batches are large and stay queued.
     */
    protected function importMessages(ConversationChannel $channel, array $data): void
    {
        $messages = array_values(array_filter((array) ($data['messages'] ?? []), 'is_array'));
        $history = (bool) ($data['history'] ?? false);
        $job = new ImportWhatsAppWebMessagesJob($channel->getKey(), $messages, $history);

        if (! $history && count($messages) <= self::INLINE_LIVE_BATCH) {
            dispatch_sync($job);

            return;
        }

        dispatch($job);
    }

    protected function channel(int $id): ConversationChannel
    {
        return ConversationChannel::withoutGlobalScopes()->withoutTrashed()
            ->with('company')
            ->where('connection_type', 'web')
            ->findOrFail($id);
    }

    protected function applyConnection(ConversationChannel $channel, array $data): void
    {
        $status = (string) ($data['status'] ?? '');

        if (! array_key_exists($status, ConversationChannel::WEB_STATUSES)) {
            return;
        }

        $updates = ['web_status' => $status];
        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));

        if ($phone !== '') {
            $updates['web_phone'] = $phone;
        }

        if ($status === 'connected') {
            $updates['web_connected_at'] = now();
        }

        if (in_array($status, ['logged_out', 'banned', 'replaced'], true)) {
            $updates['web_disconnected_at'] = now();
        }

        $channel->forceFill($updates)->saveQuietly();

        if (in_array($status, ['logged_out', 'banned', 'replaced', 'error'], true)) {
            $channel->recordDiagnosticError(
                __('WhatsApp Web device disconnected (:status). All archived chats are kept; scan a new QR code to resume syncing.', [
                    'status' => __(ConversationChannel::WEB_STATUSES[$status]),
                ]),
                'connection',
            );
        } elseif ($status === 'connected') {
            $channel->clearDiagnosticError('connection');
        }
    }

    protected function applyHistoryProgress(ConversationChannel $channel, array $data): void
    {
        $progress = $data['progress'] ?? null;

        if (is_numeric($progress) && (float) $progress >= 100) {
            $firstCompletion = $channel->web_history_synced_at === null;

            $channel->forceFill(['web_history_synced_at' => now()])->saveQuietly();

            // Names and chats arrive in separate batches; match them up once the queue has caught up.
            if ($firstCompletion) {
                RefreshWhatsAppWebNamesJob::dispatch($channel->getKey())->delay(now()->addMinutes(2));
            }
        }
    }
}
