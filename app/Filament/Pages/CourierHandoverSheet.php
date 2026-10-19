<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Courier;
use App\Models\CourierProvider;
use App\Services\CompanyContext;
use App\Services\CourierHandoverSheetService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Parcels booked through one courier on one day, with the cash to collect
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.8). The print view is a separate
 * page so the rider gets a clean sheet.
 */
class CourierHandoverSheet extends Page
{
    protected static ?string $cluster = Courier::class;

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected string $view = 'filament.pages.courier-handover-sheet';

    public ?int $providerId = null;

    public string $date = '';

    public static function getNavigationLabel(): string
    {
        return __('Handover sheet');
    }

    public function getTitle(): string
    {
        return __('Courier handover sheet');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('sales.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $company = app(CompanyContext::class)->company();
        $this->date = now($company?->timezone ?: 'Asia/Dhaka')->toDateString();
        $this->providerId = $this->providers()->first()?->getKey();
    }

    /** @return Collection<int, CourierProvider> */
    public function providers()
    {
        return CourierProvider::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @return array<string, mixed>|null */
    public function sheet(): ?array
    {
        $provider = $this->providerId ? CourierProvider::query()->find($this->providerId) : null;

        return $provider ? app(CourierHandoverSheetService::class)->build($provider, $this->date ?: null) : null;
    }

    public function printUrl(): ?string
    {
        return $this->providerId
            ? route('courier.handover-sheet', ['provider' => $this->providerId, 'date' => $this->date ?: null, 'print' => 1])
            : null;
    }
}
