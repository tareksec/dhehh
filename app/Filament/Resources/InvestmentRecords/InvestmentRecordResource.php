<?php

namespace App\Filament\Resources\InvestmentRecords;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\InvestmentRecords\Pages\ViewInvestmentRecord;
use App\Filament\Resources\InvestmentRecords\RelationManagers\SecurityInstrumentsRelationManager;
use App\Filament\Resources\InvestmentRecords\RelationManagers\WitnessesRelationManager;
use App\Models\FundSource;
use App\Models\Investment;
use App\Services\Investment\InvestmentWithdrawalService;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class InvestmentRecordResource extends Resource
{
    protected static ?string $model = Investment::class;

    protected static ?string $cluster = Investments::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Investment')->schema([
                TextEntry::make('project.project_code')->label('Project'),
                TextEntry::make('investor.name'),
                TextEntry::make('amount')->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('payment_method')->badge(),
                TextEntry::make('payment_reference')->placeholder('-'),
                TextEntry::make('invested_at')->date(),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statusLabel($state))
                    ->color(fn (?string $state): string => static::statusColor($state)),
            ])->columns(2),
            Section::make(__('Withdrawal'))
                ->visible(fn (Investment $record): bool => $record->isWithdrawn())
                ->schema([
                    TextEntry::make('withdrawn_at')->label(__('Withdrawn on'))->date(),
                    TextEntry::make('withdrawalVoucher.voucher_number')
                        ->label(__('Withdrawal voucher'))
                        ->url(fn (Investment $record): ?string => $record->withdrawal_voucher_id ? ImsPanelLinks::voucher($record->withdrawal_voucher_id) : null)
                        ->placeholder('-'),
                    TextEntry::make('withdrawalVoucher.status')->label(__('Voucher status'))->badge()->placeholder('-'),
                    TextEntry::make('withdrawal_note')->label(__('Note'))->placeholder('-')->columnSpanFull(),
                ])->columns(3),
        ]);
    }

    public static function statusLabel(?string $status): string
    {
        return __(Investment::STATUSES[$status ?? Investment::STATUS_ACTIVE] ?? (string) $status);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            Investment::STATUS_WITHDRAWN => 'gray',
            Investment::STATUS_PENDING => 'warning',
            default => 'success',
        };
    }

    /**
     * "Withdraw principal": creates the withdrawal voucher and only then marks
     * the investment withdrawn (owner's fixed rule). Shared by the investment
     * page and the investments tabs of the investor and the project.
     */
    public static function withdrawAction(): Action
    {
        return Action::make('withdrawPrincipal')
            ->label(__('Withdraw principal'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Investment $record): bool => ! $record->isWithdrawn() && ! $record->isPending() && (Auth::user()?->hasPermission('investments.manage') ?? false))
            ->modalHeading(fn (Investment $record): string => __('Withdraw :amount from :project', ['amount' => number_format((float) $record->amount), 'project' => (string) ($record->project?->project_code ?: $record->project?->name)]))
            ->modalDescription(__('Use this when the investor has taken their principal back. A pending debit voucher (Investor Payout) is created in Finance for the full amount, and only then is the investment marked withdrawn. The investment stays in the records; it is not deleted.'))
            ->schema([
                DatePicker::make('withdrawn_at')->label(__('Withdrawn on'))->default(now())->maxDate(now())->required(),
                Select::make('fund_source_id')
                    ->label(__('Paid from (fund source)'))
                    ->options(fn (): array => FundSource::query()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (Investment $record): ?int => $record->project?->payout_fund_source_id)
                    ->searchable()
                    ->required(),
                Select::make('payment_method')
                    ->label(__('Payment Method'))
                    ->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all())
                    ->default(fn (Investment $record): ?string => $record->payment_method)
                    ->required(),
                TextInput::make('payment_reference')->label(__('Payment reference (transaction ID)'))->maxLength(255),
                Textarea::make('note')->label(__('Note'))->rows(2),
            ])
            ->action(function (Investment $record, array $data): void {
                abort_unless(Auth::user()?->hasPermission('investments.manage'), 403);

                $voucher = app(InvestmentWithdrawalService::class)->withdraw($record, $data, Auth::user());

                Notification::make()
                    ->title(__('Investment marked withdrawn. Voucher :number is waiting for approval in Finance.', ['number' => $voucher->voucher_number]))
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [SecurityInstrumentsRelationManager::class, WitnessesRelationManager::class, DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['view' => ViewInvestmentRecord::route('/{record}')];
    }

    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return InvestmentProjectResource::getUrl(
            'index',
            $parameters,
            $isAbsolute,
            $panel,
            $tenant,
            $shouldGuessMissingParameters,
        );
    }
}
