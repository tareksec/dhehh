<?php

namespace App\Filament\Resources\StockPools\Pages;

use App\Filament\Resources\StockPools\StockPoolResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockPool;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Superadmin bulk tool for Shared Stock Pools: instead of opening the
 * single-record Create form once per product, pick a source company and a
 * linked company, then match many of the source company's products to their
 * counterpart in the linked company in one screen and link them all with one
 * Save — the same "stage inline, save together" shape as
 * App\Filament\Resources\Products\Pages\BulkUpdateStock.
 *
 * Every actual link goes through StockPoolResource::syncMembers(), the exact
 * same path the Create/Edit form uses, so pool mechanics (stock_pool_id,
 * live stock resync via StockMovementService) are identical. A source
 * product that is already a pool's source just gets the new linked product
 * added to its existing pool.
 */
class BulkLinkStockPools extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = StockPoolResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Bulk Link Products';

    protected string $view = 'filament.resources.stock-pools.pages.bulk-link-stock-pools';

    #[Url]
    public ?int $sourceCompanyId = null;

    #[Url]
    public ?int $targetCompanyId = null;

    /** @var array<int|string, int|string|null> Staged sourceProductId => linkedProductId */
    public array $links = [];

    public static function canAccess(array $parameters = []): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('sourceCompanyId')
                    ->label('Source company (holds the real stock)')
                    ->options(fn (): array => $this->companyOptions())
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetSelection())
                    ->helperText('Usually the wholesale/main company. Its products own the physical inventory.'),
                Select::make('targetCompanyId')
                    ->label('Linked company (sells the same units)')
                    ->options(fn (): array => $this->companyOptions(exclude: $this->sourceCompanyId))
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetSelection()),
            ])
            ->columns(2);
    }

    protected function resetSelection(): void
    {
        $this->links = [];
        $this->resetTable();
    }

    /** @return array<int, string> */
    protected function companyOptions(?int $exclude = null): array
    {
        return Company::query()
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function sourceCompany(): ?Company
    {
        return $this->sourceCompanyId ? Company::query()->find($this->sourceCompanyId) : null;
    }

    public function targetCompany(): ?Company
    {
        return $this->targetCompanyId ? Company::query()->find($this->targetCompanyId) : null;
    }

    protected function bothCompaniesChosen(): bool
    {
        return $this->sourceCompanyId !== null
            && $this->targetCompanyId !== null
            && $this->sourceCompanyId !== $this->targetCompanyId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->sourceProductsQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Source product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->badge(),

                TextColumn::make('current_pool')
                    ->label('Current pool')
                    ->badge()
                    ->color(fn (Product $record): string => $record->stock_pool_id ? 'info' : 'gray')
                    ->state(fn (Product $record): string => $this->currentPoolLabel($record)),

                SelectColumn::make('match')
                    ->label(fn (): string => 'Link to '.($this->targetCompany()?->name ?? 'linked company'))
                    ->options(fn (): array => $this->targetProductOptions())
                    ->searchableOptions()
                    ->selectablePlaceholder(true)
                    ->getStateUsing(fn (Product $record): int|string|null => $this->links[$record->getKey()] ?? null)
                    ->updateStateUsing(function (Product $record, mixed $state): int|string|null {
                        if (blank($state)) {
                            unset($this->links[$record->getKey()]);

                            return null;
                        }

                        return $this->links[$record->getKey()] = (int) $state;
                    })
                    ->disabled(fn (Product $record): bool => $record->stock_pool_id !== null && ! $this->isPoolSource($record)),
            ])
            ->searchPlaceholder('Search source product name or SKU...')
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->emptyStateHeading($this->bothCompaniesChosen() ? 'No linkable products' : 'Choose both companies')
            ->emptyStateDescription($this->bothCompaniesChosen()
                ? 'This source company has no simple (non-variant) products to link.'
                : 'Pick a source company and a linked company above to start matching products.')
            ->emptyStateIcon(Heroicon::OutlinedSquares2x2);
    }

    protected function sourceProductsQuery(): Builder
    {
        if (! $this->bothCompaniesChosen()) {
            return Product::query()->withoutGlobalScopes()->whereRaw('1 = 0');
        }

        return Product::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_id', $this->sourceCompanyId)
            ->where('has_variants', false)
            ->with(['stockPool', 'company']);
    }

    /** @return array<int, string> */
    protected function targetProductOptions(): array
    {
        if (! $this->bothCompaniesChosen()) {
            return [];
        }

        return Product::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_id', $this->targetCompanyId)
            ->where('has_variants', false)
            ->whereNull('stock_pool_id')
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} (SKU: {$product->sku})",
            ])
            ->all();
    }

    protected function currentPoolLabel(Product $record): string
    {
        if (! $record->stock_pool_id) {
            return 'Not pooled';
        }

        $others = StockPoolResource::memberProducts($record->stockPool)
            ->reject(fn (Product $member): bool => (int) $member->getKey() === (int) $record->getKey())
            ->map(fn (Product $member): ?string => $member->company?->name)
            ->filter()
            ->unique()
            ->sort()
            ->implode(', ');

        $prefix = $this->isPoolSource($record) ? 'Source' : 'Member';

        return $others !== '' ? "{$prefix} · {$others}" : $prefix;
    }

    protected function isPoolSource(Product $record): bool
    {
        return $record->stock_pool_id !== null
            && (int) $record->stockPool?->source_product_id === (int) $record->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suggestMatches')
                ->label('Suggest matches')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->bothCompaniesChosen())
                ->action(fn () => $this->suggestMatches()),

            Action::make('saveLinks')
                ->label('Save links')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => $this->bothCompaniesChosen())
                ->requiresConfirmation()
                ->modalDescription('Link every product with a chosen match into a shared stock pool. Their stock will immediately show the same live pool-wide quantity in both companies.')
                ->action(fn () => $this->saveLinks()),
        ];
    }

    public function suggestMatches(): void
    {
        abort_unless(self::canAccess(), 403);

        if (! $this->bothCompaniesChosen()) {
            return;
        }

        $targets = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->targetCompanyId)
            ->where('has_variants', false)
            ->whereNull('stock_pool_id')
            ->get(['id', 'name', 'sku']);

        $bySku = $targets->filter(fn (Product $p): bool => filled($p->sku))
            ->keyBy(fn (Product $p): string => mb_strtolower(trim((string) $p->sku)));
        $byName = $targets->keyBy(fn (Product $p): string => mb_strtolower(trim((string) $p->name)));

        $suggested = 0;

        $this->sourceProductsQuery()->get(['id', 'name', 'sku'])->each(function (Product $source) use ($bySku, $byName, &$suggested): void {
            if (isset($this->links[$source->getKey()])) {
                return;
            }

            $match = $bySku->get(mb_strtolower(trim((string) $source->sku)))
                ?? $byName->get(mb_strtolower(trim((string) $source->name)));

            if ($match) {
                $this->links[$source->getKey()] = $match->getKey();
                $suggested++;
            }
        });

        $this->resetTable();

        Notification::make()
            ->title($suggested > 0 ? "Suggested {$suggested} match(es)" : 'No exact SKU or name matches found')
            ->body($suggested > 0 ? 'Review each row, then Save links.' : 'Pick matches manually, then Save links.')
            ->{$suggested > 0 ? 'success' : 'info'}()
            ->send();
    }

    public function saveLinks(): void
    {
        abort_unless(self::canAccess(), 403);

        $pairs = collect($this->links)
            ->reject(fn (mixed $targetId): bool => blank($targetId))
            ->map(fn (mixed $targetId): int => (int) $targetId)
            ->all();

        if ($pairs === []) {
            Notification::make()
                ->title('Nothing to link')
                ->body('Choose a linked product for at least one row first.')
                ->warning()
                ->send();

            return;
        }

        $linked = 0;
        $skipped = 0;

        DB::transaction(function () use ($pairs, &$linked, &$skipped): void {
            foreach ($pairs as $sourceId => $targetId) {
                $source = Product::query()->withoutGlobalScopes()->withoutTrashed()->find($sourceId);
                $target = Product::query()->withoutGlobalScopes()->withoutTrashed()->find($targetId);

                if (
                    ! $source || ! $target
                    || $source->has_variants || $target->has_variants
                    || (int) $source->company_id !== (int) $this->sourceCompanyId
                    || (int) $target->company_id !== (int) $this->targetCompanyId
                    || ($target->stock_pool_id !== null && $target->stock_pool_id !== $source->stock_pool_id)
                ) {
                    $skipped++;

                    continue;
                }

                if ($source->stock_pool_id) {
                    $pool = StockPool::query()->find($source->stock_pool_id);

                    if (! $pool || (int) $pool->source_product_id !== (int) $source->getKey()) {
                        $skipped++;

                        continue;
                    }

                    $existingMembers = Product::query()
                        ->withoutGlobalScopes()
                        ->where('stock_pool_id', $pool->getKey())
                        ->where('id', '!=', $pool->source_product_id)
                        ->pluck('id')
                        ->all();

                    StockPoolResource::syncMembers($pool, array_values(array_unique([...$existingMembers, (int) $targetId])));
                } else {
                    $pool = StockPool::query()->create(['source_product_id' => $source->getKey()]);
                    StockPoolResource::syncMembers($pool, [(int) $targetId]);
                }

                $linked++;
            }
        });

        $this->links = [];
        $this->resetTable();

        Notification::make()
            ->title($linked > 0 ? "Linked {$linked} product(s)" : 'No products linked')
            ->body($skipped > 0 ? "{$skipped} row(s) skipped (already pooled elsewhere or no longer eligible)." : null)
            ->{$linked > 0 ? 'success' : 'warning'}()
            ->send();
    }
}
