<?php

namespace App\Filament\Pages\Concerns;

use App\Services\Email\Inbox\EmailAddressSuggester;
use App\Support\EmailAddressList;
use Livewire\Attributes\Computed;

/**
 * Address suggestions in the To / Cc / Bcc boxes of the compose window (Phase 2, docs/email-inbox-plan.md): while the
 * person types, the people the mailbox has written with and the company's customers that fit the last word are listed;
 * a click puts the address in place of the word. Used by App\Filament\Pages\EmailInbox, which provides $mailbox
 * and the composeTo / composeCc / composeBcc boxes.
 */
trait SuggestsEmailAddresses
{
    /** The box the person is typing in: to, cc or bcc; null when none. */
    public ?string $suggestField = null;

    public function updatedComposeTo(): void
    {
        $this->suggestField = 'to';
    }

    public function updatedComposeCc(): void
    {
        $this->suggestField = 'cc';
    }

    public function updatedComposeBcc(): void
    {
        $this->suggestField = 'bcc';
    }

    /** @return array<int, array{name: string, address: string, customer: bool}> */
    #[Computed]
    public function addressSuggestions(): array
    {
        if ($this->mailbox === null || $this->composeId === null || ! in_array($this->suggestField, ['to', 'cc', 'bcc'], true)) {
            return [];
        }

        $word = $this->lastWord((string) $this->{'compose'.ucfirst($this->suggestField)});
        $suggestions = app(EmailAddressSuggester::class)->suggest($this->mailbox, $word);

        // Not the people already in the box.
        $present = array_map('mb_strtolower', EmailAddressList::addresses(EmailAddressList::parse((string) $this->{'compose'.ucfirst($this->suggestField)})['valid']));

        return array_values(array_filter($suggestions, fn (array $one): bool => ! in_array($one['address'], $present, true)));
    }

    /** Puts the chosen suggestion in place of the word being typed. */
    public function pickSuggestion(int $index): void
    {
        $this->authorizeManage();
        $one = $this->addressSuggestions[$index] ?? null;

        if ($one === null) {
            return;
        }

        $property = 'compose'.ucfirst((string) $this->suggestField);
        $text = (string) $this->{$property};
        $cut = max((int) strrpos($text, ','), (int) strrpos($text, ';'));
        $head = $cut > 0 ? rtrim(substr($text, 0, $cut + 1)).' ' : '';
        $this->{$property} = $head.EmailAddressList::label(['name' => $one['name'], 'address' => $one['address']]).', ';
        $this->suggestField = null;
        unset($this->addressSuggestions);
    }

    /** The part after the last comma or semicolon: the address being typed. */
    protected function lastWord(string $text): string
    {
        $parts = preg_split('/[,;]/', $text) ?: [''];

        return trim((string) end($parts));
    }
}
