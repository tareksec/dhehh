<?php

namespace App\Services\Email\Inbox;

use App\Models\Customer;
use App\Models\EmailMailbox;
use App\Models\EmailThread;

/**
 * Suggests addresses while someone types in To / Cc / Bcc: the people this mailbox has written with before (the other
 * party of its conversations, newest first) and the company's customers that have an email address. Only the current
 * company's records are read. The mailbox's own address is never offered.
 */
class EmailAddressSuggester
{
    public const MIN_LENGTH = 2;

    public const LIMIT = 6;

    /** @return array<int, array{name: string, address: string, customer: bool}> */
    public function suggest(EmailMailbox $mailbox, string $term): array
    {
        $term = mb_strtolower(trim($term));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $own = mb_strtolower((string) $mailbox->address);
        $found = [];

        $add = function (?string $address, ?string $name, bool $customer) use (&$found, $own): void {
            $address = mb_strtolower(trim((string) $address));

            if ($address === '' || $address === $own || isset($found[$address])) {
                return;
            }

            $found[$address] = ['name' => trim((string) $name), 'address' => $address, 'customer' => $customer];
        };

        EmailThread::query()
            ->where('company_id', $mailbox->company_id)
            ->where('mailbox_id', $mailbox->getKey())
            ->whereNotNull('contact_email')
            ->where(fn ($query) => $query->whereRaw("lower(contact_email) like ? escape '\\'", [$like])->orWhereRaw("lower(coalesce(contact_name, '')) like ? escape '\\'", [$like]))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(self::LIMIT * 3)
            ->get(['contact_email', 'contact_name'])
            ->each(fn (EmailThread $thread) => $add($thread->contact_email, $thread->contact_name, false));

        if (count($found) < self::LIMIT) {
            Customer::query()
                ->where('company_id', $mailbox->company_id)
                ->whereNotNull('email')->where('email', '!=', '')
                ->where(fn ($query) => $query->whereRaw("lower(email) like ? escape '\\'", [$like])->orWhereRaw("lower(coalesce(name, '')) like ? escape '\\'", [$like]))
                ->orderByDesc('id')
                ->limit(self::LIMIT * 2)
                ->get(['email', 'name'])
                ->each(fn (Customer $customer) => $add($customer->email, $customer->name, true));
        }

        return array_slice(array_values($found), 0, self::LIMIT);
    }
}
