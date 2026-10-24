<?php

namespace App\Filament\Resources\InvestmentProjects;

use App\Filament\Clusters\Investments;
use App\Filament\Forms\Components\FundSourceSelect;
use App\Filament\Resources\InvestmentProjects\Pages\CreateInvestmentProject;
use App\Filament\Resources\InvestmentProjects\Pages\EditInvestmentProject;
use App\Filament\Resources\InvestmentProjects\Pages\ListInvestmentProjects;
use App\Filament\Resources\InvestmentProjects\Pages\ViewInvestmentProject;
use App\Filament\Resources\InvestmentProjects\RelationManagers\CostItemsRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\InvestmentsRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\LinkedPurchasesRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Support\MoneyFormatter;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvestmentProjectResource extends Resource
{
    protected static ?string $model = InvestmentProject::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Project Details')->schema([
                TextInput::make('project_code')
                    ->hintAction(static::fieldHelp('projectCodeHelp', 'Project Code', 'প্রতিটি investment project-এর স্বতন্ত্র রেফারেন্স নম্বর। এটি company ও বছর অনুযায়ী save করার সময় স্বয়ংক্রিয়ভাবে তৈরি হয়; হাতে লিখতে বা পরিবর্তন করতে হয় না।'))
                    ->disabled()->dehydrated(false)->placeholder('Generated on save'),
                TextInput::make('name')
                    ->hintAction(static::fieldHelp('projectNameHelp', 'Project Name', 'প্রজেক্টটি সহজে চেনার জন্য সংক্ষিপ্ত নাম দিন। উদাহরণ: “Shearing Machine Import 2026”। তালিকা, settlement এবং investor history-তে এই নাম দেখা যাবে।'))
                    ->required()->maxLength(255),
                TextInput::make('deal_reference')
                    ->hintAction(static::fieldHelp('dealReferenceHelp', 'Deal Reference', 'যে নির্দিষ্ট মেশিন, চালান বা ব্যবসায়িক deal-এর জন্য টাকা নেওয়া হচ্ছে তার নাম/রেফারেন্স লিখুন। উদাহরণ: “Shearing Machine (Taiwan Made)”।'))
                    ->maxLength(255),
                Select::make('purchase_id')
                    ->hintAction(static::fieldHelp('purchaseHelp', 'Linked Purchase', 'এই investment-এর টাকা দিয়ে করা existing Purchase record নির্বাচন করুন। এতে purchase invoice, costing ও project expense সহজে trace করা যাবে। Purchase এখনো তৈরি না হলে খালি রাখা যায়।'))
                    ->relationship('purchase', 'purchase_number')->searchable()->preload(),
                Select::make('duration_type')
                    ->hintAction(static::fieldHelp('durationTypeHelp', 'Duration Type', 'চুক্তির সম্ভাব্য trade cycle নির্বাচন করুন। ২/৬/১২ মাস নিলে দিনের সংখ্যা স্বয়ংক্রিয়ভাবে নির্ধারিত হবে; অন্য মেয়াদের জন্য Custom days নির্বাচন করুন।'))
                    ->options(InvestmentProject::DURATIONS)->default('custom_days')->required()->live(),
                TextInput::make('trade_cycle_days')
                    ->hintAction(static::fieldHelp('tradeCycleDaysHelp', 'Trade Cycle Days', 'Custom duration হলে project শুরু থেকে expected settlement পর্যন্ত মোট দিন লিখুন। এই সংখ্যা annualized return report হিসাব করতে ব্যবহৃত হয়; প্রকৃত profit payout বদলায় না।'))
                    ->numeric()->minValue(1)->required(fn (Get $get): bool => $get('duration_type') === 'custom_days')->visible(fn (Get $get): bool => $get('duration_type') === 'custom_days'),
                DatePicker::make('start_date')
                    ->hintAction(static::fieldHelp('startDateHelp', 'Start Date', 'যেদিন থেকে project-এর trade cycle ও investment period আনুষ্ঠানিকভাবে শুরু হবে সেই তারিখ।'))
                    ->default(now())->required(),
                DatePicker::make('end_date')
                    ->hintAction(static::fieldHelp('endDateHelp', 'Expected End Date', 'Project বা trade cycle শেষ হওয়ার প্রত্যাশিত তারিখ। নিশ্চিত না হলে খালি রাখা যায়; এটি Start Date-এর আগে হতে পারবে না।'))
                    ->afterOrEqual('start_date'),
                DatePicker::make('investment_opens_at')
                    ->label('Investment Window Opens')
                    ->hintAction(static::fieldHelp('investmentOpensHelp', 'Investment Window Opens', 'যেদিন থেকে investor-রা এই project-এ টাকা জমা দেওয়া শুরু করতে পারবেন। ঐচ্ছিক — খালি রাখলে কোনো window restriction থাকবে না।')),
                DatePicker::make('investment_closes_at')
                    ->label('Investment Window Closes')
                    ->hintAction(static::fieldHelp('investmentClosesHelp', 'Investment Window Closes', 'এই তারিখের পর নতুন investment যোগ করতে গেলে বিশেষ অনুমতি (Override) এবং একটি কারণ লাগবে। ঐচ্ছিক — খালি রাখলে কোনো window restriction থাকবে না।'))
                    ->afterOrEqual('investment_opens_at'),
                TextInput::make('target_amount')
                    ->hintAction(static::fieldHelp('targetAmountHelp', 'Target Amount', 'এই project-এর জন্য মোট কত টাকা investor-দের কাছ থেকে সংগ্রহের লক্ষ্য তা লিখুন। এটি funding progress দেখায়; settlement profit calculation-এ সরাসরি ব্যবহার হয় না।'))
                    ->numeric()->prefix('৳')->minValue(0),
                TextInput::make('company_contribution_amount')
                    ->hintAction(static::fieldHelp('companyContributionHelp', 'Company Contribution', 'Investor-রা target-এর চেয়ে কম দিলে company নিজে যত টাকা পূরণ করেছে। এই অংশও investor-pool rate-এ profit পায়, যা company-র net-এর সাথে যোগ হয়। gap না থাকলে ০ রাখুন।'))
                    ->numeric()->prefix('৳')->minValue(0)->default(0)->required()->live(debounce: 500),
                TextInput::make('company_contribution_note')
                    ->hintAction(static::fieldHelp('companyContributionNoteHelp', 'Contribution Note', 'কেন company gap পূরণ করল — সংক্ষিপ্ত নোট (ঐচ্ছিক)।'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => (float) $get('company_contribution_amount') > 0),
                FundSourceSelect::make('receiving_fund_source_id')
                    ->label('Receiving Fund Source')
                    ->hintAction(static::fieldHelp('receivingFundSourceHelp', 'Receiving Fund Source', 'ঐচ্ছিক। নির্বাচন করলে investor-দের প্রতিটি নতুন investment একটি pending capital investment voucher হিসেবে এই fund source-এ বুক হবে — কেউ verify/approve করবে বিদ্যমান Voucher workflow-এ। খালি রাখলে কোনো voucher তৈরি হবে না।')),
                FundSourceSelect::make('payout_fund_source_id')
                    ->label('Payout Fund Source')
                    ->hintAction(static::fieldHelp('payoutFundSourceHelp', 'Payout Fund Source', 'ঐচ্ছিক। নির্বাচন করলে investor/channel-partner payout "Mark as Paid" করলে একটি pending investor payout voucher তৈরি হবে এই fund source থেকে।')),
                Select::make('status')
                    ->hintAction(static::fieldHelp('statusHelp', 'Project Status', 'Open = investment নেওয়া হচ্ছে; Running = deal চলছে; Closed = হিসাব প্রস্তুত এবং settlement করা যাবে। Settled status হাতে দেওয়া যায় না—Calculate & Settle action সফল হলে স্বয়ংক্রিয়ভাবে হয়।'))
                    ->options(fn (?InvestmentProject $record): array => $record?->status === 'settled' ? ['settled' => 'Settled'] : array_diff_key(InvestmentProject::STATUSES, ['settled' => true]))->default('open')->required(),
                Textarea::make('description')
                    ->hintAction(static::fieldHelp('descriptionHelp', 'Description', 'Deal-এর উদ্দেশ্য, পণ্য, expected sales plan, investor-কে আগে জানানো direct cost বা অন্যান্য গুরুত্বপূর্ণ শর্ত লিখুন। এটি transparency ও audit reference হিসেবে থাকবে।'))
                    ->rows(3)->columnSpanFull(),
            ])->columns(2),
            Section::make('Profit Split (%)')
                ->description('The three shares must total exactly 100%. Defaults are 40% investor, 10% channel partner, and 50% company.')
                ->schema([
                    TextInput::make('investor_share_percent')
                        ->hintAction(static::fieldHelp('investorShareHelp', 'Investor Share', 'Direct project costs বাদ দেওয়ার পর যে Net Profit থাকবে, তার কত শতাংশ investor pool-এ যাবে। প্রত্যেক investor তার বিনিয়োগের অনুপাতে এই pool থেকে profit পাবেন। বর্তমান default 40%।'))
                        ->numeric()->default(40)->minValue(0)->maxValue(100)->suffix('%')->required()
                        ->live(debounce: 500),
                    TextInput::make('channel_partner_share_percent')
                        ->hintAction(static::fieldHelp('channelShareHelp', 'Channel Partner Share', 'Net Profit-এর যে শতাংশ eligible channel partner পাবেন। বর্তমান version-এ একটি project-এ একজন partner সমর্থিত; direct investor-এর সংশ্লিষ্ট unallocated share Company Net-এ যোগ হয়। Default 10%।'))
                        ->numeric()->default(10)->minValue(0)->maxValue(100)->suffix('%')->required()
                        ->live(debounce: 500),
                    TextInput::make('company_share_percent')
                        ->hintAction(static::fieldHelp('companyShareHelp', 'Company Share', 'Net Profit-এর কোম্পানির চুক্তিভিত্তিক অংশ। এর সঙ্গে channel partner ছাড়া আসা investment-এর unallocated channel share যোগ হতে পারে। বর্তমান default 50%।'))
                        ->numeric()->default(50)->minValue(0)->maxValue(100)->suffix('%')->required()
                        ->live(debounce: 500)
                        ->rule(fn (Get $get): Closure => static::profitSplitValidationRule($get)),
                    Callout::make(fn (Get $get): string => static::hasValidProfitSplit($get)
                        ? 'Profit split is valid'
                        : 'Profit split needs correction')
                        ->description(fn (Get $get): string => 'Current total: '.MoneyFormatter::number(static::profitSplitTotal($get)).'%. Investor, channel partner, and company shares must total exactly 100.00%.')
                        ->status(fn (Get $get): string => static::hasValidProfitSplit($get) ? 'success' : 'danger')
                        ->columnSpanFull(),
                ])->columns(3),
        ]);
    }

    private static function profitSplitValidationRule(Get $get): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! static::hasValidProfitSplit($get)) {
                $fail('Investor, channel partner, and company shares must total exactly 100.00%.');
            }
        };
    }

    private static function hasValidProfitSplit(Get $get): bool
    {
        return abs(static::profitSplitTotal($get) - 100) <= 0.001;
    }

    private static function profitSplitTotal(Get $get): float
    {
        return (float) ($get('investor_share_percent') ?? 0)
            + (float) ($get('channel_partner_share_percent') ?? 0)
            + (float) ($get('company_share_percent') ?? 0);
    }

    private static function fieldHelp(string $name, string $heading, string $description): Action
    {
        return Action::make($name)
            ->label("Help for {$heading}")
            ->icon(Heroicon::OutlinedInformationCircle)
            ->iconButton()
            ->color('gray')
            ->tooltip("{$heading} কী কাজে ব্যবহার হয়")
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Project Summary')->schema([
                TextEntry::make('project_code'),
                TextEntry::make('name'),
                TextEntry::make('group.name')
                    ->label(__('Group'))
                    ->formatStateUsing(fn (InvestmentProject $record): string => $record->group->name.' · P'.$record->sequence_in_group)
                    ->url(fn (InvestmentProject $record): ?string => $record->group_id ? InvestorGroupResource::getUrl('view', ['record' => $record->group_id]) : null)
                    ->visible(fn (InvestmentProject $record): bool => $record->isGrouped()),
                TextEntry::make('deal_reference')->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('investment_window')
                    ->label('Investment Window')
                    ->state(fn (InvestmentProject $record): ?string => $record->investmentWindowLabel())
                    ->badge()
                    ->color(fn (InvestmentProject $record): string => $record->isWithinInvestmentWindow() ? 'success' : 'danger')
                    ->visible(fn (InvestmentProject $record): bool => filled($record->investmentWindowLabel())),
                TextEntry::make('start_date')->date(),
                TextEntry::make('end_date')->date()->placeholder('-'),
                TextEntry::make('target_amount')->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
                TextEntry::make('total_invested')->state(fn (InvestmentProject $record): float => $record->totalInvested())->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('company_contribution_amount')
                    ->label('Company Contribution')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->hint(fn (InvestmentProject $record): ?string => $record->company_contribution_note),
                TextEntry::make('capital_base')
                    ->label('Total Capital Base')
                    ->state(fn (InvestmentProject $record): float => $record->totalCapitalBase())
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->helperText('Investor + company contribution — settlement-এ profit pool ও rate per lac এর উপর হিসাব হয়।'),
                TextEntry::make('capital_gap')
                    ->label(__('Capital base vs landed cost'))
                    ->state(fn (InvestmentProject $record): ?string => $record->capitalBaseGap() === null ? null : ViewInvestmentProject::capitalGapMessage($record))
                    ->color('warning')
                    ->columnSpanFull()
                    ->visible(fn (InvestmentProject $record): bool => $record->capitalBaseGap() !== null && $record->status !== 'settled'),
                TextEntry::make('receivingFundSource.name')->label('Receiving Fund Source')->placeholder('Not configured — no voucher created'),
                TextEntry::make('payoutFundSource.name')->label('Payout Fund Source')->placeholder('Not configured — no voucher created'),
                TextEntry::make('funding_progress')
                    ->label('Funding Progress')
                    ->state(fn (InvestmentProject $record): ?float => static::fundingProgress($record))
                    ->suffix('%')
                    ->badge()
                    ->color(fn (?float $state): string => static::fundingProgressColor($state))
                    ->placeholder('No target'),
                TextEntry::make('profit_split')->state(fn (InvestmentProject $record): string => "{$record->investor_share_percent}% / {$record->channel_partner_share_percent}% / {$record->company_share_percent}%")->label('Investor / Channel / Company'),
                TextEntry::make('description')->columnSpanFull()->placeholder('-'),
            ])->columns(2),
            Section::make('Direct Cost Summary')->schema([
                TextEntry::make('total_landed_cost')
                    ->label('Total Landed Cost')
                    ->state(fn (InvestmentProject $record): float => $record->totalLandedCost())
                    ->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('total_local_expense')
                    ->label('Total Local Expense')
                    ->state(fn (InvestmentProject $record): float => $record->totalLocalExpense())
                    ->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('total_direct_cost')
                    ->label('Total Direct Cost')
                    ->state(fn (InvestmentProject $record): float => $record->totalCostItems())
                    ->moneyWithoutTrailingZeroes('BDT'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withSum('investments', 'amount'))->columns([
            TextColumn::make('project_code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('group.group_code')->label(__('Group'))->placeholder('-')->sortable()->toggleable(),
            TextColumn::make('deal_reference')->limit(30)->placeholder('-'),
            TextColumn::make('investments_sum_amount')->label('Invested')->moneyWithoutTrailingZeroes('BDT')->sortable(),
            TextColumn::make('target_amount')->moneyWithoutTrailingZeroes('BDT')->placeholder('-'),
            TextColumn::make('funding_progress')
                ->label('Funding')
                ->state(fn (InvestmentProject $record): ?float => static::fundingProgress($record))
                ->suffix('%')
                ->badge()
                ->color(fn (?float $state): string => static::fundingProgressColor($state))
                ->icon(Heroicon::OutlinedChartBar)
                ->description(fn (InvestmentProject $record): ?string => (float) $record->target_amount > 0
                    ? MoneyFormatter::currency((float) ($record->investments_sum_amount ?? 0)).' of '.MoneyFormatter::currency((float) $record->target_amount)
                    : null)
                ->placeholder('No target'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('investment_window')
                ->label('Window')
                ->state(fn (InvestmentProject $record): ?string => $record->investmentWindowLabel())
                ->badge()
                ->color(fn (InvestmentProject $record): string => $record->isWithinInvestmentWindow() ? 'success' : 'danger')
                ->placeholder('-'),
        ])->filters([
            SelectFilter::make('group_id')
                ->label(__('Group'))
                ->options(fn (): array => InvestorGroup::query()->orderBy('id')->pluck('name', 'id')->all()),
        ])->recordActions([ViewAction::make(), EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    private static function fundingProgress(InvestmentProject $record): ?float
    {
        $target = (float) $record->target_amount;

        if ($target <= 0) {
            return null;
        }

        $invested = array_key_exists('investments_sum_amount', $record->getAttributes())
            ? (float) ($record->investments_sum_amount ?? 0)
            : $record->totalInvested();

        return round(($invested / $target) * 100, 1);
    }

    private static function fundingProgressColor(?float $progress): string
    {
        return match (true) {
            $progress === null => 'gray',
            $progress >= 100 => 'success',
            $progress >= 75 => 'warning',
            default => 'primary',
        };
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof InvestmentProject && $record->status !== 'settled' && (auth()->user()?->hasPermission('investments.manage') ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof InvestmentProject && ! $record->investments()->exists() && (auth()->user()?->hasPermission('investments.manage') ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasPermission('investments.manage') ?? false;
    }

    public static function getRelations(): array
    {
        return [InvestmentsRelationManager::class, CostItemsRelationManager::class, LinkedPurchasesRelationManager::class, MilestonesRelationManager::class, DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestmentProjects::route('/'),
            'create' => CreateInvestmentProject::route('/create'),
            'view' => ViewInvestmentProject::route('/{record}'),
            'edit' => EditInvestmentProject::route('/{record}/edit'),
        ];
    }
}
