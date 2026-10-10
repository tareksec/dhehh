<?php

namespace App\Services\Crm;

use App\Models\AttentionItem;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\ConversationMessage;
use App\Models\ConversationModeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers for the AI inbox report (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.4),
 * read from what phases 1–2 record: conversation messages, AI/team switches
 * and Needs attention items. Runs inside the current company's scope; nothing
 * is stored. Response times count from the AI handover, like AiPraktor.
 */
class AiInboxReportService
{
    public const PERIODS = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    public function from(string $period): Carbon
    {
        return $period === 'today' ? now()->startOfDay() : now()->subDays(max(1, (int) $period));
    }

    public function summary(string $period): array
    {
        $from = $this->from($period);
        $to = now();

        $chatIds = $this->messages($from, $to)->where('direction', 'incoming')->distinct()->pluck('conversation_id');
        $teamChatIds = ConversationModeEvent::query()->whereBetween('created_at', [$from, $to])
            ->where('to_mode', Conversation::MODE_HUMAN)->distinct()->pluck('conversation_id');
        $chats = $chatIds->count();
        $aiOnly = $chatIds->diff($teamChatIds)->count();

        $events = ConversationModeEvent::query()->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, to_mode, count(*) as total')->groupBy('source', 'to_mode')->get();
        $count = fn (callable $filter): int => (int) $events->filter($filter)->sum('total');

        $items = AttentionItem::query()->whereBetween('opened_at', [$from, $to])->get();
        $responses = $items->filter(fn (AttentionItem $item): bool => $item->first_response_at !== null)
            ->map(fn (AttentionItem $item): int => $item->waitMinutes())->sort()->values();
        $targeted = $items->filter(fn (AttentionItem $item): bool => $item->due_at !== null);
        $late = $targeted->filter(fn (AttentionItem $item): bool => $this->wasLate($item));

        $waiting = AttentionItem::query()->open()->whereNull('first_response_at')->get();

        return [
            'chats' => $chats,
            'ai_only' => $aiOnly,
            'ai_only_percent' => $chats > 0 ? (int) round($aiOnly * 100 / $chats) : null,
            'ai_replies' => $this->messages($from, $to)->where('direction', 'outgoing')->where('generated_by', 'ai')->count(),
            'staff_replies' => $this->messages($from, $to)->where('direction', 'outgoing')->where('generated_by', 'human')->count(),
            'handoffs' => $count(fn ($row): bool => $row->source === 'ai'),
            'takeovers' => $count(fn ($row): bool => $row->source !== 'ai' && $row->to_mode === Conversation::MODE_HUMAN),
            'auto_returns' => $count(fn ($row): bool => $row->source === 'auto_revert'),
            'answered' => $responses->count(),
            'never_answered' => $items->where('outcome', 'never_answered')->count(),
            'avg_response_minutes' => $responses->isNotEmpty() ? (int) round($responses->avg()) : null,
            'median_response_minutes' => $responses->isNotEmpty() ? $this->median($responses) : null,
            'late' => $late->count(),
            'late_percent' => $targeted->isNotEmpty() ? (int) round($late->count() * 100 / $targeted->count()) : null,
            'waiting_now' => $waiting->count(),
            'longest_wait_minutes' => $waiting->isNotEmpty() ? $waiting->max(fn (AttentionItem $item): int => $item->waitMinutes()) : null,
            'reasons' => $this->reasons($from, $to),
            'channels' => $this->channels($from, $to, $items),
        ];
    }

    /** No first reply before the target time (or still none and the target has passed). */
    public function wasLate(AttentionItem $item): bool
    {
        if ($item->due_at === null) {
            return false;
        }
        $end = $item->first_response_at ?? $item->resolved_at ?? now();

        return $end->greaterThan($item->due_at);
    }

    protected function messages(Carbon $from, Carbon $to)
    {
        return ConversationMessage::query()
            ->whereIn('conversation_id', Conversation::query()->select('id'))
            ->whereBetween('sent_at', [$from, $to]);
    }

    protected function reasons(Carbon $from, Carbon $to): Collection
    {
        return ConversationModeEvent::query()->whereBetween('created_at', [$from, $to])->where('source', 'ai')
            ->selectRaw('reason, count(*) as total')->groupBy('reason')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['reason' => $row->reason ?: 'other', 'total' => (int) $row->total]);
    }

    protected function channels(Carbon $from, Carbon $to, Collection $items): Collection
    {
        $chatsByChannel = $this->messages($from, $to)->where('direction', 'incoming')
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->selectRaw('conversations.channel_id, count(distinct conversation_messages.conversation_id) as total')
            ->groupBy('conversations.channel_id')->pluck('total', 'conversations.channel_id');

        $items->loadMissing('conversation');
        $itemsByChannel = $items->groupBy(fn (AttentionItem $item) => $item->conversation?->channel_id);

        return ConversationChannel::query()->whereIn('id', $chatsByChannel->keys()->merge($itemsByChannel->keys())->filter()->unique())
            ->get()
            ->map(function (ConversationChannel $channel) use ($chatsByChannel, $itemsByChannel): array {
                $channelItems = $itemsByChannel->get($channel->id, collect());
                $responses = $channelItems->filter(fn (AttentionItem $item): bool => $item->first_response_at !== null)
                    ->map(fn (AttentionItem $item): int => $item->waitMinutes());

                return [
                    'name' => $channel->display_name ?: $channel->provider,
                    'provider' => $channel->provider,
                    'chats' => (int) ($chatsByChannel[$channel->id] ?? 0),
                    'handoffs' => $channelItems->count(),
                    'avg_response_minutes' => $responses->isNotEmpty() ? (int) round($responses->avg()) : null,
                    'late' => $channelItems->filter(fn (AttentionItem $item): bool => $this->wasLate($item))->count(),
                ];
            })
            ->sortByDesc('chats')->values();
    }

    protected function median(Collection $sorted): int
    {
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return $count % 2 ? $sorted[$middle] : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }
}
