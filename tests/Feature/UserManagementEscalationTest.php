<?php

namespace Tests\Feature;

use App\Filament\Resources\UserRoles\UserRoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Models\UserRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's rule: only a Super Admin changes permissions (any user's role, their
 * own included, and what a role may do). A custom role holding "Users: Manage"
 * also must not take over a Super Admin account.
 */
class UserManagementEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_user_manager_cannot_open_a_super_admins_edit_page(): void
    {
        $manager = $this->userManager();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($manager)
            ->get("/admin/settings/users/{$superAdmin->getKey()}/edit")
            ->assertForbidden();

        $this->actingAs($manager)
            ->get("/admin/settings/users/{$superAdmin->getKey()}")
            ->assertOk();
    }

    public function test_only_a_super_admin_can_open_the_role_permissions_screen(): void
    {
        $url = UserRoleResource::getUrl('index');

        $this->actingAs($this->userManager())->get($url)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get($url)
            ->assertOk();
    }

    public function test_a_user_manager_cannot_promote_themselves_to_super_admin(): void
    {
        $manager = $this->userManager();

        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $manager->getKey()])
            ->fillForm(['role' => 'super_admin'])
            ->call('save');

        $this->assertSame('user_manager', $manager->fresh()->role);
    }

    public function test_a_user_manager_cannot_switch_to_another_role_either(): void
    {
        $manager = $this->userManager();
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $manager->getKey()])
            ->fillForm(['role' => 'manager'])
            ->call('save');

        $this->assertSame('user_manager', $manager->fresh()->role);
    }

    public function test_a_user_created_by_a_user_manager_gets_the_default_role(): void
    {
        $this->actingAs($this->userManager());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sneaky Admin',
                'email' => 'sneaky@example.test',
                'role' => 'super_admin',
                'is_active' => true,
                'password' => 'Sneaky-Password-123',
            ])
            ->call('create');

        $this->assertSame('sales_staff', User::query()->where('email', 'sneaky@example.test')->value('role'));
    }

    public function test_a_user_manager_cannot_bulk_delete_a_super_admin(): void
    {
        $this->actingAs($this->userManager());
        // Two, so the existing "last active Super Admin" rule isn't what saves it.
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$superAdmin, $staff]);

        $this->assertModelExists($superAdmin);
        $this->assertModelMissing($staff);
    }

    public function test_a_user_manager_can_edit_a_normal_user_but_not_their_role(): void
    {
        $this->actingAs($this->userManager());
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['role' => 'inventory_staff', 'password' => 'New-Staff-Password-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sales_staff', $staff->fresh()->role);
        $this->assertTrue(Hash::check('New-Staff-Password-123', $staff->fresh()->password));
    }

    public function test_a_super_admin_can_still_promote_a_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['role' => 'super_admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('super_admin', $staff->fresh()->role);
    }

    private function userManager(): User
    {
        UserRole::query()->updateOrCreate(['slug' => 'user_manager'], [
            'name' => 'User Manager',
            'permissions' => ['dashboard.view', 'users.manage'],
            'is_active' => true,
        ]);

        return User::factory()->create(['role' => 'user_manager', 'is_active' => true]);
    }
}
