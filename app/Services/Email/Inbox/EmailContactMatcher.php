<?php

namespace App\Services\Email\Inbox;

use App\Models\Customer;
use App\Models\Lead;

/**
 * Finds the customer and the lead a sender's email address belongs to, in the company's own records (by the
 * address, case-insensitive). Nothing is created: an unknown sender is just an address. When several records
 * share an address the newest one is used.
 */
class EmailContactMatcher
{
    /** @return array{customer_id: ?int, lead_id: ?int} */
    public function match(int $companyId, string $email): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return ['customer_id' => null, 'lead_id' => null];
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereRaw('lower(email) = ?', [$email])
            ->orderByDesc('id')
            ->first(['id']);

        $lead = Lead::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereRaw('lower(email) = ?', [$email])
            ->orderByDesc('id')
            ->first(['id']);

        return ['customer_id' => $customer?->getKey(), 'lead_id' => $lead?->getKey()];
    }
}
