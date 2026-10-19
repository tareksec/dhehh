<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\CompanyContext;
use App\Services\Crm\AiReplyService;
use App\Services\Crm\AiSettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class AiAutoReplyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 25; // lock contention is released; inference failures hand off

    public int $timeout = 70;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $conversationId,
        public ?int $sourceMessageId = null,
    ) {}

    /**
     * Queue the reply after the company's "wait before answering" time, so a
     * customer who sends two quick messages gets one combined answer (a
     * newer message makes the older job step aside).
     */
    public static function dispatchAfterWait(int $conversationId, int $sourceMessageId): void
    {
        $company = Conversation::withoutGlobalScopes()->with('company')->find($conversationId)?->company;
        $seconds = $company
            ? (int) app(AiSettingsService::class)->all($company, AiSettingsService::TOOL_MESSAGING)['reply_delay_seconds']
            : (int) AiSettingsService::DEFAULTS['reply_delay_seconds'];

        static::dispatch($conversationId, $sourceMessageId)->delay(now()->addSeconds(max(0, min(15, $seconds))));
    }

    public function uniqueId(): string
    {
        return $this->conversationId.':'.($this->sourceMessageId ?: 'legacy');
    }

    public function handle(CompanyContext $context, AiReplyService $service): void
    {
        $conversation = Conversation::withoutGlobalScopes()->with('company')->find($this->conversationId);

        if (! $conversation || ! $conversation->company) {
            return;
        }

        $context->set($conversation->company);

        try {
            $sourceQuery = $conversation->messages()->where('direction', 'incoming');
            $source = $this->sourceMessageId
                ? $sourceQuery->whereKey($this->sourceMessageId)->first()
                : $sourceQuery->latest('sent_at')->latest('id')->first();

            if (! $source) {
                return;
            }

            if ($this->alreadyProcessed($source)) {
                $this->markProcessed($source);

                return;
            }

            $lock = Cache::lock('crm:conversation:'.$conversation->getKey(), 80);

            if (! $lock->get()) {
                $this->release(3);

                return;
            }

            try {
                $source->refresh();

                if ($conversation->messages()->where('direction', 'incoming')->where('id', '>', $source->getKey())->exists()) {
                    $this->markProcessed($source);

                    return;
                }

                if ($this->alreadyProcessed($source)) {
                    $this->markProcessed($source);

                    return;
                }

                $service->maybeReply($conversation, $source);
                $this->markProcessed($source);
            } finally {
                $lock->release();
            }
        } finally {
            $context->clear();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $conversation = Conversation::withoutGlobalScopes()->with('company')->find($this->conversationId);
        if (! $conversation?->company) {
            return;
        }
        $context = app(CompanyContext::class);
        $context->set($conversation->company);
        try {
            app(AiReplyService::class)->escalate($conversation, 'AI processing timed out or failed; please review the conversation.');
        } finally {
            $context->clear();
        }
    }

    protected function alreadyProcessed(ConversationMessage $source): bool
    {
        if (data_get($source->raw_payload, '_local.ai_processed_at')) {
            return true;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $source->conversation_id)
            ->where('generated_by', 'ai')
            ->where('ai_meta->source_message_id', $source->getKey())
            ->exists();
    }

    protected function markProcessed(ConversationMessage $source): void
    {
        $source->refresh();
        $raw = is_array($source->raw_payload) ? $source->raw_payload : [];
        data_set($raw, '_local.ai_processed_at', now()->toIso8601String());
        $source->forceFill(['raw_payload' => $raw])->saveQuietly();
    }
}
