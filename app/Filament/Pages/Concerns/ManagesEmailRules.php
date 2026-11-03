<?php

namespace App\Filament\Pages\Concerns;

use App\Models\EmailLabel;
use App\Models\EmailRule;
use App\Models\User;
use App\Services\Email\Inbox\EmailThreadActions;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The Rules card of Settings → Email inbox (Phase 2, docs/email-inbox-plan.md): list, add, edit, switch on / off and
 * delete the rules of the open mailbox. A rule says: when the sender and / or the subject contains some text, put a
 * label on the conversation, give it to a person, archive it (EmailRuleEngine does it as mail arrives). Used by
 * App\Filament\Pages\EmailInboxSettings, which provides company(), mailbox() and $companyId. Only this company's
 * labels and staff can be chosen, whatever the browser sends.
 */
trait ManagesEmailRules
{
    public ?int $editingRuleId = null;

    public string $ruleName = '';

    public string $ruleFrom = '';

    public string $ruleSubject = '';

    public ?int $ruleLabelId = null;

    public ?int $ruleAssignTo = null;

    public bool $ruleArchive = false;

    /** @return Collection<int, EmailRule> */
    #[Computed]
    public function mailboxRules(): Collection
    {
        $this->company();
        $mailbox = $this->mailbox();

        return $mailbox === null ? new Collection : EmailRule::query()
            ->where('company_id', $this->companyId)->where('mailbox_id', $mailbox->getKey())
            ->with(['label:id,name', 'assignee:id,name'])->orderBy('position')->orderBy('id')->get();
    }

    /** @return Collection<int, User> Staff of this company a rule may give a conversation to. */
    #[Computed]
    public function ruleStaff(): Collection
    {
        $this->company();
        $actions = app(EmailThreadActions::class);

        return User::query()->where('is_active', true)->orderBy('name')->limit(300)->get()
            ->filter(fn (User $user): bool => $actions->isStaff($user))->values();
    }

    public function saveRule(): void
    {
        $this->company();
        $mailbox = $this->mailbox();

        if ($mailbox === null) {
            return;
        }

        $this->ruleName = trim((string) preg_replace('/\s+/u', ' ', $this->ruleName));
        $this->ruleFrom = trim($this->ruleFrom);
        $this->ruleSubject = trim($this->ruleSubject);

        $this->validate([
            'ruleName' => ['required', 'string', 'max:80'],
            'ruleFrom' => ['nullable', 'string', 'max:190', 'required_without:ruleSubject'],
            'ruleSubject' => ['nullable', 'string', 'max:190', 'required_without:ruleFrom'],
            'ruleLabelId' => ['nullable', 'integer'],
            'ruleAssignTo' => ['nullable', 'integer'],
            'ruleArchive' => ['boolean'],
        ], [
            'ruleFrom.required_without' => __('Give the sender or the subject to look for.'),
            'ruleSubject.required_without' => __('Give the sender or the subject to look for.'),
        ], ['ruleName' => __('Rule name')]);

        $label = $this->ruleLabelId === null ? null : EmailLabel::query()->where('company_id', $this->companyId)->find($this->ruleLabelId);
        $assignee = $this->ruleAssignTo === null ? null : $this->ruleStaff->firstWhere('id', $this->ruleAssignTo);

        if ($label === null && $assignee === null && ! $this->ruleArchive) {
            $this->addError('ruleLabelId', __('Choose what the rule does: a label, a person, or archive.'));

            return;
        }

        $values = [
            'name' => $this->ruleName,
            'from_contains' => $this->ruleFrom === '' ? null : $this->ruleFrom,
            'subject_contains' => $this->ruleSubject === '' ? null : $this->ruleSubject,
            'label_id' => $label?->getKey(),
            'assign_to' => $assignee?->getKey(),
            'archive' => $this->ruleArchive,
        ];

        if ($this->editingRuleId !== null) {
            EmailRule::query()->where('company_id', $this->companyId)->where('mailbox_id', $mailbox->getKey())->whereKey($this->editingRuleId)->update($values);
        } else {
            $position = (int) EmailRule::query()->where('mailbox_id', $mailbox->getKey())->max('position') + 1;
            EmailRule::query()->create($values + ['company_id' => $this->companyId, 'mailbox_id' => $mailbox->getKey(), 'position' => $position, 'is_active' => true]);
        }

        $this->cancelRule();
        unset($this->mailboxRules);
    }

    public function editRule(int $ruleId): void
    {
        $this->company();
        $rule = $this->mailboxRules->firstWhere('id', $ruleId);

        if ($rule !== null) {
            $this->editingRuleId = $rule->getKey();
            $this->ruleName = $rule->name;
            $this->ruleFrom = (string) $rule->from_contains;
            $this->ruleSubject = (string) $rule->subject_contains;
            $this->ruleLabelId = $rule->label_id;
            $this->ruleAssignTo = $rule->assign_to;
            $this->ruleArchive = $rule->archive;
            $this->resetErrorBag();
        }
    }

    public function cancelRule(): void
    {
        $this->editingRuleId = null;
        $this->ruleName = $this->ruleFrom = $this->ruleSubject = '';
        $this->ruleLabelId = $this->ruleAssignTo = null;
        $this->ruleArchive = false;
        $this->resetErrorBag();
    }

    public function toggleRule(int $ruleId): void
    {
        $this->company();
        $rule = $this->mailboxRules->firstWhere('id', $ruleId);

        if ($rule !== null) {
            $rule->forceFill(['is_active' => ! $rule->is_active])->save();
            unset($this->mailboxRules);
        }
    }

    /** Moves a rule one place up (-1) or down (+1); rules run in this order. */
    public function moveRule(int $ruleId, int $direction): void
    {
        $this->company();
        $rules = $this->mailboxRules->values();
        $index = $rules->search(fn (EmailRule $rule): bool => $rule->getKey() === $ruleId);
        $other = $index === false ? null : $rules->get($index + ($direction < 0 ? -1 : 1));

        if ($index === false || $other === null) {
            return;
        }

        // Number them 0..n-1 in the shown order, then swap the two places.
        $order = $rules->pluck('id')->all();
        [$order[$index], $order[$index + ($direction < 0 ? -1 : 1)]] = [$order[$index + ($direction < 0 ? -1 : 1)], $order[$index]];

        foreach ($order as $position => $id) {
            EmailRule::query()->where('company_id', $this->companyId)->whereKey($id)->update(['position' => $position]);
        }

        unset($this->mailboxRules);
    }

    public function deleteRule(int $ruleId): void
    {
        $this->company();
        $rule = $this->mailboxRules->firstWhere('id', $ruleId);

        if ($rule !== null) {
            $rule->delete();
            unset($this->mailboxRules);

            if ($this->editingRuleId === $ruleId) {
                $this->cancelRule();
            }
        }
    }
}
