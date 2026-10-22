<?php

namespace App\Services\Crm;

use App\Models\ChaseMark;
use App\Models\Lead;
use App\Models\Order;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * People who showed they wanted to buy and have not (owner answer O9,
 * 2026-10-19): carts left behind, checkouts that stopped at the payment, and
 * leads with no order. Worked out from the data each time; the only thing
 * stored is who has already been contacted. Anyone who ordered since is
 * left out. One row per phone number, whatever the sources.
 *
 * Offer taps are not a source: nothing records who tapped an offer, so there
 * is nobody to contact.
 */
class ToChaseService
{
    public const SOURCES = [
        'cart' => 'Cart left behind',
        'checkout' => 'Checkout not paid',
        'lead' => 'Lead with no order',
    ];

    public const DAY_CHOICES = [7, 14, 30];

    public function __construct(protected ContactSuppressionService $phones) {}

    /**
     * @param  list<string>|null  $sources  keys of self::SOURCES; null = all
     * @return Collection<int, array{phone:string,name:?string,sources:list<string>,last_at:Carbon,detail:?string,contacted_at:?Carbon}>
     */
    public function rows(int $days = 7, ?array $sources = null, bool $includeContacted = false, int $limit = 300): Collection
    {
        $since = now()->subDays($days);
        $sources = array_values(array_intersect($sources ?? array_keys(self::SOURCES), array_keys(self::SOURCES)));

        $found = collect();
        if (in_array('cart', $sources, true)) {
            $found = $found->concat($this->carts($since));
        }
        if (in_array('checkout', $sources, true)) {
            $found = $found->concat($this->checkouts($since));
        }
        if (in_array('lead', $sources, true)) {
            $found = $found->concat($this->leads($since));
        }

        $ordered = $this->lastOrderTimes($since);
        $marks = ChaseMark::query()->pluck('contacted_at', 'phone');

        return $found
            ->groupBy('phone')
            ->map(function (Collection $group, string $phone) use ($marks): array {
                $latest = $group->sortByDesc('at')->first();

                return [
                    'phone' => $phone,
                    'name' => $group->pluck('name')->filter()->first(),
                    'sources' => $group->pluck('source')->unique()->values()->all(),
                    'last_at' => $latest['at'],
                    'detail' => $latest['detail'],
                    'contacted_at' => isset($marks[$phone]) ? Carbon::parse($marks[$phone]) : null,
                ];
            })
            // Ordered after the last sign of interest: no longer to chase.
            ->reject(fn (array $row): bool => isset($ordered[$row['phone']]) && $ordered[$row['phone']]->greaterThanOrEqualTo($row['last_at']))
            // Contacted, and nothing new since: hidden unless asked for.
            ->reject(fn (array $row): bool => ! $includeContacted && $row['contacted_at'] && $row['contacted_at']->greaterThanOrEqualTo($row['last_at']))
            ->sortByDesc('last_at')
            ->take($limit)
            ->values();
    }

    public function markContacted(string $phone, ?User $user = null): void
    {
        $phone = $this->phones->normalize($phone);
        if ($phone === '') {
            return;
        }

        ChaseMark::query()->updateOrCreate(['phone' => $phone], ['contacted_at' => now(), 'contacted_by' => $user?->getKey()]);
    }

    public function unmarkContacted(string $phone): void
    {
        ChaseMark::query()->where('phone', $this->phones->normalize($phone))->delete();
    }

    /** @return Collection<int, array{phone:string,name:?string,source:string,at:Carbon,detail:?string}> */
    protected function carts(Carbon $since): Collection
    {
        return StorefrontCartRecord::query()
            ->whereIn('status', [StorefrontCartRecord::STATUS_ACTIVE, StorefrontCartRecord::STATUS_CHECKOUT_STARTED, StorefrontCartRecord::STATUS_REMINDED])
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->where('last_activity_at', '>=', $since)
            ->get()
            ->map(fn (StorefrontCartRecord $cart): array => [
                'phone' => $this->phones->normalize((string) $cart->phone),
                'name' => $cart->customer_name,
                'source' => 'cart',
                'at' => $cart->last_activity_at,
                'detail' => trim($cart->itemCount().' '.__('items').' · '.number_format((float) $cart->subtotal, 0)),
            ])
            ->filter(fn (array $row): bool => $row['phone'] !== '');
    }

    /** A checkout that went to the payment page and was never paid. */
    protected function checkouts(Carbon $since): Collection
    {
        return StorefrontPayment::query()
            ->where('status', StorefrontPayment::STATUS_PENDING)
            ->whereNull('order_id')->whereNotNull('checkout_data')
            ->where('created_at', '>=', $since)
            ->get()
            ->map(function (StorefrontPayment $payment): array {
                $data = (array) $payment->checkout_data;

                return [
                    'phone' => $this->phones->normalize((string) ($data['phone'] ?? '')),
                    'name' => $data['name'] ?? null,
                    'source' => 'checkout',
                    'at' => $payment->created_at,
                    'detail' => __('Advance of :amount not paid', ['amount' => number_format((float) $payment->amount, 0)]),
                ];
            })
            ->filter(fn (array $row): bool => $row['phone'] !== '');
    }

    protected function leads(Carbon $since): Collection
    {
        return Lead::query()
            ->whereIn('status', ['new', 'contacted', 'quoted'])
            ->whereNull('converted_order_id')->whereNull('opted_out_at')
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->where('created_at', '>=', $since)
            ->get()
            ->map(fn (Lead $lead): array => [
                'phone' => $this->phones->normalize((string) $lead->phone),
                'name' => $lead->name,
                'source' => 'lead',
                'at' => $lead->created_at,
                'detail' => $lead->interest ? Str::limit($lead->interest, 80) : null,
            ])
            ->filter(fn (array $row): bool => $row['phone'] !== '');
    }

    /**
     * When each phone last ordered, from the day the window starts. An order
     * that was cancelled, returned or refunded does not count.
     *
     * @return array<string, Carbon>
     */
    protected function lastOrderTimes(Carbon $since): array
    {
        $times = [];
        Order::query()
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED])
            ->with('customer:id,phone')
            ->get(['id', 'customer_id', 'created_at'])
            ->each(function (Order $order) use (&$times): void {
                $phone = $this->phones->normalize((string) $order->customer?->phone);
                if ($phone !== '' && (! isset($times[$phone]) || $order->created_at->greaterThan($times[$phone]))) {
                    $times[$phone] = $order->created_at;
                }
            });

        return $times;
    }
}
