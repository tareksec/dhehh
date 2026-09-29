<?php

namespace App\Filament\Resources\ChannelPartners\RelationManagers;

use App\Filament\Resources\Investors\InvestorResource;
use App\Models\Investment;
use App\Models\Investor;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Investors this channel partner brought in. Assigned from the investor side. */
class ReferredInvestorsRelationManager extends RelationManager
{
    protected static string $relationship = 'investors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Investors brought in');
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum(['investments as active_capital' => fn (Builder $investments) => $investments->where('status', Investment::STATUS_ACTIVE)], 'amount'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('phone')->label(__('Phone')),
                TextColumn::make('active_capital')->label(__('Active capital'))->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Investor $record): string => InvestorResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
