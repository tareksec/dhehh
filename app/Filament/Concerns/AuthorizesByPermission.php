<?php

namespace App\Filament\Concerns;

use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Puts a Filament resource behind the role permissions (`crm.view`,
 * `purchasing.update`, ...).
 *
 * There are no model policies in this app: `Gate::before` decides, and only for
 * the models listed in `User::MODEL_MODULES`. For any other model it returns
 * null, and a Filament resource without a policy reads null as "allowed" - so a
 * resource that neither lists its model there nor sets its own access rules is
 * open to every staff member of the panel, whatever their role (the CRM leads,
 * quotations, quick replies and FAQs were open this way to an accountant).
 *
 * Every Filament ability on the resource (`canViewAny`, `canCreate`,
 * `canEdit`, `canDelete`, `canRestore`, `canReorder`, the `authorize*()` calls
 * that guard its page routes, ...) ends in `getAuthorizationResponse()`, so
 * overriding that one method covers them all. An ability this trait does not
 * know is refused, never allowed.
 *
 * The using resource names one permission per level:
 *
 *     protected static function requiredPermissions(): array
 *     {
 *         return ['view' => 'crm.view', 'create' => 'crm.manage', 'update' => 'crm.manage', 'delete' => 'crm.manage'];
 *     }
 */
trait AuthorizesByPermission
{
    /**
     * @return array{view: string, create: string, update: string, delete: string}
     */
    abstract protected static function requiredPermissions(): array;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return static::permits($action) ? Response::allow() : Response::deny();
    }

    /**
     * Whether the signed-in staff member may perform a Filament ability on this
     * resource. Public so that a relation manager on the resource (which
     * authorizes its own rows) can follow the same rule.
     */
    public static function permits(string|UnitEnum $action): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $name = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };

        $level = match ($name) {
            'viewAny', 'view' => 'view',
            'create', 'replicate' => 'create',
            'update', 'reorder' => 'update',
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => 'delete',
            default => null,
        };

        if ($level === null) {
            return false;
        }

        return $user->hasPermission(static::requiredPermissions()[$level]);
    }
}
