<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\ChannelPartner;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Services\InvestorBankAccountService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Page 7 of §6.1: where the person's money is sent. A new account (a bank
 * change too) waits for the company to approve it; until then payments go
 * to the approved one.
 */
class BankAccounts extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'holder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'payment-accounts';

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationGroup(): ?string
    {
        return __('Money');
    }

    public static function getNavigationLabel(): string
    {
        return __('Payment accounts');
    }

    public function getTitle(): string
    {
        return __('Payment accounts');
    }

    /** The investor record, else the partner record, of this sign-in. */
    protected function holder(): Investor|ChannelPartner
    {
        $account = $this->account();

        return $account->isInvestor() ? $account->investor : $account->channelPartner;
    }

    protected function ownAccounts(): Builder
    {
        $account = $this->account();

        return InvestorBankAccount::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('holder_type', (new Investor)->getMorphClass())->where('holder_id', $account->isInvestor() ? $account->investor_id : 0))
            ->orWhere(fn (Builder $query) => $query->where('holder_type', (new ChannelPartner)->getMorphClass())->where('holder_id', $account->isChannelPartner() ? $account->channel_partner_id : 0)));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('Add a payment account'))
                ->icon(Heroicon::OutlinedPlus)
                ->hidden(fn (): bool => $this->isStaffPreview())
                ->modalDescription(__('The company checks a new account before using it. Until then payments go to your approved account.'))
                ->schema([
                    Radio::make('type')
                        ->label(__('Type'))
                        ->options([InvestorBankAccount::TYPE_BANK => __('Bank account'), InvestorBankAccount::TYPE_MFS => __('Mobile banking')])
                        ->default(InvestorBankAccount::TYPE_BANK)
                        ->required()
                        ->live(),
                    TextInput::make('bank_name')->label(__('Bank name'))->maxLength(255)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                    TextInput::make('branch_name')->label(__('Branch'))->maxLength(255)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                    TextInput::make('routing_number')->label(__('Routing number (9 digits)'))->required()->regex('/^\d{9}$/')->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                    TextInput::make('account_name')->label(__('Account name'))->maxLength(255)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                    TextInput::make('account_number')->label(__('Account number'))->maxLength(40)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                    Select::make('mfs_provider')->label(__('Service'))->options(InvestorBankAccount::MFS_PROVIDERS)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_MFS),
                    TextInput::make('mfs_number')->label(__('Mobile number'))->tel()->required()->regex('/^01\d{9}$/')->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_MFS),
                ])
                ->action(function (array $data): void {
                    $this->refuseInStaffPreview();
                    app(InvestorBankAccountService::class)->add($this->holder(), $data);
                    Notification::make()->title(__('Sent to the company for approval.'))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->ownAccounts())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display')->label(__('Account'))->state(fn (InvestorBankAccount $record): string => $record->displayName())->description(fn (InvestorBankAccount $record): ?string => $record->account_name)->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InvestorBankAccount::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        InvestorBankAccount::STATUS_APPROVED => 'success',
                        InvestorBankAccount::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (InvestorBankAccount $record): ?string => $record->rejection_reason),
                IconColumn::make('is_default')->label(__('Payments go here'))->boolean(),
            ])
            ->emptyStateHeading(__('No payment account yet.'))
            ->paginated(false);
    }
}
