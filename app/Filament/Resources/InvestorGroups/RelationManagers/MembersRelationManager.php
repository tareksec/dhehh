<?php

namespace App\Filament\Resources\InvestorGroups\RelationManagers;

use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Services\Investment\InvestmentWithdrawalService;
use App\Services\Investment\InvestorGroupService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * The deposits of a group (one row per deposit). New deposits are accepted only
 * while the group is Open; the actions below enforce it, and so does the model.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Members');
    }

    private function group(): InvestorGroup
    {
        return $this->getOwnerRecord();
    }

    private function mayAdd(): bool
    {
        return $this->group()->isOpen() && InvestorGroupResource::canManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('investor_id')
                ->label(__('Investor'))
                ->relationship('investor', 'name')
                ->searchable(['name', 'phone'])
                ->preload()
                ->disabledOn('edit')
                ->required(),
            TextInput::make('amount')->label(__('Amount'))->numeric()->prefix('৳')->minValue(0.01)->required(),
            Select::make('payment_method')->label(__('Payment Method'))
                ->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all())->required(),
            TextInput::make('payment_reference')->label(__('Payment reference (transaction ID)'))->maxLength(255),
            DatePicker::make('invested_at')->label(__('Invested on'))->default(now())->maxDate(now())->required(),
            Textarea::make('notes')->label(__('Notes'))->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['investor', 'voucher'])->withCount('investments'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('investor.name')->label(__('Investor'))->searchable(),
                TextColumn::make('amount')->label(__('Amount'))->moneyWithoutTrailingZeroes('BDT')
                    ->summarize(Sum::make()->label(__('Total'))->moneyWithoutTrailingZeroes('BDT')),
                TextColumn::make('invested_at')->label(__('Invested on'))->date(),
                TextColumn::make('payment_method')->label(__('Payment Method'))->badge()
                    ->formatStateUsing(fn (?string $state): string => __(Investment::PAYMENT_METHODS[$state] ?? (string) $state)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(InvestorGroupMember::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => $state === InvestorGroupMember::STATUS_WITHDRAWN ? 'gray' : 'success'),
                TextColumn::make('investments_count')->label(__('Projects joined')),
                TextColumn::make('leaves_after_sequence')->label(__('Left after project'))->formatStateUsing(fn ($state): string => 'P'.$state)->placeholder('-'),
                TextColumn::make('voucher.status')->label(__('Voucher'))->badge()->placeholder('-')
                    ->url(fn (InvestorGroupMember $record): ?string => $record->voucher_id ? VoucherResource::getUrl('view', ['record' => $record->voucher_id]) : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add member'))
                    ->visible(fn (): bool => $this->mayAdd())
                    ->using(function (array $data): InvestorGroupMember {
                        abort_unless($this->mayAdd(), 403);

                        return app(InvestorGroupService::class)->addMember(
                            $this->group(),
                            Investor::query()->findOrFail($data['investor_id']),
                            $data,
                            auth()->user(),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (InvestorGroupMember $record): bool => $this->mayAdd() && ! $record->isWithdrawn()),
                $this->withdrawAction(),
                DeleteAction::make()
                    ->visible(fn (InvestorGroupMember $record): bool => $this->mayAdd() && ! $record->isWithdrawn() && blank($record->voucher_id)),
            ]);
    }

    private function withdrawAction(): Action
    {
        return Action::make('withdrawDeposit')
            ->label(__('Withdraw deposit'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (InvestorGroupMember $record): bool => $this->mayAdd() && ! $record->isWithdrawn())
            ->modalDescription(__('Use this when the investor takes the deposit back before the group starts. A pending debit voucher (Investor Payout) is created in Finance for the full amount, and only then is the member marked withdrawn. The record is kept.'))
            ->schema([
                DatePicker::make('withdrawn_at')->label(__('Withdrawn on'))->default(now())->maxDate(now())->required(),
                Select::make('fund_source_id')->label(__('Paid from (fund source)'))
                    ->options(fn (): array => FundSource::query()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => $this->group()->payout_fund_source_id)
                    ->searchable()->required(),
                Select::make('payment_method')->label(__('Payment Method'))
                    ->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all())
                    ->default(fn (InvestorGroupMember $record): ?string => $record->payment_method)
                    ->required(),
                TextInput::make('payment_reference')->label(__('Payment reference (transaction ID)'))->maxLength(255),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(function (InvestorGroupMember $record, array $data, Action $action): void {
                abort_unless($this->mayAdd(), 403);

                try {
                    $voucher = app(InvestmentWithdrawalService::class)->withdrawGroupMember($record, $data, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title(__('Member marked withdrawn. Voucher :number is waiting for approval in Finance.', ['number' => $voucher->voucher_number]))
                    ->send();
            });
    }
}
