<?php

namespace App\Filament\Ims\Office\Pages;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Ims\Office\Widgets\PendingInvestmentsTable;
use App\Ims\Investments\MoneyConfirmationService;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorGroupMember;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Money to confirm (IMS-V2/PRD.md FR-22, FR-24): group deposits and
 * investments recorded as "waiting for the money". Someone with
 * `ims.investments.confirm` checks the statement and confirms; a new
 * investor needs a verified KYC first (FR-14).
 */
class PendingMoney extends Page implements HasActions, HasSchemas, HasTable
{
    use HasGuardedNavigationBadge;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'money-to-confirm';

    protected string $view = 'filament.ims.office.pages.pending-money';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.investments.confirm') || $user?->hasPermission('ims.reconcile') || $user?->hasPermission('investments.manage')) ?? false;
    }

    public function getTitle(): string
    {
        return __('Money to confirm');
    }

    protected static function navigationBadge(): ?string
    {
        $count = InvestorGroupMember::query()->pending()->count() + Investment::query()->pending()->count();

        return $count > 0 ? ImsNumbers::count($count) : null;
    }

    protected function getFooterWidgets(): array
    {
        return [PendingInvestmentsTable::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Deposits into groups'))
            ->query(InvestorGroupMember::query()->pending()->with(['investor', 'group'])->latest('id'))
            ->columns([
                TextColumn::make('investor.name')->label(__('Investor'))->searchable()->wrap(),
                TextColumn::make('group.name')->label(__('Group'))->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('payment_method')->label(__('Paid by'))->placeholder('—'),
                TextColumn::make('payment_reference')->label(__('Reference'))->placeholder('—'),
                TextColumn::make('investor.kyc_status')->label(__('KYC'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Investor::kycStatusLabel($state))
                    ->color(fn (?string $state): string => Investor::kycStatusColor($state)),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Money received'))
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.investments.confirm') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(fn (InvestorGroupMember $record): string => __('Confirm that :amount from :name has arrived? It becomes active and a receiving voucher is booked.', ['amount' => ImsNumbers::money($record->amount), 'name' => $record->investor?->name]))
                    ->action(function (InvestorGroupMember $record): void {
                        app(MoneyConfirmationService::class)->confirm($record, auth()->user());
                        Notification::make()->title(__('Money confirmed'))->success()->send();
                    }),
                Action::make('discard')
                    ->label(__('Remove'))
                    ->color('danger')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.investments.confirm') ?? false)
                    ->schema([Textarea::make('reason')->label(__('Why (the money never came)'))->required()->minLength(5)])
                    ->action(function (InvestorGroupMember $record, array $data): void {
                        app(MoneyConfirmationService::class)->discard($record, auth()->user(), (string) $data['reason']);
                        Notification::make()->title(__('Removed'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No deposit is waiting'))
            ->emptyStateDescription(__('Deposits recorded as "waiting for the money" show here until someone confirms them.'));
    }
}
