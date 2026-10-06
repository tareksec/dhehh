<?php

namespace App\Filament\Resources\UserRoles;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\UserRoles\Pages\CreateUserRole;
use App\Filament\Resources\UserRoles\Pages\EditUserRole;
use App\Filament\Resources\UserRoles\Pages\ListUserRoles;
use App\Filament\Resources\UserRoles\Schemas\UserRoleForm;
use App\Filament\Resources\UserRoles\Tables\UserRolesTable;
use App\Models\User;
use App\Models\UserRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UserRoleResource extends Resource
{
    protected static ?string $model = UserRole::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'User Role';

    protected static ?string $pluralModelLabel = 'User Roles';

    /**
     * Managed from the Users page ("Manage Roles" header action) rather than
     * its own sidebar entry — still fully routable/accessible from there.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        // Editing what a role may do is changing permissions: Super Admin only.
        return Auth::user() instanceof User && Auth::user()->canChangePermissions();
    }

    public static function form(Schema $schema): Schema
    {
        return UserRoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserRolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserRoles::route('/'),
            'create' => CreateUserRole::route('/create'),
            'edit' => EditUserRole::route('/{record}/edit'),
        ];
    }
}
