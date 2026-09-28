<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Filament\Resources\InvestmentRecords\InvestmentRecordResource;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\Investment;
use App\Models\Investor;
use App\Services\Investment\InvestmentLedgerService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvestmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investments';

    public function isReadOnly(): bool
    {
        // Frozen once the investment window is closed, not only once settled
        // (v3 P2.2) -- the recorded investments feed the capital base and
        // report figures from the moment the project stops accepting money.
        return in_array($this->getOwnerRecord()->status, ['closed', 'settled'], true) || parent::isReadOnly();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // 'nid_number' dropped from the search columns -- it is encrypted
            // at rest (v3 P3), so a DB-level LIKE search can never match it.
            Select::make('investor_id')->relationship('investor', 'name')->searchable(['name', 'phone'])->preload()
                ->createOptionForm([
                    TextInput::make('name')->required(),
                    TextInput::make('phone')->required(),
                    TextInput::make('nid_number')->label(__('NID / Passport (Investor ID)'))->required(),
                ])->createOptionUsing(fn (array $data): int => Investor::query()->create($data)->getKey())->required(),
            TextInput::make('amount')->numeric()->prefix('৳')->minValue(0.01)->required(),
            Select::make('payment_method')->options(Investment::PAYMENT_METHODS)->required(),
            TextInput::make('payment_reference'),
            DatePicker::make('invested_at')->default(now())->required(),
            Textarea::make('override_reason')
                ->label('Reason (investment window closed)')
                ->rows(2)
                ->visible(fn (): bool => $this->windowRequiresOverride())
                ->required(fn (): bool => $this->windowRequiresOverride())
                ->helperText('এই project-এর investment window বন্ধ — এখানে একটি কারণ audit log-এ থাকবে।'),
        ]);
    }

    /** Whether adding a new investment right now needs the override reason. */
    private function windowRequiresOverride(): bool
    {
        $project = $this->getOwnerRecord();

        return (bool) $project->investment_closes_at && ! $project->isWithinInvestmentWindow();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount('securityInstruments')->with('securityInstruments'))->columns([
            TextColumn::make('investor.name')->searchable(),
            TextColumn::make('investor.channelPartner.name')->label('Channel Partner')->placeholder('Direct'),
            TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->summarize(Sum::make()->moneyWithoutTrailingZeroes('BDT')),
            TextColumn::make('payment_method')->badge(),
            TextColumn::make('invested_at')->date(),
            // Inline cheque number straight off the investment row (v3 P3) --
            // previously only visible by opening the separate
            // SecurityInstrumentsRelationManager on the investment's own view.
            TextColumn::make('cheque_number')
                ->label('Cheque No.')
                ->state(fn (Investment $record): ?string => $record->securityInstruments->first()?->cheque_number)
                ->placeholder('-'),
            TextColumn::make('security_instruments_count')->label('Security')->badge(),
            TextColumn::make('override_reason')->label('Late-Window Override')->limit(30)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('voucher.status')
                ->label('Voucher')
                ->badge()
                ->placeholder('-')
                ->url(fn (Investment $record): ?string => $record->voucher_id ? VoucherResource::getUrl('view', ['record' => $record->voucher_id]) : null),
        ])->headerActions([
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id, 'received_by' => auth()->id()])
                ->after(fn (Investment $record) => app(InvestmentLedgerService::class)->recordInvestmentReceived($record)),
        ])
            ->recordActions([
                ViewAction::make()->url(fn (Investment $record): string => InvestmentRecordResource::getUrl('view', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
