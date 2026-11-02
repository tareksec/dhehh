<?php

namespace App\Jobs;

use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Meta CDN media URLs expire within 24-48 hours, so incoming media is copied
 * to our own storage as soon as the webhook arrives.
 */
class DownloadConversationMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $messageId,
        public int $channelId,
        public ?string $mediaId = null,
        public ?string $mediaUrl = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->channelId.':'.$this->messageId;
    }

    public function handle(CompanyContext $context, CompanyStorageService $storage, ?MetaGraphService $metaGraph = null): void
    {
        $metaGraph ??= app(MetaGraphService::class);
        $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()->with('company')->findOrFail($this->channelId);
        $context->set($channel->company);
        $message = null;

        try {
            $message = ConversationMessage::query()
                ->whereHas('conversation', fn (Builder $query): Builder => $query
                    ->withoutGlobalScopes()
                    ->where('company_id', $channel->company_id)
                    ->where('channel_id', $channel->getKey()))
                ->findOrFail($this->messageId);

            $url = $this->mediaUrl;
            $mime = $message->media_mime;
            $expectedBytes = null;

            // WhatsApp media ids must first be exchanged for a temporary URL.
            if (! $url && $this->mediaId) {
                $meta = $metaGraph->resolveWhatsAppMedia($channel, $this->mediaId);

                $url = $meta['url'] ?? null;
                $mime = $mime ?: ($meta['mime_type'] ?? null);
                $expectedBytes = isset($meta['file_size']) ? (int) $meta['file_size'] : null;
            }

            if (! $url && $this->mediaId) {
                throw new MetaGraphException('Meta returned no download URL for the incoming attachment. Retry after checking the channel token.');
            }

            if (! $url) {
                return;
            }

            $response = $metaGraph->downloadMedia($channel, $url, $expectedBytes);
            $mime = $mime ?: $response->header('Content-Type');

            $extension = Str::of((string) $mime)
                ->after('/')
                ->before(';')
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '')
                ->limit(12, '')
                ->value() ?: 'bin';
            $messageName = Str::of($message->external_message_id ?: Str::random(20))
                ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
                ->trim('.-_')
                ->limit(160, '')
                ->value() ?: Str::random(20);

            $path = $storage->putPrivate(
                $channel->company,
                'conversation-media',
                "{$messageName}.{$extension}",
                $response->body(),
            );

            $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
            $mediaError = data_get($raw, '_local.media_download_error');
            data_forget($raw, '_local.media_download_error');

            $message->forceFill([
                'media_path' => $path,
                'media_mime' => $mime,
                'raw_payload' => $raw,
            ])->save();

            if ($message->direction === 'incoming' && in_array($message->type, ['image', 'audio'], true)) {
                AiAutoReplyJob::dispatchAfterWait($message->conversation_id, $message->id);
            }

            $channel->refresh();

            if (is_array($mediaError)
                && filled($channel->last_error)
                && hash_equals((string) ($mediaError['message'] ?? ''), (string) $channel->last_error)
                && (string) ($mediaError['recorded_at'] ?? '') === (string) $channel->last_error_at?->toIso8601String()) {
                $channel->clearDiagnosticError('media');
            }
        } catch (Throwable $exception) {
            $channel->recordDiagnosticError($exception instanceof MetaGraphException
                ? $exception->getMessage()
                : 'Incoming Meta media could not be downloaded. Check the channel token and retry.', 'media');

            if ($message) {
                $channel->refresh();
                $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
                data_set($raw, '_local.media_download_error', [
                    'message' => $channel->last_error,
                    'recorded_at' => $channel->last_error_at?->toIso8601String(),
                ]);
                $message->forceFill(['raw_payload' => $raw])->saveQuietly();
            }

            throw $exception;
        } finally {
            $context->clear();
        }
    }
}
