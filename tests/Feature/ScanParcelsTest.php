<?php

namespace Tests\Feature;

use App\Filament\Pages\ScanParcels;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 7: scan invoice numbers, move the batch through the order workflow. */
class ScanParcelsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Scan Co', 'slug' => 'scan-co', 'invoice_prefix' => 'SC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))->withSession(['current_company_id' => $this->company->id]);
    }

    private function order(string $status = Order::STATUS_CONFIRMED): Order
    {
        $customer = Customer::query()->create(['name' => 'Rina', 'phone' => '01711000000', 'opening_balance' => 0, 'is_active' => true]);

        return Order::query()->create(['customer_id' => $customer->id, 'customer_name' => 'Rina', 'status' => $status, 'source' => Order::SOURCE_ADMIN]);
    }

    public function test_scanned_numbers_build_a_list_and_one_press_moves_them_all(): void
    {
        $first = $this->order();
        $second = $this->order(Order::STATUS_PROCESSING);

        Livewire::test(ScanParcels::class)
            ->set('code', strtolower($first->order_number))->call('scan')
            ->set('code', $second->order_number)->call('scan')
            ->assertSet('ids', [$second->id, $first->id])
            ->assertSee($first->order_number)
            ->set('stage', Order::STAGE_SHIPPING)
            ->call('move')
            ->assertSet('ids', [])
            ->assertSet('skipped', []);

        $this->assertSame('shipping', $first->fresh()->workflowStage());
        $this->assertSame('shipping', $second->fresh()->workflowStage());
        $this->assertSame(1, $first->fresh()->statusTransitions()->count());
    }

    public function test_an_unknown_or_repeated_number_is_not_added(): void
    {
        $order = $this->order();

        Livewire::test(ScanParcels::class)
            ->set('code', 'NO-SUCH-ORDER')->call('scan')
            ->assertSet('ids', [])
            ->set('code', $order->order_number)->call('scan')
            ->set('code', $order->order_number)->call('scan')
            ->assertSet('ids', [$order->id]);
    }

    public function test_an_order_that_cannot_make_the_move_is_skipped_with_its_reason(): void
    {
        $draft = $this->order(Order::STATUS_DRAFT);
        $ready = $this->order();

        Livewire::test(ScanParcels::class)
            ->set('code', $draft->order_number)->call('scan')
            ->set('code', $ready->order_number)->call('scan')
            ->set('stage', Order::STAGE_SHIPPING)
            ->call('move')
            ->assertSet('ids', [$draft->id])
            ->assertCount('skipped', 1);

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('shipping', $ready->fresh()->workflowStage());
    }

    public function test_a_stage_that_needs_a_reason_cannot_be_chosen(): void
    {
        $order = $this->order();

        Livewire::test(ScanParcels::class)
            ->set('code', $order->order_number)->call('scan')
            ->set('stage', Order::STAGE_CANCELLED)
            ->call('move');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_another_companys_order_number_is_not_found(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->order();
        app(CompanyContext::class)->set($this->company);

        Livewire::test(ScanParcels::class)->set('code', $theirs->order_number)->call('scan')->assertSet('ids', []);
    }

    public function test_only_people_who_can_update_sales_may_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]));
        $this->assertFalse(ScanParcels::canAccess());
    }
}
