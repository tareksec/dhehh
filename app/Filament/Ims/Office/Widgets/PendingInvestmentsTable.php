<?php

namespace App\Filament\Ims\Office\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Ims\Investments\MoneyConfirmationService;
use App\Models\Investment;
use App\Models\Investor;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Investments in a project outside a group that wait for the money (on "Money to confirm"). */
class PendingInvestmentsTable extends TableWidget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Investments in projects'))
            ->query(Investment::query()->pending()->with(['investor', 'project'])->latest('id'))
            ->columns([
                TextColumn::make('investor.name')->label(__('Investor'))->wrap(),
                TextColumn::make('project.name')->label(__('Project'))->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('—'),
                TextColumn::make('investor.kyc_status')->label(__('KYC'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Investor::kycStatusLabel($state))
                    ->color(fn (?string $state): string => Investor::kycStatusColor($state)),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Money received'))
                    ->color('success')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.investments.confirm') ?? false)
                    ->requiresConfirmation()
                    ->action(function (Investment $record): void {
                        app(MoneyConfirmationService::class)->confirm($record, auth()->user());
                        Notification::make()->title(__('Money confirmed'))->success()->send();
                    }),
                Action::make('discard')
                    ->label(__('Remove'))
                    ->color('danger')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.investments.confirm') ?? false)
                    ->schema([Textarea::make('reason')->label(__('Why (the money never came)'))->required()->minLength(5)])
                    ->action(function (Investment $record, array $data): void {
                        app(MoneyConfirmationService::class)->discard($record, auth()->user(), (string) $data['reason']);
                        Notification::make()->title(__('Removed'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No investment is waiting'));
    }
}
