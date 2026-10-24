<?php

namespace App\Filament\Ims\Office\Resources\CapitalReturns\Pages;

use App\Filament\Forms\Components\FundSourceSelect;
use App\Filament\Ims\Office\Resources\CapitalReturns\CapitalReturnResource;
use App\Filament\Ims\Office\Widgets\MaturingCapital;
use App\Ims\Capital\CapitalCalculator;
use App\Ims\Capital\CapitalReturnService;
use App\Ims\Capital\MaturityQueue;
use App\Models\Investment;
use App\Models\InvestorGroupMember;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListCapitalReturns extends ListRecords
{
    protected static string $resource = CapitalReturnResource::class;

    protected function getHeaderWidgets(): array
    {
        return [MaturingCapital::class];
    }

    /**
     * "m:12" (a group deposit) or "i:34" (an investment) => label; due ones first.
     *
     * @return array<string, string>
     */
    public static function targets(): array
    {
        $capital = app(CapitalCalculator::class);
        $options = [];

        foreach (app(MaturityQueue::class)->dueWithin(30) as $row) {
            $record = $row['record'];
            $key = ($record instanceof InvestorGroupMember ? 'm:' : 'i:').$record->getKey();
            $options[$key] = __(':name — :amount — matures :date', ['name' => $record->investor?->name, 'amount' => ImsNumbers::money($row['outstanding']), 'date' => ImsNumbers::date($row['maturity_date'])]);
        }

        foreach (InvestorGroupMember::query()->active()->with(['investor', 'group'])->get() as $member) {
            $left = $capital->forMember($member)['outstanding'];
            $options['m:'.$member->getKey()] ??= __(':name — :amount — :place', ['name' => $member->investor?->name, 'amount' => ImsNumbers::money($left), 'place' => $member->group?->name]);
        }

        foreach (Investment::query()->active()->whereNull('group_member_id')->with(['investor', 'project'])->get() as $investment) {
            $left = $capital->forInvestment($investment)['outstanding'];
            $options['i:'.$investment->getKey()] ??= __(':name — :amount — :place', ['name' => $investment->investor?->name, 'amount' => ImsNumbers::money($left), 'place' => $investment->project?->name]);
        }

        return $options;
    }

    public static function resolveTarget(?string $key): Investment|InvestorGroupMember|null
    {
        [$kind, $id] = array_pad(explode(':', (string) $key, 2), 2, null);

        return match ($kind) {
            'm' => InvestorGroupMember::query()->find($id),
            'i' => Investment::query()->find($id),
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payBack')
                ->label(__('Pay back capital'))
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->hasPermission('ims.capital_returns.manage') ?? false)
                ->schema([
                    Select::make('target')->label(__('Whose money'))->options(fn (): array => static::targets())->searchable()->required()->live(),
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required()
                        ->helperText(function (Get $get): ?string {
                            $target = static::resolveTarget($get('target'));

                            if (! $target) {
                                return null;
                            }

                            $f = $target instanceof InvestorGroupMember ? app(CapitalCalculator::class)->forMember($target) : app(CapitalCalculator::class)->forInvestment($target);

                            return __('Invested :amount, loss :loss, already paid back :returned — at most :left now.', ['amount' => ImsNumbers::money($f['amount']), 'loss' => ImsNumbers::money($f['loss']), 'returned' => ImsNumbers::money($f['returned']), 'left' => ImsNumbers::money($f['outstanding'])]);
                        }),
                    DatePicker::make('returned_on')->label(__('Paid on'))->default(now())->maxDate(now())->required(),
                    FundSourceSelect::make('fund_source_id')->label(__('Paid from (fund source)'))->required(),
                    Select::make('method')->label(__('Payment Method'))->options(collect(Investment::PAYMENT_METHODS)->map(fn (string $label): string => __($label))->all())->required(),
                    TextInput::make('reference')->label(__('Payment reference (transaction ID)'))->maxLength(255),
                    FileUpload::make('proof')->label(__('Proof (screenshot or PDF)'))->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->storeFiles(false),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->modalDescription(__('A debit voucher is made in Finance. When nothing is left, the investment is marked paid back.'))
                ->action(function (array $data): void {
                    $target = static::resolveTarget($data['target'] ?? null);
                    abort_if($target === null, 404);

                    app(CapitalReturnService::class)->record($target, $data, auth()->user(), $data['proof'] ?? null);
                    Notification::make()->title(__('Capital paid back'))->success()->send();
                }),
        ];
    }
}
