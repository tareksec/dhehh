<?php

namespace App\Filament\Resources\ChannelPartners\RelationManagers;

use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Models\ChannelPartnerPayout;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * This partner's commission, one row per settlement. Payouts are created by
 * the settlement and marked paid from the settlement's own tab.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Commission');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('settlement.project'))
            ->columns([
                TextColumn::make('settlement.project.project_code')->label(__('Project')),
                TextColumn::make('amount')->label(__('Amount'))->moneyWithoutTrailingZeroes('BDT')->summarize(Sum::make()->moneyWithoutTrailingZeroes('BDT')),
                TextColumn::make('payment_status')->label(__('Status'))->badge()->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('paid_at')->label(__('Paid at'))->date()->placeholder('-'),
                TextColumn::make('voucher.voucher_number')
                    ->label(__('Voucher'))
                    ->placeholder('-')
                    ->url(fn (ChannelPartnerPayout $record): ?string => $record->voucher_id ? ImsPanelLinks::voucher($record->voucher_id) : null),
            ])
            ->recordActions([
                Action::make('settlement')
                    ->label(__('Settlement'))
                    ->icon('heroicon-o-document-text')
                    ->url(fn (ChannelPartnerPayout $record): string => ProjectSettlementResource::getUrl('view', ['record' => $record->settlement_id])),
            ]);
    }
}
