<?php

namespace Tests\Feature;

use App\Filament\Resources\UserRoles\UserRoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
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
 * also must not take over a Super Admin account, or give anyone access to a
 * company the manager can't access themselves (owner's rule, 2026-10-07).
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

    public function test_a_user_manager_only_sees_their_own_companies_in_the_form(): void
    {
        [$own, $other] = [$this->company('Own Company'), $this->company('Hidden Company')];

        $this->actingAs($this->userManager($own))
            ->get('/admin/settings/users/create')
            ->assertOk()
            ->assertSee('Own Company')
            ->assertDontSee('Hidden Company');
    }

    public function test_a_user_manager_cannot_add_a_new_user_to_another_company(): void
    {
        [$own, $other] = [$this->company('Own Company'), $this->company('Other Company')];
        $this->actingAs($this->userManager($own));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'new-staff@example.test',
                'is_active' => true,
                'password' => 'New-Staff-Password-123',
                'company_ids' => [$own->getKey(), $other->getKey()],
                'default_company_id' => $other->getKey(),
            ])
            ->call('create');

        // A hand-crafted request naming another company is rejected outright.
        $this->assertFalse(
            User::query()->where('email', 'new-staff@example.test')
                ->whereHas('companies', fn ($query) => $query->whereKey($other->getKey()))
                ->exists(),
        );
    }

    public function test_a_user_manager_cannot_add_or_remove_another_companys_access(): void
    {
        [$own, $other, $third] = [$this->company('Own Company'), $this->company('Other Company'), $this->company('Third Company')];
        $this->actingAs($this->userManager($own));

        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $staff->companies()->sync([
            $own->getKey() => ['role' => 'sales_staff', 'is_default' => false],
            $other->getKey() => ['role' => 'sales_staff', 'is_default' => true],
        ]);

        // A normal save: the form only holds Own, and Other is kept.
        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->assertFormSet(['company_ids' => [$own->getKey()], 'default_company_id' => null])
            ->fillForm(['name' => 'Renamed Staff'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Staff', $staff->fresh()->name);
        $this->assertEqualsCanonicalizing(
            [$own->getKey(), $other->getKey()],
            $staff->companies()->pluck('companies.id')->all(),
        );
        $this->assertSame($other->getKey(), (int) $staff->companies()->wherePivot('is_default', true)->value('companies.id'));

        // A hand-crafted request adding Third and moving the default is rejected.
        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'company_ids' => [$own->getKey(), $third->getKey()],
                'default_company_id' => $third->getKey(),
            ])
            ->call('save');

        $this->assertEqualsCanonicalizing(
            [$own->getKey(), $other->getKey()],
            $staff->companies()->pluck('companies.id')->all(),
        );
        $this->assertSame($other->getKey(), (int) $staff->companies()->wherePivot('is_default', true)->value('companies.id'));
    }

    public function test_a_user_manager_can_still_manage_access_to_their_own_companies(): void
    {
        [$own, $second] = [$this->company('Own Company'), $this->company('Second Own Company')];
        $manager = $this->userManager($own);
        $manager->companies()->attach($second, ['role' => 'user_manager', 'is_default' => false]);
        $this->actingAs($manager);

        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $staff->companies()->sync([$own->getKey() => ['role' => 'sales_staff', 'is_default' => true]]);

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'company_ids' => [$second->getKey()],
                'default_company_id' => $second->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$second->getKey()], $staff->companies()->pluck('companies.id')->all());
    }

    public function test_a_super_admin_can_assign_any_company(): void
    {
        [$first, $second] = [$this->company('First Company'), $this->company('Second Company')];
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Livewire::test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'company_ids' => [$first->getKey(), $second->getKey()],
                'default_company_id' => $second->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            $staff->companies()->pluck('companies.id')->all(),
        );
    }

    private function company(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'invoice_prefix' => str($name)->slug('')->substr(0, 8)->upper()->toString(),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }

    private function userManager(?Company $company = null): User
    {
        UserRole::query()->updateOrCreate(['slug' => 'user_manager'], [
            'name' => 'User Manager',
            'permissions' => ['dashboard.view', 'users.manage'],
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['role' => 'user_manager', 'is_active' => true]);
        $manager->companies()->detach();

        if ($company) {
            $manager->companies()->attach($company, ['role' => 'user_manager', 'is_default' => true]);
        }

        return $manager;
    }
}
