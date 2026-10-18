<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Reports as ReportsCluster;
use App\Models\Supplier;
use App\Services\SupplierReportService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Supplier-wise report: purchase dates, landed cost, sales, damaged and short
 * units, gross and net profit — for one product or all of a supplier's
 * products, with a one-click PDF (SupplierReportService documents the rules).
 */
class SupplierReport extends Page
{
    protected static ?string $cluster = ReportsCluster::class;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected string $view = 'filament.pages.supplier-report';

    public string $supplierId = '';

    public string $productId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public static function getNavigationLabel(): string
    {
        return __('Supplier Report');
    }

    public function getTitle(): string
    {
        return __('Supplier Report');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessReports() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->supplierId = (string) request('supplier', '');
        $this->productId = (string) request('product', '');
        $this->dateFrom = (string) request('date_from', '');
        $this->dateTo = (string) request('date_to', '');
    }

    public function updatedSupplierId(): void
    {
        $this->productId = '';
    }

    public function supplierOptions(): array
    {
        return Supplier::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function productOptions(): array
    {
        $supplier = $this->supplier();

        return $supplier ? app(SupplierReportService::class)->productOptions($supplier) : [];
    }

    public function report(): ?array
    {
        $supplier = $this->supplier();

        if (! $supplier) {
            return null;
        }

        return app(SupplierReportService::class)->build(
            $supplier,
            $this->date($this->dateFrom),
            $this->date($this->dateTo),
            $this->productId !== '' ? (int) $this->productId : null,
        );
    }

    public function pdfUrl(): ?string
    {
        if (! $this->supplier() || ! (Auth::user()?->canExportReports() ?? false)) {
            return null;
        }

        return route('reports.supplier.pdf', array_filter([
            'supplier' => $this->supplierId,
            'product' => $this->productId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ], fn (string $value): bool => $value !== ''));
    }

    public function money(float|int|string|null $amount): string
    {
        return MoneyFormatter::currency($amount);
    }

    public function number(float|int|string|null $amount): string
    {
        return MoneyFormatter::number($amount);
    }

    private function supplier(): ?Supplier
    {
        return ctype_digit($this->supplierId) ? Supplier::query()->find((int) $this->supplierId) : null;
    }

    private function date(string $value): ?Carbon
    {
        try {
            return $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
