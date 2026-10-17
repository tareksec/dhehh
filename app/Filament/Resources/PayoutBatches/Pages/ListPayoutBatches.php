<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Models\FundSource;
use App\Models\InvestorBankAccount;
use App\Services\Investment\PayoutBatchService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ListPayoutBatches extends ListRecords
{
    protected static string $resource = PayoutBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newBatch')
                ->label(__('New payout batch'))
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => auth()->user()?->hasPermission('investments.payout_batch_create') ?? false)
                ->modalWidth('4xl')
                ->modalDescription(__('Pending payouts of confirmed settlements, each sent to the payee\'s approved payment account. Someone other than you approves the batch.'))
                ->schema(function (): array {
                    $payables = app(PayoutBatchService::class)->payables();
                    $label = fn (array $row): string => collect([
                        $row['payee_name'],
                        $row['kind'],
                        $row['project'] ?: null,
                        MoneyFormatter::currency($row['amount']),
                        $row['account'] instanceof InvestorBankAccount ? $row['account']->displayName() : null,
                        $row['reason'],
                    ])->filter()->implode(' · ');

                    return [
                        CheckboxList::make('payables')
                            ->label(__('Payouts to pay'))
                            ->options($payables['eligible']->map($label)->all())
                            ->default($payables['eligible']->keys()->all())
                            ->bulkToggleable()
                            ->required()
                            ->visible($payables['eligible']->isNotEmpty()),
                        Text::make(__('No payout is ready for a batch.'))->visible($payables['eligible']->isEmpty()),
                        Select::make('fund_source_id')
                            ->label(__('Fund source'))
                            ->options(fn (): array => FundSource::query()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText(__('The payout voucher is booked from this fund source. Left empty, the projects\' payout fund source is used when they all share one; otherwise no voucher is booked.')),
                        DatePicker::make('payout_date')->label(__('Payout date'))->default(now()->toDateString()),
                        Textarea::make('notes')->label(__('Notes'))->maxLength(2000),
                        Section::make(__('Left out (:count)', ['count' => $payables['left_out']->count()]))
                            ->schema($payables['left_out']->map(fn (array $row): Text => Text::make($label($row)))->values()->all())
                            ->collapsed()
                            ->visible($payables['left_out']->isNotEmpty()),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $batch = app(PayoutBatchService::class)->create(
                            auth()->user(),
                            array_values($data['payables'] ?? []),
                            filled($data['fund_source_id'] ?? null) ? (int) $data['fund_source_id'] : null,
                            $data['payout_date'] ?? null,
                            $data['notes'] ?? null,
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(__('Could not make the batch'))->body(collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    Notification::make()->success()->title(__(':batch made. Someone else now approves it.', ['batch' => $batch->label()]))->send();
                    $this->redirect(PayoutBatchResource::getUrl('view', ['record' => $batch]));
                }),
        ];
    }
}
