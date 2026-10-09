<?php

namespace App\Filament\Resources\ProjectSettlements\RelationManagers;

use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\ChannelPartnerPayout;
use App\Services\Investment\PayoutCompletionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ChannelPartnerPayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'channelPartnerPayouts';

    protected static ?string $title = 'Channel Partner Payout';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payment_method')->options(['cash' => 'Cash', 'bkash' => 'bKash', 'bank' => 'Bank', 'other' => 'Other']),
            TextInput::make('payment_reference'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('channelPartner.name')->label('Channel Partner')->url(fn (ChannelPartnerPayout $record): ?string => $record->channel_partner_id ? ChannelPartnerResource::getUrl('view', ['record' => $record->channel_partner_id]) : null),
            TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('payment_status')->badge(),
            TextColumn::make('paid_at')->date()->placeholder('-'),
            TextColumn::make('voucher.status')
                ->label('Voucher')
                ->badge()
                ->placeholder('-')
                ->url(fn (ChannelPartnerPayout $record): ?string => $record->voucher_id ? VoucherResource::getUrl('view', ['record' => $record->voucher_id]) : null),
        ])->recordActions([
            Action::make('markPaid')->label('Mark as Paid')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn ($record): bool => $this->getOwnerRecord()->status === 'confirmed' && $record->payment_status === 'pending' && (auth()->user()?->hasPermission('investments.settle') ?? false))
                ->schema([
                    Select::make('payment_method')->options(['cash' => 'Cash', 'bkash' => 'bKash', 'bank' => 'Bank', 'other' => 'Other'])->required(),
                    TextInput::make('payment_reference'),
                ])->requiresConfirmation()->action(function (ChannelPartnerPayout $record, array $data): void {
                    try {
                        app(PayoutCompletionService::class)->markPaid($record, auth()->user(), $data);
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(__('Could not mark the payout paid'))->body(collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    Notification::make()->success()->title('Channel payout marked paid')->send();
                }),
        ]);
    }
}
