<?php

namespace App\Services\Crm;

use App\Models\AttentionItem;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The record of chats that need a person (16_AI_INBOX_CONTROL_PLANE_PLAN.md
 * §4.3). The push alert still goes out from AiReplyService::escalate(); this
 * keeps what happened next: when someone first answered, and how it ended.
 * At most one open item per chat.
 */
class AttentionQueueService
{
    public function __construct(protected AiSettingsService $settings) {}

    /** The AI handed the chat over: open an item, or note the new reason on the open one. */
    public function opened(Conversation $conversation, string $kind, ?string $reason = null, ?string $note = null): AttentionItem
    {
        $open = $this->openItem($conversation);
        if ($open) {
            $open->forceFill(array_filter([
                'reason' => $reason,
                'note' => $note !== null ? Str::limit($note, 250, '') : null,
            ], fn ($value): bool => $value !== null))->save();

            return $open;
        }

        $target = $this->responseTargetMinutes($conversation);

        return AttentionItem::query()->create([
            'company_id' => $conversation->company_id,
            'kind' => $kind,
            'conversation_id' => $conversation->getKey(),
            'reason' => $reason,
            'note' => $note !== null ? Str::limit($note, 250, '') : null,
            'opened_at' => now(),
            'due_at' => $target > 0 ? now()->addMinutes($target) : null,
        ]);
    }

    /** A person answered the chat: the first answer stops the response timer. */
    public function answered(Conversation $conversation, ?User $user = null, ?Carbon $answeredAt = null): void
    {
        $open = $this->openItem($conversation);
        if ($open && $open->first_response_at === null) {
            $at = $answeredAt ?? now();
            $open->forceFill([
                'first_response_at' => $at->lessThan($open->opened_at) ? $open->opened_at : $at,
                'first_response_by' => $user?->getKey(),
            ])->save();
        }
    }

    /** The chat went back to the AI: close its open item. */
    public function returnedToAi(Conversation $conversation, string $source, ?User $user = null): void
    {
        $open = $this->openItem($conversation);
        if (! $open) {
            return;
        }

        $outcome = match (true) {
            $open->first_response_at !== null => 'answered',
            $source === 'auto_revert' => 'never_answered',
            default => 'returned_to_ai',
        };
        $this->close($open, $outcome, $user);
    }

    /** "Done" pressed on the Needs attention page. */
    public function markDone(AttentionItem $item, ?User $user = null): void
    {
        if ($item->resolved_at === null) {
            $this->close($item, $item->first_response_at !== null ? 'answered' : 'dismissed', $user);
        }
    }

    public function responseTargetMinutes(Conversation $conversation): int
    {
        $company = $conversation->company;

        return $company
            ? (int) $this->settings->all($company, AiSettingsService::TOOL_MESSAGING)['response_target_minutes']
            : (int) AiSettingsService::DEFAULTS['response_target_minutes'];
    }

    protected function openItem(Conversation $conversation): ?AttentionItem
    {
        return AttentionItem::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();
    }

    protected function close(AttentionItem $item, string $outcome, ?User $user): void
    {
        $item->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $user?->getKey(),
            'outcome' => $outcome,
        ])->save();
    }
}
