<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailRule;
use App\Models\EmailThread;
use App\Models\User;
use Throwable;

/**
 * Runs the mailbox's rules (Settings → Email inbox → Rules) on a message that has just arrived, in their order. Every
 * rule that fits does its part: a label is added, an unassigned conversation is given to the person (only a member of
 * staff who may use CRM), a conversation still in the Inbox is archived. Spam and the Trash are never touched. A rule
 * can never lose mail: a failure is reported and the message stays as it came.
 */
class EmailRuleEngine
{
    public function __construct(protected EmailThreadActions $actions) {}

    /** Returns how many rules fitted. */
    public function apply(EmailMailbox $mailbox, EmailThread $thread, string $fromAddress, ?string $fromName, string $subject): int
    {
        try {
            $fitted = 0;

            foreach (EmailRule::query()->where('mailbox_id', $mailbox->getKey())->where('is_active', true)->orderBy('position')->orderBy('id')->get() as $rule) {
                if (! $rule->matches($fromAddress, $fromName, $subject)) {
                    continue;
                }

                $fitted++;
                $this->perform($rule, $thread);
            }

            return $fitted;
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    protected function perform(EmailRule $rule, EmailThread $thread): void
    {
        // The label is looked up through the company scope, so a label of another company is never put on this one's mail.
        $label = $rule->label_id === null ? null : EmailLabel::query()->find($rule->label_id);

        if ($label !== null) {
            $thread->labels()->syncWithoutDetaching([$label->getKey()]);
        }

        if ($rule->assign_to !== null && $thread->assigned_to === null) {
            $user = User::query()->find($rule->assign_to);

            if ($user !== null && $this->actions->isStaff($user)) {
                $thread->forceFill(['assigned_to' => $user->getKey()])->save();
            }
        }

        if ($rule->archive && $thread->folder === EmailThread::FOLDER_INBOX) {
            $thread->forceFill(['folder' => EmailThread::FOLDER_ARCHIVED, 'snoozed_until' => null, 'snoozed_by' => null])->save();
        }
    }
}
