<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CourierAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesQualificationService
{
    public const FIELDS = ['product_interest', 'variant_preference', 'quantity', 'budget', 'delivery_area', 'purchase_timeframe', 'language'];

    public function update(Conversation $conversation, array $input): array
    {
        $data = Validator::make($input, [
            'field' => ['required', 'in:'.implode(',', self::FIELDS)],
            'value' => ['required', 'string', 'max:250'],
            'evidence_message_id' => ['required', 'integer'],
            'evidence_quote' => ['required', 'string', 'min:1', 'max:500'],
        ])->validate();
        $evidence = $conversation->messages()->whereKey($data['evidence_message_id'])->where('direction', 'incoming')->first();
        if (! $evidence || ! str_contains((string) $evidence->body, $data['evidence_quote'])
            || ! str_contains(mb_strtolower($data['evidence_quote']), mb_strtolower($data['value']))) {
            return ['error' => 'Use an exact customer quote containing the value; do not infer confirmed preferences.'];
        }

        return DB::transaction(function () use ($conversation, $data): array {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            // A lead in Trash counts as no lead (the link itself is kept, so Restore reconnects it).
            if (! $locked->lead_id || ! Lead::query()->where('company_id', $locked->company_id)->whereKey($locked->lead_id)->exists()) {
                if (blank($locked->contact_phone)) {
                    return ['error' => 'A phone number is required before creating a lead.'];
                }
                $lead = Lead::query()->firstOrCreate(
                    ['company_id' => $locked->company_id, 'phone' => $locked->contact_phone],
                    ['name' => $locked->contact_name ?: $locked->contact_phone, 'source' => $locked->provider === 'whatsapp' ? 'whatsapp' : 'facebook', 'status' => 'new', 'assigned_to' => $locked->assigned_to],
                );
                $locked->update(['lead_id' => $lead->getKey()]);
                $conversation->setAttribute('lead_id', $lead->getKey());
            }
            $lead = Lead::query()->where('company_id', $locked->company_id)->whereKey($locked->lead_id)->lockForUpdate()->firstOrFail();
            $facts = $lead->qualification ?? [];
            $facts[$data['field']] = ['value' => $data['value'], 'evidence_message_id' => $data['evidence_message_id'], 'quote' => $data['evidence_quote']];
            $score = min(100, count($facts) * 15);
            $summary = collect($facts)->map(fn ($fact, $field) => $field.': '.($fact['value'] ?? ''))->implode("\n");
            $lead->update(['qualification' => $facts, 'qualification_score' => $score,
                'temperature' => $lead->temperature_locked ? $lead->temperature : ($score >= 75 ? 'hot' : ($score >= 30 ? 'warm' : 'cold')), 'qualification_summary' => $summary]);
            if ($lead->temperature === 'hot' && ! $lead->assigned_to) {
                app(CourierAlertService::class)->alert((int) $lead->company_id, 'crm-hot-lead', 'lead-'.$lead->getKey(), 'Unassigned hot lead', $lead->name.' needs a sales owner.');
            }

            return ['saved' => true, 'score' => $score, 'temperature' => $lead->temperature, 'missing' => array_values(array_diff(self::FIELDS, array_keys($facts)))];
        });
    }

    /**
     * $includePersonalization is off by default: recent_orders keeps its
     * original order_number/status-only shape and no returning-customer
     * signal is added, so a company that never opts in sees the exact same
     * qualification context as before. On, it also eager-loads each order's
     * product names and adds is_returning_customer/lead_source so the agent
     * can personalize tone without the model inventing purchase history.
     */
    public function context(Conversation $conversation, bool $includePersonalization = false): array
    {
        $lead = $conversation->lead()->where('company_id', $conversation->company_id)->first();
        $customerId = $conversation->customer_id ?: $lead?->converted_customer_id;
        $query = $customerId ? Order::query()->where('company_id', $conversation->company_id)->where('customer_id', $customerId)->latest('id')->limit(3) : null;

        if (! $includePersonalization) {
            $orders = $query ? $query->get(['order_number', 'status'])->toArray() : [];

            return ['qualification' => $lead?->qualification ?? [], 'summary' => $lead?->qualification_summary, 'recent_orders' => $orders,
                'missing' => array_values(array_diff(self::FIELDS, array_keys($lead?->qualification ?? [])))];
        }

        $orders = $query
            ? $query->with('items.product:id,name')->get(['id', 'order_number', 'status'])->map(fn (Order $order): array => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'items' => $order->items->map(fn (OrderItem $item): ?string => $item->product?->name)->filter()->values()->all(),
            ])->all()
            : [];

        return ['qualification' => $lead?->qualification ?? [], 'summary' => $lead?->qualification_summary, 'recent_orders' => $orders,
            'missing' => array_values(array_diff(self::FIELDS, array_keys($lead?->qualification ?? []))),
            'is_returning_customer' => $orders !== [], 'lead_source' => $lead?->source];
    }
}
