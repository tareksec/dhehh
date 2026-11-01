<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Reports as ReportsCluster;
use App\Models\Product;
use App\Models\Supplier;
use App\Reports\ReportFilters;
use App\Services\SupplierAccountService;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * One product inside a supplier's account: the receipts, the sales by price,
 * the stock and damage, the pieces in the courier pipeline, what the supplier
 * took and the advance he collected. Opened from the product name in the
 * Supplier Account report.
 */
class SupplierProductAccount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $cluster = ReportsCluster::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'supplier-product-account';

    protected string $view = 'filament.pages.supplier-product-account';

    public int $supplierId = 0;

    public int $productId = 0;

    public string $asOf = '';

    /** @var array<string, mixed>|null */
    private ?array $detailCache = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessReports() ?? false;
    }

    public function mount(): void
    {
        $this->supplierId = request()->integer('supplier');
        $this->productId = request()->integer('product');
        $this->asOf = ReportFilters::make(request('as_of'), request('as_of'))->to->toDateString();

        abort_unless($this->supplier() && $this->product(), 404);
    }

    public function getTitle(): string|Htmlable
    {
        return (string) $this->product()?->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __(':supplier, as of :date', [
            'supplier' => (string) $this->supplier()?->name,
            'date' => ReportFilters::make($this->asOf, $this->asOf)->to->format('d M Y'),
        ]);
    }

    public static function urlFor(int $supplierId, int $productId, string $asOf): string
    {
        return static::getUrl(['supplier' => $supplierId, 'product' => $productId, 'as_of' => $asOf]);
    }

    public function backUrl(): string
    {
        return Reports::getUrl([
            'report_type' => 'supplier-account',
            'supplier_id' => $this->supplierId,
            'date_to' => $this->asOf,
        ]);
    }

    public function supplier(): ?Supplier
    {
        return Supplier::query()->find($this->supplierId);
    }

    public function product(): ?Product
    {
        return Product::query()->find($this->productId);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return $this->detailCache ??= app(SupplierAccountService::class)->productDetail(
            $this->supplier(),
            $this->product(),
            ReportFilters::make($this->asOf, $this->asOf)->to,
        );
    }

    public function money(float|int|string|null $amount): string
    {
        return MoneyFormatter::currency($amount);
    }

    public function number(float|int|string|null $amount): string
    {
        return MoneyFormatter::number($amount);
    }
}
