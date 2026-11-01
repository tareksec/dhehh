<?php

namespace App\Filament\Resources\Resellers;

use App\Filament\Clusters\Resellers;
use App\Filament\Concerns\TrashFollowsDelete;
use App\Filament\Resources\Resellers\Pages\EditReseller;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Filament\Resources\Resellers\Pages\TrashedResellers;
use App\Filament\Resources\Resellers\Pages\ViewReseller;
use App\Filament\Resources\Resellers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Resellers\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Resellers\Schemas\ResellerForm;
use App\Filament\Resources\Resellers\Schemas\ResellerInfolist;
use App\Filament\Resources\Resellers\Tables\ResellersTable;
use App\Models\Customer;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Deliberately a *separate* Resource from CustomerResource, not just a
 * filtered view -- same underlying Customer model, but the owner wants
 * resellers fully out of the regular Customers list once they exist here
 * (see CustomerResource::getEloquentQuery(), narrowed to reseller_status
 * = 'none' as the counterpart to this resource's != 'none').
 */
class ResellerResource extends Resource
{
    use TrashFollowsDelete;

    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $cluster = Resellers::class;

    protected static ?string $navigationLabel = 'All Resellers';

    protected static ?string $modelLabel = 'Reseller';

    protected static ?string $pluralModelLabel = 'Resellers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'resellers';

    public static function form(Schema $schema): Schema
    {
        return ResellerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResellerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResellersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellers::route('/'),
            'trash' => TrashedResellers::route('/trash'),
            'view' => ViewReseller::route('/{record}'),
            'edit' => EditReseller::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        if (! (Auth::user()?->canPerformModelAbility('viewAny', Customer::class) ?? false)) {
            return false;
        }

        return static::resellerModuleEnabledForCurrentContext();
    }

    public static function canCreate(): bool
    {
        // Resellers only ever originate from the storefront apply form
        // (App\Http\Controllers\Storefront\ResellerController) -- staff
        // approve/reject existing applications, they don't create new ones.
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('reseller_status', '!=', 'none');

        return static::resellerModuleEnabledForCurrentContext() ? $query : $query->whereRaw('1 = 0');
    }

    public static function resellerModuleEnabledForCurrentContext(): bool
    {
        $context = app(CompanyContext::class);

        if ($context->isAllCompanies()) {
            // A super admin auditing across every company isn't blocked by
            // any single company's toggle -- the toggle controls whether
            // that company's own staff see the module, not super-admin reach.
            return true;
        }

        return (bool) $context->company()?->reseller_module_enabled;
    }
}
