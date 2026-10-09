<?php

namespace App\Filament\Concerns;

use App\Services\ExternalCourierFraudService;
use App\Support\FraudCheckReport;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The "type a phone number, see its courier history" screen, shared by the
 * admin Fraud Checker (App\Filament\Pages\FraudChecker) and the reseller
 * portal's (App\Filament\Reseller\Pages\FraudChecker). Both render
 * filament.fraud-check.page; showsStoreData() and canRefresh() are the
 * only differences.
 */
trait ChecksCourierFraud
{
    public ?string $phone = null;

    /** Normalized (01XXXXXXXXX) number the result below belongs to. */
    public ?string $checkedPhone = null;

    /** @var array<string, mixed>|null ExternalCourierFraudService::checkByPhone() output */
    public ?array $result = null;

    public ?string $checkedAt = null;

    /** Valid Bangladeshi mobile number, with or without +88 / 88. */
    public const PHONE_PATTERN = '/^(?:\+?88)?0?1[3-9]\d{8}$/';

    /**
     * "Full report" links from the create-order card open this page with
     * ?phone=…, which checks it straight away (from the 24h cache, so no
     * extra courier lookup).
     */
    public function mount(): void
    {
        $phone = trim((string) request()->query('phone', ''));

        if ($phone !== '' && preg_match(self::PHONE_PATTERN, $phone)) {
            $this->phone = $phone;
            $this->check();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->hiddenLabel()
                    ->tel()
                    ->prefix('+88')
                    ->placeholder('01XXXXXXXXX')
                    ->autofocus()
                    ->required()
                    ->regex(self::PHONE_PATTERN)
                    ->validationMessages([
                        'regex' => __('Enter a valid Bangladeshi mobile number, e.g. 01712345678.'),
                    ]),
            ]);
    }

    /**
     * $refresh skips the 24h cache, same as the order form's manual button,
     * so newly added courier credentials take effect immediately.
     */
    public function check(bool $refresh = false): void
    {
        $this->form->validate();

        $service = app(ExternalCourierFraudService::class);
        $phone = $service->normalizePhone((string) $this->phone);

        $this->result = $service->checkByPhone(
            $phone,
            $this->fraudCheckCompanyId(),
            $this->showsStoreData() ? FraudCheckReport::matchingCustomersQuery($phone)->first()?->getKey() : null,
            bypassCache: $refresh && $this->canRefresh(),
        );
        $this->checkedPhone = $phone;
        $this->checkedAt = now()->toDateTimeString();
    }

    public function refreshCheck(): void
    {
        if ($this->checkedPhone === null || ! $this->canRefresh()) {
            return;
        }

        $this->phone = $this->checkedPhone;
        $this->check(refresh: true);
    }

    public function report(): ?FraudCheckReport
    {
        if ($this->result === null || $this->checkedPhone === null) {
            return null;
        }

        return new FraudCheckReport($this->result, $this->checkedPhone, $this->fraudCheckCompanyId());
    }

    /** Store history, blacklist hits and "set up this courier" links. */
    abstract public function showsStoreData(): bool;

    /** Whether the cache-skipping "Refresh live data" button is offered. */
    abstract public function canRefresh(): bool;

    abstract protected function fraudCheckCompanyId(): int;
}
