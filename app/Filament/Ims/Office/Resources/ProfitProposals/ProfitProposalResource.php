<?php

namespace App\Filament\Ims\Office\Resources\ProfitProposals;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Ims\Office\Resources\ProfitProposals\Pages\ListProfitProposals;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Ims\Profit\ProposalService;
use App\Models\ProfitProposal;
use App\Models\ProjectSettlement;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Profit proposals (IMS-V2/PRD.md FR-45, DESIGN §8.2): the figures the system
 * worked out when a cycle ended, and the decision — accept them, enter the
 * profit by hand, or dismiss. Nothing is shared out before a decision.
 */
class ProfitProposalResource extends Resource
{
    use HasGuardedNavigationBadge;

    protected static ?string $model = ProfitProposal::class;

    protected static ?string $slug = 'profit-proposals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('profit proposal');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Profit proposals');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.proposals.approve') || $user?->hasPermission('investments.view')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function navigationBadge(): ?string
    {
        $count = ProfitProposal::query()->where('status', ProfitProposal::STATUS_PENDING)->count();

        return $count > 0 ? ImsNumbers::count($count) : null;
    }

    public static function table(Table $table): Table
    {
        $canDecide = fn (ProfitProposal $record): bool => $record->status === ProfitProposal::STATUS_PENDING && (auth()->user()?->hasPermission('ims.proposals.approve') ?? false);

        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('project'))
            ->columns([
                TextColumn::make('project.name')->label(__('Project'))->wrap(),
                TextColumn::make('period')->label(__('Cycle'))->state(fn (ProfitProposal $record): string => ImsNumbers::date($record->period_start).' → '.ImsNumbers::date($record->period_end)),
                TextColumn::make('figures.revenue')->label(__('Sales'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('figures.costs.total')->label(__('Total cost'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state)),
                TextColumn::make('figures.net_profit')->label(__('Net profit'))->formatStateUsing(fn ($state): string => ImsNumbers::money($state, signed: true))
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => ProfitProposal::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        ProfitProposal::STATUS_PENDING => 'warning',
                        ProfitProposal::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(ProfitProposal::STATUSES)->map(fn (string $label): string => __($label))->all())->default(ProfitProposal::STATUS_PENDING),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label(__('Accept the figures'))
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->visible($canDecide)
                    ->modalDescription(fn (ProfitProposal $record): HtmlString => new HtmlString(e(__('A draft settlement is made with these figures. You still review and confirm it before anyone is told.'))
                        .(($record->figures['shared_products'] ?? 0) > 0 ? '<br><strong>'.e(__(':count products are also in another project\'s purchases, so their sales are not counted here. Enter that profit by hand when settling.', ['count' => $record->figures['shared_products']])).'</strong>' : '')))
                    ->schema(fn (ProfitProposal $record): array => (float) ($record->figures['net_profit'] ?? 0) < 0 ? [
                        Select::make('outcome')->label(__('Who bears the loss'))->options([
                            'loss_investor_borne' => __(ProjectSettlement::OUTCOMES['loss_investor_borne']),
                            'loss_manager_borne' => __(ProjectSettlement::OUTCOMES['loss_manager_borne']),
                        ])->required(),
                        Textarea::make('loss_reason')->label(__('Why the loss happened'))->required(),
                    ] : [])
                    ->action(function (ProfitProposal $record, array $data): void {
                        $settlement = app(ProposalService::class)->accept($record, auth()->user(), $data);
                        Notification::make()->title(__('Draft settlement made. Review and confirm it.'))->success()->send();
                        redirect(ProjectSettlementResource::getUrl('view', ['record' => $settlement]));
                    }),
                Action::make('byHand')
                    ->label(__('Enter the profit by hand'))
                    ->color('gray')
                    ->icon('heroicon-o-pencil')
                    ->visible($canDecide)
                    ->schema([TextInput::make('net_profit')->label(__('Net profit to share'))->numeric()->minValue(0)->required()])
                    ->action(function (ProfitProposal $record, array $data): void {
                        $settlement = app(ProposalService::class)->enterByHand($record, auth()->user(), (float) $data['net_profit']);
                        Notification::make()->title(__('Draft settlement made. Review and confirm it.'))->success()->send();
                        redirect(ProjectSettlementResource::getUrl('view', ['record' => $settlement]));
                    }),
                Action::make('dismiss')
                    ->label(__('Dismiss'))
                    ->color('danger')
                    ->visible($canDecide)
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->minLength(5)])
                    ->action(function (ProfitProposal $record, array $data): void {
                        app(ProposalService::class)->dismiss($record, auth()->user(), (string) $data['reason']);
                        Notification::make()->title(__('Proposal dismissed'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No proposal is waiting'))
            ->emptyStateDescription(__('When a cycle ends, the system works out its profit and shows it here for you to decide.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListProfitProposals::route('/')];
    }
}
