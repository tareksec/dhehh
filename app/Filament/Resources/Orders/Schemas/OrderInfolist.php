<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\CustomerRiskProfile;
use App\Models\CustomerRiskReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderActivityFeedService;
use App\Support\PhoneLinks;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Invoice')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('order_number')->label('Invoice Number'),
                        TextEntry::make('customer.name')->label('Customer'),
                        TextEntry::make('customer.customer_type')
                            ->label('Customer Type')
                            ->badge()
                            ->placeholder('Regular')
                            ->formatStateUsing(fn (?string $state): ?string => Customer::typeLabel($state) ?? 'Regular'),
                        TextEntry::make('customer.phone')
                            ->label('Phone')
                            ->placeholder('-')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            // wa.me needs a leading country code, not the local
                            // 0-prefixed format phone numbers are stored in.
                            ->url(fn (Order $record): ?string => PhoneLinks::whatsappUrl($record->customer?->phone))
                            ->openUrlInNewTab(),
                        TextEntry::make('order_date')->date(),
                        TextEntry::make('source')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Order::SOURCES[$state ?? Order::SOURCE_ADMIN] ?? str($state)->headline()->toString()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Order::STATUSES[$state] ?? str($state)->headline()->toString())
                            ->color(fn (string $state): string => match ($state) {
                                Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED => 'success',
                                Order::STATUS_PROCESSING => 'warning',
                                Order::STATUS_CANCELLED, Order::STATUS_RETURNED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('delivery_status')
                            ->label('Delivery')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Order::DELIVERY_STATUSES[$state ?? CourierBooking::STATUS_NOT_BOOKED] ?? str($state)->headline()->toString()),
                    ])
                    ->columns(4),

                Section::make('Courier')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('latestCourierBooking.provider.name')
                            ->label('Provider')
                            ->placeholder('Not booked'),
                        TextEntry::make('latestCourierBooking.tracking_id')
                            ->label('Tracking ID')
                            ->placeholder('Not booked'),
                        TextEntry::make('latestCourierBooking.provider_reference')
                            ->label('Parcel ID')
                            ->placeholder('Not booked'),
                        TextEntry::make('latestCourierBooking.status')
                            ->label('Courier Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => CourierBooking::STATUSES[$state ?? ''] ?? 'Not booked'),
                        TextEntry::make('latestCourierBooking.cod_amount')
                            ->label('COD')
                            ->moneyWithoutTrailingZeroes('BDT')
                            ->placeholder('৳ 0'),
                    ])
                    ->columns(4),

                Section::make('Customer Success & Risk Score')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('latestFraudCheck.risk_score')->label('Score')->placeholder('Not checked'),
                        TextEntry::make('latestFraudCheck.risk_level')->label('Risk Level')->badge()->placeholder('Not checked')
                            ->formatStateUsing(fn (?string $state): string => CustomerRiskProfile::LEVELS[$state ?? ''] ?? 'Not checked'),
                        TextEntry::make('latestFraudCheck.created_at')->label('Checked At')->dateTime()->placeholder('Never'),
                        TextEntry::make('latestRiskReview.status')->label('Review Status')->badge()->placeholder('Not required')
                            ->formatStateUsing(fn (?string $state): string => CustomerRiskReview::STATUSES[$state ?? ''] ?? 'Not required'),
                        TextEntry::make('latestRiskReview.approval_type')->label('Approval Type')->badge()->placeholder('Not required')
                            ->formatStateUsing(fn (?string $state): string => CustomerRiskReview::TYPES[$state ?? ''] ?? 'Not required'),
                        TextEntry::make('latestRiskReview.review_note')->label('Review Note')->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Totals')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('subtotal')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('discount')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('vat')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('total_amount')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('paid_amount')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('due_amount')->moneyWithoutTrailingZeroes('BDT'),
                        TextEntry::make('total_weight')
                            ->label('Total Weight')
                            ->state(fn (Order $record): string => number_format(
                                $record->items->sum(fn (OrderItem $item): float => (int) $item->quantity * (float) ($item->product?->weight_kg ?? 0)),
                                3
                            ).' kg'),
                        TextEntry::make('total_cost')
                            ->label('Associated Costs')
                            ->state(fn (Order $record): float => $record->totalCost())
                            ->moneyWithoutTrailingZeroes('BDT'),
                        // total_amount minus per-line COGS (OrderItem::unit_cost) minus
                        // the Associated Costs ledger below — see Order::profit().
                        TextEntry::make('profit')
                            ->label('Profit')
                            ->state(fn (Order $record): float => $record->profit())
                            ->moneyWithoutTrailingZeroes('BDT')
                            ->color(fn (Order $record): string => $record->profit() >= 0 ? 'success' : 'danger'),
                    ])
                    ->columns(4),

                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Product')
                                    // Admin-only tag: nothing the customer sees shows it.
                                    ->belowContent(Text::make(__('Upsell'))
                                        ->badge()
                                        ->color('success')
                                        ->visible(fn (?OrderItem $record): bool => (bool) $record?->is_upsell)),

                                TextEntry::make('variant_label')
                                    ->label('Variant')
                                    ->placeholder('-'),

                                TextEntry::make('quantity')
                                    ->badge(),

                                TextEntry::make('product.weight_kg')
                                    ->label('Weight (kg)')
                                    ->placeholder('-'),

                                TextEntry::make('unit_price')
                                    ->moneyWithoutTrailingZeroes('BDT'),

                                TextEntry::make('subtotal')
                                    ->moneyWithoutTrailingZeroes('BDT'),
                            ])
                            ->columns(6)
                            ->contained(false)
                            ->columnSpanFull(),
                    ]),

                TextEntry::make('note')->columnSpanFull(),

                Section::make('Activity')
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        TextEntry::make('activity_feed')
                            ->hiddenLabel()
                            ->html()
                            ->state(function (Order $record): string {
                                $entries = app(OrderActivityFeedService::class)->narrate($record);

                                if ($entries->isEmpty()) {
                                    return '<p class="text-sm text-gray-500">No activity recorded yet.</p>';
                                }

                                $rows = $entries->map(fn (array $entry): string => sprintf(
                                    '<li><span class="text-gray-500">%s</span> — %s</li>',
                                    e($entry['at']?->format('d M Y, h:i A') ?? ''),
                                    e($entry['text'])
                                ))->implode('');

                                return "<ul class=\"space-y-1 text-sm\">{$rows}</ul>";
                            }),
                    ]),
            ]);
    }
}
