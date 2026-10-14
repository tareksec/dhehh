<?php

namespace App\Filament\Resources\Investors\RelationManagers;

use App\Models\InvestorBankAccount;
use App\Services\InvestorBankAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Payment accounts of an investor or channel partner
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §7.2): approve or reject the
 * ones sent from the portal, or add one for them (approved at once).
 * Used on both the investor and the channel partner page.
 */
class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payment accounts');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $pending = $ownerRecord->bankAccounts()->where('status', InvestorBankAccount::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('investments.portal_manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display')->label(__('Account'))->state(fn (InvestorBankAccount $record): string => $record->displayName())->description(fn (InvestorBankAccount $record): ?string => $record->account_name)->wrap(),
                TextColumn::make('routing_number')->label(__('Routing'))->placeholder('-'),
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
                TextColumn::make('source')->label(__('Added from'))->formatStateUsing(fn (string $state): string => $state === 'portal' ? __('Portal') : __('Office')),
                TextColumn::make('created_at')->label(__('Added'))->date(),
            ])
            ->headerActions([
                Action::make('addAccount')
                    ->label(__('Add a payment account'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        Radio::make('type')->label(__('Type'))->options([InvestorBankAccount::TYPE_BANK => __('Bank account'), InvestorBankAccount::TYPE_MFS => __('Mobile banking')])->default(InvestorBankAccount::TYPE_BANK)->required()->live(),
                        TextInput::make('bank_name')->label(__('Bank name'))->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                        TextInput::make('branch_name')->label(__('Branch'))->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                        TextInput::make('routing_number')->label(__('Routing number (9 digits)'))->required()->regex('/^\d{9}$/')->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                        TextInput::make('account_name')->label(__('Account name'))->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                        TextInput::make('account_number')->label(__('Account number'))->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_BANK),
                        Select::make('mfs_provider')->label(__('Service'))->options(InvestorBankAccount::MFS_PROVIDERS)->required()->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_MFS),
                        TextInput::make('mfs_number')->label(__('Mobile number'))->tel()->required()->regex('/^01\d{9}$/')->visible(fn (Get $get): bool => $get('type') === InvestorBankAccount::TYPE_MFS),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canManage(), 403);
                        $service = app(InvestorBankAccountService::class);
                        $account = $service->add($this->getOwnerRecord(), $data, 'admin');
                        $service->approve($account, auth()->user());
                        Notification::make()->title(__('Payment account added'))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('showNumber')
                    ->label(__('Show number'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (InvestorBankAccount $record): bool => $this->canManage() && $record->type === InvestorBankAccount::TYPE_BANK)
                    ->modalHeading(__('Account number'))
                    ->modalContent(fn (InvestorBankAccount $record) => new HtmlString('<p class="font-mono text-lg">'.e((string) $record->account_number).'</p>'))
                    ->modalSubmitAction(false),
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (InvestorBankAccount $record): bool => $this->canManage() && $record->status === InvestorBankAccount::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalDescription(__('Check the account with the person (call them on their registered phone) before approving. It becomes the account payments go to.'))
                    ->action(function (InvestorBankAccount $record): void {
                        abort_unless($this->canManage(), 403);
                        app(InvestorBankAccountService::class)->approve($record, auth()->user());
                        Notification::make()->title(__('Payment account approved'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (InvestorBankAccount $record): bool => $this->canManage() && $record->status === InvestorBankAccount::STATUS_PENDING)
                    ->schema([Textarea::make('reason')->label(__('Reason (the person sees this)'))->required()->maxLength(1000)])
                    ->action(function (InvestorBankAccount $record, array $data): void {
                        abort_unless($this->canManage(), 403);
                        app(InvestorBankAccountService::class)->reject($record, auth()->user(), (string) $data['reason']);
                        Notification::make()->title(__('Payment account rejected'))->success()->send();
                    }),
            ]);
    }
}
