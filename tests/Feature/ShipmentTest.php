<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Container;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Add shipment plan" never created a shipment: Shipment::booted()'s
 * `saving` hook compared $shipment->company_id against the container's/
 * purchase's company_id — but on a brand-new record `saving` fires before
 * BelongsToCompany's `creating` hook has filled in company_id (see
 * Model::save()/performInsert()), so that comparison always read null and
 * rejected every single creation with "Purchase must belong to the same
 * company."
 */
class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    private function makePurchase(): Purchase
    {
        $supplier = Supplier::query()->create(['name' => 'Shipment Test Supplier']);

        return Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-01-01',
            'status' => 'draft',
        ]);
    }

    public function test_a_shipment_plan_can_be_created_for_a_draft_purchase(): void
    {
        $purchase = $this->makePurchase();

        $shipment = $purchase->shipments()->create([
            'tracking_number' => 'TRK-1001',
            'transport_mode' => 'sea',
            'status' => 'planned',
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'purchase_id' => $purchase->id,
            'tracking_number' => 'TRK-1001',
        ]);
        $this->assertSame($purchase->company_id, $shipment->company_id);
    }

    public function test_a_shipment_plan_with_a_container_can_be_created(): void
    {
        $purchase = $this->makePurchase();
        $container = Container::query()->create(['container_number' => 'CONT-1']);

        $shipment = $purchase->shipments()->create([
            'tracking_number' => 'TRK-1002',
            'container_id' => $container->id,
            'transport_mode' => 'sea',
            'status' => 'planned',
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'container_id' => $container->id,
        ]);
    }

    public function test_shipment_creation_is_still_rejected_for_a_container_from_another_company(): void
    {
        $purchase = $this->makePurchase();

        $otherCompany = Company::query()->create([
            'name' => 'Other Co', 'slug' => 'other-co-shipment-test', 'invoice_prefix' => 'OTS',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $foreignContainer = Container::query()->create(['company_id' => $otherCompany->id, 'container_number' => 'CONT-FOREIGN']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Container must belong to the same company.');

        $purchase->shipments()->create([
            'tracking_number' => 'TRK-1003',
            'container_id' => $foreignContainer->id,
            'transport_mode' => 'sea',
            'status' => 'planned',
        ]);
    }

    public function test_shipment_creation_is_still_rejected_for_a_purchase_from_another_company(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Cross-Company Supplier']);
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-01-01',
            'status' => 'draft',
        ]);

        $otherCompany = Company::query()->create([
            'name' => 'Other Co 2', 'slug' => 'other-co-2-shipment-test', 'invoice_prefix' => 'OT2',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Purchase must belong to the same company.');

        // company_id set explicitly (mimics an admin operating under a
        // different active company than the purchase belongs to) rather
        // than left to BelongsToCompany's default resolution.
        $purchase->shipments()->create([
            'company_id' => $otherCompany->id,
            'tracking_number' => 'TRK-1004',
            'transport_mode' => 'sea',
            'status' => 'planned',
        ]);
    }
}
