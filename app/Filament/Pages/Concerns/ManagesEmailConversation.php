<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Forms\Components\PhoneInput;
use App\Filament\Pages\Inbox;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailThread;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Services\Email\Inbox\EmailThreadActions;
use App\Support\MoneyFormatter;
use App\Support\PhoneLinks;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Who a conversation is with and who handles it (owner request 2026-11-02), in the Email page: the staff member it is
 * assigned to, its status (Open / Pending / Closed), and the customer panel (the customer matched by the sender's email,
 * their orders, the WhatsApp chat and the buttons Create order, Create lead and Link to an order). Writing to the
 * conversation (assign, status, lead, order link) needs `crm.manage`; the panel itself is read with `crm.view`.
 */
trait ManagesEmailConversation
{
    public string $orderNumberInput = '';

    public bool $showOrderLink = false;

    public bool $showLeadForm = false;

    public string $leadName = '';

    public string $leadPhone = '';

    // --- Assignee and status ----------------------------------------------------------------------------

    /** @return Collection<int, User> Staff of this company who can be given a conversation. */
    #[Computed]
    public function staff(): Collection
    {
        $actions = app(EmailThreadActions::class);

        return User::query()->where('is_active', true)->orderBy('name')->limit(300)->get()
            ->filter(fn (User $user): bool => $actions->isStaff($user))->values();
    }

    public function assignThread(?int $userId): void
    {
        $this->authorizeManage();
        $thread = $this->thread;

        if ($thread === null) {
            return;
        }

        $user = $userId === null ? null : $this->staff->firstWhere('id', $userId);

        if ($userId !== null && $user === null) {
            return;
        }

        app(EmailThreadActions::class)->assign($thread, $user);
        $this->forgetData();
    }

    public function setThreadStatus(string $status): void
    {
        $this->authorizeManage();

        if ($this->thread !== null && in_array($status, EmailThread::STATUSES, true)) {
            app(EmailThreadActions::class)->setStatus($this->thread, $status);
            $this->forgetData();
        }
    }

    // --- The customer panel -----------------------------------------------------------------------------

    /**
     * What the panel shows about the other party of the open conversation.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function contact(): array
    {
        $thread = $this->thread;

        if ($thread === null) {
            return [];
        }

        $customer = $thread->customer_id ? Customer::query()->find($thread->customer_id) : null;
        $lead = $thread->lead_id ? Lead::query()->find($thread->lead_id) : null;
        $order = $thread->order_id ? Order::query()->find($thread->order_id) : null;
        $phone = $customer?->phone ?: $lead?->phone;
        $orders = $customer === null ? collect() : Order::query()->where('customer_id', $customer->getKey())->orderByDesc('order_date')->orderByDesc('id')->limit(3)->get();
        $accounted = $customer === null ? null : Order::query()->where('customer_id', $customer->getKey())->whereIn('status', Order::ACCOUNTED_STATUSES);
        $chat = $customer === null ? null : Conversation::query()->where('customer_id', $customer->getKey())->orderByDesc('last_message_at')->first();
        $currency = (string) ($this->company()?->currency ?: 'BDT');

        return [
            'customer' => $customer,
            'lead' => $lead,
            'order' => $order,
            'phone' => $phone,
            'telUrl' => PhoneLinks::telUrl($phone),
            'whatsappUrl' => PhoneLinks::whatsappUrl($phone),
            'chatUrl' => $chat === null ? null : Inbox::getUrl(['conversation' => $chat->getKey(), 'status' => 'all']),
            'orders' => $orders,
            'orderCount' => $customer === null ? 0 : Order::query()->where('customer_id', $customer->getKey())->count(),
            'spent' => $accounted === null ? null : MoneyFormatter::currency((float) $accounted->sum('total_amount'), $currency),
            'due' => $customer === null ? null : MoneyFormatter::currency((float) $customer->current_balance, $currency),
            'createOrderUrl' => OrderResource::getUrl('create', $customer === null ? [] : ['customer' => $customer->getKey()]),
            'customerUrl' => $customer === null ? null : CustomerResource::getUrl('view', ['record' => $customer]),
            'leadUrl' => $lead === null ? null : LeadResource::getUrl('view', ['record' => $lead]),
            'orderUrl' => $order === null ? null : OrderResource::getUrl('view', ['record' => $order]),
        ];
    }

    public function money(mixed $amount): string
    {
        return MoneyFormatter::currency((float) $amount, (string) ($this->company()?->currency ?: 'BDT'));
    }

    /** Opens the small form for a new lead: the name and phone are filled from the customer when there is one. */
    public function startLead(): void
    {
        $this->authorizeManage();
        $thread = $this->thread;

        if ($thread === null) {
            return;
        }

        $customer = $thread->customer_id ? Customer::query()->find($thread->customer_id) : null;
        $this->leadName = (string) ($customer?->name ?: ($thread->contact_name ?: $thread->contact_email));
        $this->leadPhone = (string) ($customer?->phone ?? '');
        $this->showLeadForm = true;
        $this->resetErrorBag();
    }

    public function createLeadForThread(): void
    {
        $this->authorizeManage();

        if ($this->thread === null) {
            return;
        }

        $this->leadName = trim($this->leadName);
        $this->leadPhone = trim($this->leadPhone);

        $this->validate([
            'leadName' => ['required', 'string', 'max:190'],
            'leadPhone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-]{5,}$/'],
        ], [
            'leadPhone.regex' => __('Enter a valid phone number.'),
        ], ['leadName' => __('Name'), 'leadPhone' => __('Phone')]);

        app(EmailThreadActions::class)->createLead($this->thread, $this->user(), (string) PhoneInput::formatPhoneNumber($this->leadPhone), $this->leadName);

        $this->showLeadForm = false;
        $this->leadName = $this->leadPhone = '';
        $this->forgetData();

        Notification::make()->title(__('Lead created'))->success()->send();
    }

    /** Ties the conversation to an order by its invoice number. */
    public function linkOrder(): void
    {
        $this->authorizeManage();
        $thread = $this->thread;
        $number = trim($this->orderNumberInput);

        if ($thread === null || $number === '') {
            return;
        }

        $order = Order::query()->where('order_number', $number)->first();

        if ($order === null) {
            Notification::make()->title(__('No order has the number :number.', ['number' => $number]))->danger()->send();

            return;
        }

        app(EmailThreadActions::class)->linkOrder($thread, $order);
        $this->orderNumberInput = '';
        $this->showOrderLink = false;
        $this->forgetData();
    }

    public function unlinkOrder(): void
    {
        $this->authorizeManage();

        if ($this->thread !== null) {
            app(EmailThreadActions::class)->linkOrder($this->thread, null);
            $this->forgetData();
        }
    }
}
