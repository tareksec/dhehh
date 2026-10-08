<?php

namespace App\Filament\Resources\InvestorRequests;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestorRequests\Pages\ListInvestorRequests;
use App\Models\Investment;
use App\Models\InvestorCycleElection;
use App\Models\InvestorRequest;
use App\Services\InvestorPortalService;
use App\Services\InvestorRequestService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * The queue of requests investors send from the portal
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §7.3). Approving one runs the
 * flow staff already use (new investment, 60-day withdrawal notice, payout
 * election); each type needs that flow's own permission.
 */
class InvestorRequestResource extends Resource
{
    protected static ?string $model = InvestorRequest::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Investor Requests');
    }

    public static function getModelLabel(): string
    {
        return __('investor request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Investor Requests');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission('investments.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $count = InvestorRequest::query()->where('status', InvestorRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static function canDecide(InvestorRequest $record): bool
    {
        return $record->isPending() && (auth()->user()?->hasPermission(InvestorRequestService::permissionFor($record->type)) ?? false);
    }

    public static function table(Table $table): Table
    {
        $portal = app(InvestorPortalService::class);
        $money = fn ($state): string => $state === null ? '-' : MoneyFormatter::currency($state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['investor', 'project', 'payout.settlement.project']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Sent'))->dateTime()->sortable(),
                TextColumn::make('investor.name')->label(__('Investor'))->searchable(),
                TextColumn::make('type')->label(__('Request'))->formatStateUsing(fn (string $state): string => InvestorRequest::typeLabel($state))->badge(),
                TextColumn::make('details')
                    ->label(__('Details'))
                    ->state(fn (InvestorRequest $record): string => static::details($record, $portal))
                    ->wrap(),
                TextColumn::make('message')->label(__('Investor\'s note'))->placeholder('-')->wrap()->limit(200),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InvestorRequest::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        InvestorRequest::STATUS_APPROVED => 'success',
                        InvestorRequest::STATUS_REJECTED => 'danger',
                        InvestorRequest::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (InvestorRequest $record): ?string => $record->admin_note),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(InvestorRequest::STATUSES)->map(fn (string $label): string => __($label))->all())->default(InvestorRequest::STATUS_PENDING),
                SelectFilter::make('type')->label(__('Request'))->options(collect(InvestorRequest::TYPES)->map(fn (string $label): string => __($label))->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(fn (InvestorRequest $record): string => $record->type === InvestorRequest::TYPE_SUPPORT ? __('Answer') : __('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (InvestorRequest $record): bool => static::canDecide($record))
                    ->modalDescription(fn (InvestorRequest $record): string => match ($record->type) {
                        InvestorRequest::TYPE_TOP_UP => __('Approve only after the money has arrived. This adds the investment and its pending capital voucher, as on the project\'s Investments tab.'),
                        InvestorRequest::TYPE_WITHDRAWAL => __('This records a 60-day withdrawal notice from the day the investor sent the request.'),
                        InvestorRequest::TYPE_REINVEST => __('This records the investor\'s choice on the payout; a reinvest also adds the new investment now.'),
                        default => __('Your answer is shown to the investor in the portal.'),
                    })
                    ->fillForm(fn (InvestorRequest $record): array => [
                        'amount' => $record->amount,
                        'payment_method' => $record->payload['payment_method'] ?? null,
                        'payment_reference' => $record->payload['payment_reference'] ?? null,
                        'invested_at' => $record->payload['sent_on'] ?? now()->toDateString(),
                    ])
                    ->schema(fn (InvestorRequest $record): array => array_values(array_filter([
                        $record->type === InvestorRequest::TYPE_TOP_UP ? TextInput::make('amount')->label(__('Amount received'))->numeric()->minValue(0.01)->required() : null,
                        $record->type === InvestorRequest::TYPE_TOP_UP ? DatePicker::make('invested_at')->label(__('Received on'))->required() : null,
                        $record->type === InvestorRequest::TYPE_TOP_UP ? Select::make('payment_method')->label(__('Payment method'))->options(Investment::PAYMENT_METHODS)->required() : null,
                        $record->type === InvestorRequest::TYPE_TOP_UP ? TextInput::make('payment_reference')->label(__('Reference'))->maxLength(255) : null,
                        $record->type === InvestorRequest::TYPE_TOP_UP && $record->project?->investment_closes_at && ! $record->project->isWithinInvestmentWindow()
                            ? Textarea::make('override_reason')->label(__('Reason for adding after the window closed'))->required() : null,
                        Textarea::make('admin_note')
                            ->label(__('Note to the investor'))
                            ->required($record->type === InvestorRequest::TYPE_SUPPORT)
                            ->maxLength(2000),
                    ])))
                    ->action(function (InvestorRequest $record, array $data): void {
                        try {
                            app(InvestorRequestService::class)->approve($record, auth()->user(), $data);
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title(__('Could not approve the request'))->body(collect($exception->errors())->flatten()->first())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Request approved'))->send();
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (InvestorRequest $record): bool => static::canDecide($record))
                    ->schema([Textarea::make('admin_note')->label(__('Reason (the investor sees this)'))->required()->maxLength(2000)])
                    ->action(function (InvestorRequest $record, array $data): void {
                        app(InvestorRequestService::class)->reject($record, auth()->user(), (string) $data['admin_note']);
                        Notification::make()->success()->title(__('Request rejected'))->send();
                    }),
            ]);
    }

    protected static function details(InvestorRequest $record, InvestorPortalService $portal): string
    {
        return match ($record->type) {
            InvestorRequest::TYPE_TOP_UP => trim($portal->projectLabel($record->project).' · '.MoneyFormatter::currency($record->amount).' · '.(Investment::PAYMENT_METHODS[$record->payload['payment_method'] ?? ''] ?? '').(filled($record->payload['payment_reference'] ?? null) ? ' · '.$record->payload['payment_reference'] : '')),
            InvestorRequest::TYPE_WITHDRAWAL => $portal->projectLabel($record->project),
            InvestorRequest::TYPE_REINVEST => trim($portal->projectLabel($record->payout?->settlement?->project).' · '.__(InvestorCycleElection::ELECTIONS[$record->payload['election'] ?? ''] ?? '').($record->project ? ' → '.$portal->projectLabel($record->project) : '').($record->amount !== null ? ' · '.MoneyFormatter::currency($record->amount) : '')),
            default => '-',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListInvestorRequests::route('/')];
    }
}
