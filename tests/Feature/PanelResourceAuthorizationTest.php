<?php

namespace Tests\Feature;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Company;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Voucher;
use App\Services\CompanyContext;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Broken function-level authorization: a Filament resource whose model is not in
 * User::MODEL_MODULES and that sets no access rules of its own is open to every
 * staff member (Gate::before returns null, and a resource without a policy reads
 * that as "allowed"). The CRM leads, quotations, quick replies and FAQs, and the
 * shipments and containers, were open to roles that have no CRM / purchasing
 * permission at all.
 */
class PanelResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Panel Co', 'slug' => 'panel-co', 'invoice_prefix' => 'PN',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    /** @return array<string, array{string}> */
    public static function crmUrls(): array
    {
        return [
            'leads' => ['/admin/crm/leads'],
            'new lead' => ['/admin/crm/leads/create'],
            'quotations' => ['/admin/crm/quotations'],
            'new quotation' => ['/admin/crm/quotations/create'],
            'quick replies' => ['/admin/crm/quick-replies'],
            'new quick reply' => ['/admin/crm/quick-replies/create'],
            'FAQs' => ['/admin/crm/company-faqs'],
            'new FAQ' => ['/admin/crm/company-faqs/create'],
        ];
    }

    #[DataProvider('crmUrls')]
    public function test_roles_without_crm_permissions_cannot_open_the_crm_resources(string $url): void
    {
        foreach (['accountant', 'inventory_staff'] as $role) {
            $this->actingAs($this->staff($role))->get($url)->assertForbidden();
        }
    }

    #[DataProvider('crmUrls')]
    public function test_roles_with_crm_permissions_keep_their_access(string $url): void
    {
        foreach (['sales_staff', 'manager', 'super_admin'] as $role) {
            $this->actingAs($this->staff($role))->get($url)->assertOk();
        }
    }

    public function test_a_view_only_crm_role_can_read_but_not_change(): void
    {
        UserRole::query()->create([
            'name' => 'CRM Reader', 'slug' => 'crm_reader', 'is_active' => true,
            'permissions' => ['dashboard.view', 'crm.view'],
        ]);
        $reader = $this->staff('crm_reader');

        $this->actingAs($reader);

        foreach (['/admin/crm/leads', '/admin/crm/quotations', '/admin/crm/quick-replies', '/admin/crm/company-faqs'] as $url) {
            $this->get($url)->assertOk();
            $this->get($url.'/create')->assertForbidden();
        }
    }

    public function test_lead_activities_follow_the_lead_permissions(): void
    {
        UserRole::query()->create([
            'name' => 'CRM Reader', 'slug' => 'crm_reader', 'is_active' => true,
            'permissions' => ['dashboard.view', 'crm.view'],
        ]);
        $manager = new ActivitiesRelationManager;

        $this->actingAs($this->staff('crm_reader'));
        $this->assertTrue($manager->getAuthorizationResponse('viewAny')->allowed());
        foreach (['create', 'update', 'delete', 'deleteAny'] as $ability) {
            $this->assertFalse($manager->getAuthorizationResponse($ability)->allowed(), "view-only role must not be allowed to {$ability} an activity");
        }

        $this->actingAs($this->staff('accountant'));
        $this->assertFalse($manager->getAuthorizationResponse('viewAny')->allowed());

        $this->actingAs($this->staff('sales_staff'));
        foreach (['viewAny', 'create', 'update', 'delete'] as $ability) {
            $this->assertTrue($manager->getAuthorizationResponse($ability)->allowed(), "a CRM manager must be allowed to {$ability} an activity");
        }
    }

    public function test_an_unknown_ability_is_refused_not_allowed(): void
    {
        $this->actingAs($this->staff('super_admin'));

        $this->assertFalse(LeadResource::permits('somethingNew'));
        $this->assertTrue(LeadResource::permits('viewAny'));
    }

    public function test_shipments_and_containers_follow_the_purchasing_permissions(): void
    {
        // Accountant and inventory staff may view purchasing, not create in it.
        foreach (['accountant', 'inventory_staff'] as $role) {
            $user = $this->staff($role);
            foreach (['/admin/shipments', '/admin/containers'] as $url) {
                $this->actingAs($user)->get($url)->assertOk();
                $this->actingAs($user)->get($url.'/create')->assertForbidden();
            }
        }

        // Sales staff have no purchasing permission at all.
        foreach (['/admin/shipments', '/admin/containers', '/admin/shipments/create', '/admin/containers/create'] as $url) {
            $this->actingAs($this->staff('sales_staff'))->get($url)->assertForbidden();
        }

        foreach (['manager', 'super_admin'] as $role) {
            foreach (['/admin/shipments', '/admin/containers', '/admin/shipments/create', '/admin/containers/create'] as $url) {
                $this->actingAs($this->staff($role))->get($url)->assertOk();
            }
        }
    }

    public function test_a_voucher_can_be_opened_only_by_its_submitter_or_by_those_who_may_view_all(): void
    {
        UserRole::query()->create(['name' => 'No Voucher', 'slug' => 'no_voucher', 'is_active' => true, 'permissions' => ['dashboard.view']]);

        $owner = $this->staff('sales_staff');       // voucher.view: own vouchers only
        $colleague = $this->staff('inventory_staff'); // voucher.view: own vouchers only
        $accountant = $this->staff('accountant');   // voucher.view_all
        $nobody = $this->staff('no_voucher');       // no voucher permission at all

        $voucher = Voucher::query()->create([
            'voucher_number' => 'DV-OWN-1',
            'type' => Voucher::TYPE_DEBIT,
            'status' => Voucher::STATUS_PENDING,
            'transaction_type' => 'other',
            'amount' => 10,
            'submitted_by' => $owner->getKey(),
        ]);
        $url = '/admin/finance/vouchers/'.$voucher->getKey();

        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($accountant)->get($url)->assertOk();
        $this->actingAs($colleague)->get($url)->assertForbidden();
        $this->actingAs($nobody)->get($url)->assertForbidden();
        $this->actingAs($nobody)->get('/admin/finance/vouchers')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/finance/vouchers/create')->assertForbidden();

        $this->actingAs($colleague)->get('/admin/finance/vouchers')->assertOk();
    }

    public function test_exchange_rates_are_for_those_who_may_view_investments(): void
    {
        UserRole::query()->create(['name' => 'No Invest', 'slug' => 'no_invest', 'is_active' => true, 'permissions' => ['dashboard.view']]);
        UserRole::query()->create(['name' => 'Invest Reader', 'slug' => 'invest_reader', 'is_active' => true, 'permissions' => ['dashboard.view', 'investments.view']]);

        $this->actingAs($this->staff('no_invest'))->get('/admin/investments/exchange-rates')->assertForbidden();
        $this->actingAs($this->staff('no_invest'))->get('/admin/investments/exchange-rates/create')->assertForbidden();

        $reader = $this->staff('invest_reader');
        $this->actingAs($reader)->get('/admin/investments/exchange-rates')->assertOk();
        $this->actingAs($reader)->get('/admin/investments/exchange-rates/create')->assertForbidden();

        $this->actingAs($this->staff('manager'))->get('/admin/investments/exchange-rates/create')->assertOk();
    }

    /**
     * Guard for the future: a new Filament resource must either list its model in
     * User::MODEL_MODULES or set its own access rules (canAccess / canViewAny /
     * getAuthorizationResponse, e.g. through AuthorizesByPermission).
     */
    public function test_every_filament_resource_has_access_rules(): void
    {
        // Known gap, owner decision pending (docs/security-audit-2026-11.md, R11):
        // any staff member may upload and delete the company's media library.
        $knownOpen = [MediaResource::class];

        $unprotected = [];

        foreach ($this->resourceClasses() as $class) {
            if (isset(User::MODEL_MODULES[$class::getModel()])) {
                continue;
            }

            if (! $this->definesAccessRules(new ReflectionClass($class))) {
                $unprotected[] = $class;
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($unprotected, $knownOpen)),
            'These Filament resources are open to every staff member. Add the model to User::MODEL_MODULES, or use the AuthorizesByPermission concern / define canAccess().',
        );

        foreach ($knownOpen as $class) {
            $this->assertContains($class, $unprotected, "{$class} now has access rules - remove it from the known-open list.");
        }
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        // The factory also attaches the seeded first company; keep only this test's
        // company, as the default, so records created in it are the ones the request sees.
        $user->companies()->sync([$this->company->getKey() => ['is_default' => true]]);

        return $user;
    }

    /** @return list<class-string<resource>> */
    private function resourceClasses(): array
    {
        $classes = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            $class = 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isSubclassOf(Resource::class) && ! $reflection->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function definesAccessRules(ReflectionClass $resource): bool
    {
        foreach (['canAccess', 'canViewAny', 'getAuthorizationResponse', 'getViewAnyAuthorizationResponse'] as $method) {
            if ($resource->hasMethod($method) && str_starts_with($resource->getMethod($method)->getDeclaringClass()->getName(), 'App\\')) {
                return true;
            }
        }

        return false;
    }
}
