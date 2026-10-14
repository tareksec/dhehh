<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\Investment;
use App\Models\InvestorCycleElection;
use App\Models\InvestorGroupMember;
use App\Models\InvestorRequest;
use App\Models\SettlementPayout;
use App\Services\Investment\InvestmentTargets;
use App\Services\InvestorRequestService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page 6 of §6.1: the investor's requests and their answers, and a form for
 * a new one: add money (top-up), withdrawal notice, what to do with a
 * payout (withdraw / reinvest), or a question. The company decides each one.
 */
class Requests extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationLabel(): string
    {
        return __('Requests');
    }

    public function getTitle(): string
    {
        return __('Requests');
    }

    protected function getHeaderActions(): array
    {
        $currency = $this->currency();
        $isType = fn (string ...$types): \Closure => fn (Get $get): bool => in_array($get('type'), $types, true);

        return [
            Action::make('new')
                ->label(__('New request'))
                ->icon(Heroicon::OutlinedPlus)
                ->hidden(fn (): bool => $this->isStaffPreview())
                ->schema([
                    Select::make('type')
                        ->label(__('What is it about?'))
                        ->options(collect(InvestorRequest::TYPES)->map(fn (string $label): string => __($label))->all())
                        ->required()
                        ->live(),

                    // Top-up
                    Select::make('target')
                        ->label(__('Group'))
                        ->options(fn (): array => app(InvestmentTargets::class)->options())
                        ->required()
                        ->visible($isType(InvestorRequest::TYPE_TOP_UP))
                        ->helperText(__('New money joins a group that is still open. A group that has started takes no new members.')),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(1)->prefix($currency)->required()->visible($isType(InvestorRequest::TYPE_TOP_UP)),
                    Select::make('payment_method')->label(__('How you sent the money'))->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all())->required()->visible($isType(InvestorRequest::TYPE_TOP_UP)),
                    TextInput::make('payment_reference')->label(__('Transaction / slip reference'))->maxLength(100)->visible($isType(InvestorRequest::TYPE_TOP_UP)),
                    DatePicker::make('sent_on')->label(__('Sent on'))->maxDate(now())->visible($isType(InvestorRequest::TYPE_TOP_UP)),
                    Text::make(__('The investment is added once the company confirms the money has arrived. The deed, cheque and witnesses are completed at the office as before.'))->visible($isType(InvestorRequest::TYPE_TOP_UP)),

                    // Withdrawal notice
                    Select::make('withdrawal_target')
                        ->label(__('Group'))
                        ->options(fn (): array => $this->withdrawalOptions())
                        ->required()
                        ->visible($isType(InvestorRequest::TYPE_WITHDRAWAL)),
                    Text::make(__('Under the deed, a withdrawal needs 60 days\' written notice. Once the company accepts this request, the notice runs from the day you sent it.'))->visible($isType(InvestorRequest::TYPE_WITHDRAWAL)),

                    // Payout choice
                    Select::make('settlement_payout_id')
                        ->label(__('Payout'))
                        ->options(fn (): array => $this->portal()->payouts($this->account())
                            ->where('payment_status', 'pending')->whereDoesntHave('cycleElection')->with('settlement.project')->get()
                            ->mapWithKeys(fn (SettlementPayout $payout): array => [$payout->getKey() => $this->portal()->projectLabel($payout->settlement?->project).' — '.MoneyFormatter::currency($payout->total_payout, $currency)])->all())
                        ->required()
                        ->visible($isType(InvestorRequest::TYPE_REINVEST)),
                    Select::make('election')
                        ->label(__('Your choice'))
                        ->options(collect(InvestorCycleElection::ELECTIONS)->map(fn (string $label): string => __($label))->all())
                        ->required()
                        ->live()
                        ->visible($isType(InvestorRequest::TYPE_REINVEST)),
                    Select::make('next_target')
                        ->label(__('Reinvest into'))
                        ->options(fn (): array => app(InvestmentTargets::class)->options())
                        ->required()
                        ->visible(fn (Get $get): bool => $get('type') === InvestorRequest::TYPE_REINVEST && in_array($get('election'), ['reinvest', 'partial_reinvest'], true)),
                    TextInput::make('reinvest_amount')
                        ->label(__('Amount to reinvest'))
                        ->numeric()->minValue(1)->prefix($currency)->required()
                        ->visible(fn (Get $get): bool => $get('type') === InvestorRequest::TYPE_REINVEST && $get('election') === 'partial_reinvest'),

                    Textarea::make('message')
                        ->label(fn (Get $get): string => $get('type') === InvestorRequest::TYPE_SUPPORT ? __('Your question') : __('Note (optional)'))
                        ->required($isType(InvestorRequest::TYPE_SUPPORT))
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $this->refuseInStaffPreview();
                    $type = (string) $data['type'];

                    if ($type === InvestorRequest::TYPE_WITHDRAWAL) {
                        $data['target'] = $data['withdrawal_target'] ?? null;
                    }

                    if ($type === InvestorRequest::TYPE_REINVEST) {
                        $data['amount'] = $data['reinvest_amount'] ?? null;
                    }

                    app(InvestorRequestService::class)->create($this->account(), $type, $data);
                    Notification::make()->title(__('Request sent. The company will answer here.'))->success()->send();
                }),
        ];
    }

    /**
     * Groups the investor is in (and projects outside any group they have
     * money in), keyed `g<id>` / `p<id>`.
     *
     * @return array<string, string>
     */
    private function withdrawalOptions(): array
    {
        $targets = app(InvestmentTargets::class);
        $options = [];

        InvestorGroupMember::query()
            ->where('investor_id', $this->account()->investor_id ?? 0)
            ->where('status', InvestorGroupMember::STATUS_ACTIVE)
            ->with('group')
            ->get()
            ->each(function (InvestorGroupMember $member) use (&$options, $targets): void {
                $options['g'.$member->group_id] = $targets->label($member->group);
            });

        $this->portal()->investments($this->account())
            ->where('status', Investment::STATUS_ACTIVE)->whereNull('group_member_id')->with('project')->get()
            ->each(function (Investment $investment) use (&$options): void {
                $options['p'.$investment->project_id] = $this->portal()->projectLabel($investment->project);
            });

        return $options;
    }

    public function table(Table $table): Table
    {
        $currency = $this->currency();

        return $table
            ->query(fn () => InvestorRequest::query()->where('portal_account_id', $this->account()->getKey())->with(['project', 'group']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Request'))
                    ->formatStateUsing(fn (string $state): string => InvestorRequest::typeLabel($state))
                    ->description(fn (InvestorRequest $record): ?string => collect([
                        $record->group ? app(InvestmentTargets::class)->label($record->group) : null,
                        $record->project ? $this->portal()->projectLabel($record->project) : null,
                        filled($record->admin_note) ? __('Company\'s answer').': '.$record->admin_note : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state): string => MoneyFormatter::currency($state, $currency))->placeholder('-')->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('Sent'))->date('d M Y')->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => InvestorRequest::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        InvestorRequest::STATUS_APPROVED => 'success',
                        InvestorRequest::STATUS_REJECTED => 'danger',
                        InvestorRequest::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('admin_note')->label(__('Company\'s answer'))->placeholder('-')->wrap()->visibleFrom('lg'),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (InvestorRequest $record): bool => $record->isPending() && ! $this->isStaffPreview())
                    ->action(function (InvestorRequest $record): void {
                        $this->refuseInStaffPreview();
                        app(InvestorRequestService::class)->cancel($this->account(), $record);
                    }),
            ])
            ->emptyStateHeading(__('No requests yet.'))
            ->paginated([10, 25, 50]);
    }
}
