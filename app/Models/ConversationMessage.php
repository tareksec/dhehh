<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use LogicException;

class ConversationMessage extends Model
{
    public const TYPES = [
        'text' => 'Text',
        'image' => 'Image',
        'audio' => 'Audio',
        'video' => 'Video',
        'document' => 'Document',
        'sticker' => 'Sticker',
        'template' => 'Template',
        'order_form' => 'Order Form',
        'note' => 'Note',
    ];

    protected $fillable = [
        'conversation_id', 'direction', 'type', 'body', 'media_path', 'media_mime',
        'external_message_id', 'delivery_status', 'delivery_channel', 'sent_by', 'generated_by',
        'ai_confidence', 'ai_meta', 'raw_payload', 'sent_at', 'revoked_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'ai_meta' => 'array',
        'ai_confidence' => 'decimal:3',
        'sent_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ConversationMessage $message): void {
            if (! $message->isDirty('media_path') || blank($message->media_path)) {
                return;
            }

            $path = trim((string) $message->media_path);

            if (static::isPublicMediaReference($path)) {
                return;
            }

            $conversation = $message->conversation()
                ->withoutGlobalScopes()
                ->with('company')
                ->first();
            $company = $conversation?->company;

            if (! $company) {
                throw new LogicException('Conversation media requires an owning company.');
            }

            if (str_starts_with($path, $company->storageRoot().'/private/')) {
                return;
            }

            if (! str_starts_with($path, 'companies/')
                && LegacyPrivateStoragePath::allows($path, (int) $company->getKey())) {
                return;
            }

            throw new LogicException('Conversation media must be a public URL or a private path owned by its company.');
        });
    }

    /**
     * Escaped message body with bare URLs turned into links that open in a
     * new tab, so order-form links are tappable straight from the thread.
     */
    public function bodyHtml(): HtmlString
    {
        $escaped = e((string) $this->body);

        $linked = preg_replace_callback(
            '~https?://[^\s<]+~u',
            fn (array $match): string => '<a href="'.$match[0].'" target="_blank" rel="noopener noreferrer">'.$match[0].'</a>',
            $escaped,
        );

        return new HtmlString($linked ?? $escaped);
    }

    /**
     * Resolves an image attachment to either its existing external catalog
     * URL or the authenticated, company-authorized media endpoint. Downloaded
     * webhook media is never exposed through a public storage URL.
     */
    public function mediaImageUrl(): ?string
    {
        if (blank($this->media_path)) {
            return null;
        }

        $isImage = str_starts_with((string) $this->media_mime, 'image')
            || in_array(strtolower(pathinfo(parse_url((string) $this->media_path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);

        if (! $isImage) {
            return null;
        }

        return $this->mediaDownloadUrl();
    }

    /**
     * URL used to view or download any attachment from the authenticated ERP
     * inbox. Fully-qualified catalog URLs are already public; every stored
     * relative path is served through an authorization-checking controller.
     */
    public function mediaDownloadUrl(): ?string
    {
        if (blank($this->media_path)) {
            return null;
        }

        $path = trim((string) $this->media_path);
        $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));

        if (filter_var($path, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)) {
            return $path;
        }

        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return $path;
        }

        if (! $this->exists) {
            return null;
        }

        return route('conversation-messages.media', ['message' => $this->getKey()]);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    protected static function isPublicMediaReference(string $path): bool
    {
        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));

        return filter_var($path, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);
    }
}
