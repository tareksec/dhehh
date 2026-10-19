<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\ConversationModeEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of a chat's AI/team mode (16_AI_INBOX_CONTROL_PLANE_PLAN.md
 * §4.1). A chat is either with the AI, or with the team — until a set time
 * (`human_handled_until`), after which it goes back to the AI on its own, or
 * with no time limit until someone presses "Back to AI". `ai_enabled` is kept
 * in step (false while the team has the chat) so older checks keep working.
 * Every switch is written to conversation_mode_events.
 */
class ConversationModeService
{
    public function __construct(
        protected AiSettingsService $settings,
        protected AttentionQueueService $attention,
    ) {}

    /**
     * Give the chat to the team. $minutes null = the company's takeover
     * time; $permanent = no time limit.
     */
    public function toHuman(
        Conversation $conversation,
        string $source,
        ?User $user = null,
        ?int $minutes = null,
        bool $permanent = false,
        ?string $reason = null,
        ?string $note = null,
    ): Conversation {
        $revertAt = $permanent ? null : now()->addMinutes($minutes ?? $this->takeoverMinutes($conversation));

        return $this->switch($conversation, Conversation::MODE_HUMAN, $source, $user, $revertAt, $reason, $note);
    }

    public function toAi(Conversation $conversation, string $source, ?User $user = null, ?string $note = null): Conversation
    {
        return $this->switch($conversation, Conversation::MODE_AI, $source, $user, null, null, $note);
    }

    /**
     * A staff member answered. Keeps the chat with the team: a chat with a
     * time limit restarts its timer, a chat held with no limit stays so.
     */
    public function staffAnswered(Conversation $conversation, string $source, ?User $user = null, ?Carbon $answeredAt = null): Conversation
    {
        $this->reload($conversation);
        $this->attention->answered($conversation, $user, $answeredAt);

        if ($conversation->isHumanMode() && $conversation->human_handled_until === null) {
            return $conversation;
        }

        $revertAt = ($answeredAt ?? now())->copy()->addMinutes($this->takeoverMinutes($conversation));

        if ($conversation->isHumanMode()) {
            if ($revertAt->greaterThan($conversation->human_handled_until)) {
                $conversation->forceFill(['human_handled_until' => $revertAt])->saveQuietly();
            }

            return $conversation;
        }

        if ($revertAt->isPast()) {
            return $conversation; // an old reply synced late does not take the chat
        }

        return $this->switch($conversation, Conversation::MODE_HUMAN, $source, $user, $revertAt, null, null);
    }

    /** Return every chat whose team time is over to the AI. Runs inside one company's context. */
    public function revertDue(int $companyId): int
    {
        $count = 0;

        Conversation::query()
            ->where('company_id', $companyId)
            ->where('automation_mode', Conversation::MODE_HUMAN)
            ->whereNotNull('human_handled_until')
            ->where('human_handled_until', '<=', now())
            ->chunkById(100, function ($conversations) use (&$count): void {
                foreach ($conversations as $conversation) {
                    $fresh = $conversation->fresh();
                    if ($fresh?->isHumanMode() && $fresh->human_handled_until?->lessThanOrEqualTo(now())) {
                        $this->toAi($fresh, 'auto_revert');
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function takeoverMinutes(Conversation $conversation): int
    {
        $company = $conversation->company;

        return $company
            ? (int) $this->settings->all($company, AiSettingsService::TOOL_MESSAGING)['takeover_minutes']
            : (int) AiSettingsService::DEFAULTS['takeover_minutes'];
    }

    protected function switch(
        Conversation $conversation,
        string $mode,
        string $source,
        ?User $user,
        ?Carbon $revertAt,
        ?string $reason,
        ?string $note,
    ): Conversation {
        $reason = $reason !== null && array_key_exists($reason, Conversation::HANDOVER_REASONS) ? $reason : ($reason === null ? null : 'other');

        DB::transaction(function () use ($conversation, $mode, $source, $user, $revertAt, $reason, $note): void {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->automation_mode ?: Conversation::MODE_AI;
            $human = $mode === Conversation::MODE_HUMAN;

            $updates = [
                'automation_mode' => $mode,
                'ai_enabled' => ! $human,
                'human_handled_until' => $human ? $revertAt : null,
                'handover_reason' => $human ? ($reason ?? ($from === Conversation::MODE_HUMAN ? $locked->handover_reason : null)) : null,
                'mode_changed_at' => now(),
                'mode_changed_by' => $user?->getKey(),
            ];
            if ($human && $source === 'ai') {
                $updates['status'] = 'pending';
            } elseif (! $human && $locked->status === 'pending') {
                $updates['status'] = 'open';
            }
            $locked->forceFill($updates)->save();

            ConversationModeEvent::query()->create([
                'company_id' => $locked->company_id,
                'conversation_id' => $locked->getKey(),
                'from_mode' => $from,
                'to_mode' => $mode,
                'source' => $source,
                'reason' => $reason,
                'note' => $note !== null ? Str::limit($note, 250, '') : null,
                'revert_at' => $human ? $revertAt : null,
                'user_id' => $user?->getKey(),
            ]);
        });

        // The Needs attention queue: the AI handing over opens an item; the
        // chat going back to the AI closes it.
        if ($mode === Conversation::MODE_HUMAN && $source === 'ai') {
            $this->attention->opened($conversation, 'handover', $reason, $note);
        } elseif ($mode === Conversation::MODE_AI) {
            $this->attention->returnedToAi($conversation, $source, $user);
        }

        return $this->reload($conversation);
    }

    /**
     * Fresh column values without refresh(): refresh() also reloads every
     * loaded relation, and the Inbox sets non-relation values (like
     * hasOlderMessages) through setRelation().
     */
    protected function reload(Conversation $conversation): Conversation
    {
        $fresh = Conversation::query()->find($conversation->getKey());
        if ($fresh) {
            $conversation->setRawAttributes($fresh->getAttributes(), true);
        }

        return $conversation;
    }
}
