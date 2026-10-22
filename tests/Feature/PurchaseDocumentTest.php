<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\PurchaseWorkflowService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Purchase module automation (v2.12.0): auto-generated, printable
 * Proforma Invoice (PI), Commercial Invoice (CI), and Packing List (PL)
 * documents built from the purchase's own data.
 */
class PurchaseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /**
     * SetCurrentCompany resolves the active company from session on every
     * request (see app/Http/Middleware/SetCurrentCompany.php) — it does not
     * see the in-process CompanyContext::set() a test makes before the HTTP
     * call, and a fresh user's own default company is the pre-seeded "Main
     * Company" from the companies migration, not this test's own company.
     * Attach the user to the purchase's own company and force the session
     * explicitly, matching InvoiceDesignTest's convention.
     */
    private function actingAsFor(User $user, Purchase $purchase): static
    {
        $user->companies()->syncWithoutDetaching([
            $purchase->company_id => ['role' => $user->role, 'is_default' => true],
        ]);

        return $this->actingAs($user)
            ->withSession([
                'current_company_id' => $purchase->company_id,
                'current_company_selection_explicit' => true,
            ]);
    }

    private function makePurchase(): Purchase
    {
        $company = Company::query()->create([
            'name' => 'Tasneem Knitting Industry',
            'slug' => 'tasneem-knitting',
            'invoice_prefix' => 'TKI',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'bin_number' => '006673859-0112',
            'irc_number' => '260326112298025',
        ]);
        app(CompanyContext::class)->set($company);

        $supplier = Supplier::query()->create([
            'name' => 'Ningbo Zhongrui',
            'company_name' => 'Ningbo Zhongrui Import and Export Co., Ltd.',
            'address' => '12/F Changchun Mansion, No.159 Lingqiao Road, Ningbo',
            'country' => 'China',
            'bank_name' => 'Bank of Ningbo',
            'bank_account_number' => '32012025000016105',
            'bank_swift_code' => 'BKNBCN2N',
        ]);

        $product = Product::query()->create([
            'name' => 'Air Fryer SC-880',
            'sku' => 'DOC-PI-001',
            'price' => 8,
            'sale_price' => 8,
            'stock' => 0,
            'hs_code' => '85166000',
        ]);

        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-06-26',
            'delivery_terms' => 'FOB Ningbo',
            'country_of_origin' => 'China',
            'port_of_loading' => 'Any Port From China',
            'port_of_discharge' => 'Chattogram, Bangladesh',
            'freight_usd' => 1200,
            'status' => 'draft',
        ]);

        PurchaseItem::query()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 800,
            'unit_cost' => 640,
            'fob_unit_price_usd' => 8,
            'net_weight_kg' => 7990,
            'gross_weight_kg' => 8410,
        ]);

        return $purchase->refresh();
    }

    public function test_pi_ci_and_pl_documents_stream_as_pdf(): void
    {
        $purchase = $this->makePurchase();

        foreach (['pi', 'ci', 'pl'] as $type) {
            Pdf::shouldReceive('loadView')
                ->once()
                ->with("purchases.documents.{$type}", Mockery::on(fn (array $data) => $data['purchase']->is($purchase)))
                ->andReturnSelf();
            Pdf::shouldReceive('setPaper')->once()->with('a4')->andReturnSelf();
            Pdf::shouldReceive('stream')
                ->once()
                ->with("{$purchase->purchase_number}-{$type}.pdf")
                ->andReturn(response("PDF-{$type}"));

            $this->actingAsFor($this->admin(), $purchase)
                ->get(route('purchases.documents', ['purchase' => $purchase, 'type' => $type]))
                ->assertOk()
                ->assertSee("PDF-{$type}");
        }
    }

    public function test_invalid_document_type_is_not_found(): void
    {
        $purchase = $this->makePurchase();

        $this->actingAsFor($this->admin(), $purchase)
            ->get(route('purchases.documents', ['purchase' => $purchase, 'type' => 'invoice']))
            ->assertNotFound();
    }

    public function test_user_without_purchasing_permission_is_forbidden(): void
    {
        $purchase = $this->makePurchase();

        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAsFor($user, $purchase)
            ->get(route('purchases.documents', ['purchase' => $purchase, 'type' => 'pi']))
            ->assertForbidden();
    }

    public function test_ensure_document_number_fills_blank_number_and_date_only_once(): void
    {
        $purchase = $this->makePurchase();
        $service = app(PurchaseWorkflowService::class);

        $this->assertNull($purchase->pi_number);

        $service->ensureDocumentNumber($purchase, 'pi');
        $purchase->refresh();

        $this->assertSame($purchase->purchase_number.'-PI', $purchase->pi_number);
        $this->assertNotNull($purchase->pi_date);

        $service->ensureDocumentNumber($purchase, 'pi');
        $this->assertSame($purchase->purchase_number.'-PI', $purchase->refresh()->pi_number);
    }

    public function test_ensure_document_number_does_not_overwrite_a_manually_entered_number(): void
    {
        $purchase = $this->makePurchase();
        $purchase->update(['pi_number' => 'MANUAL-PI-1', 'pi_date' => '2026-01-01']);

        app(PurchaseWorkflowService::class)->ensureDocumentNumber($purchase, 'pi');

        $this->assertSame('MANUAL-PI-1', $purchase->refresh()->pi_number);
        $this->assertSame('2026-01-01', $purchase->pi_date->toDateString());
    }

    public function test_purchase_computes_fob_cfr_totals_and_weights_for_documents(): void
    {
        $purchase = $this->makePurchase();

        $this->assertSame(6400.0, $purchase->fobTotalUsd());
        $this->assertSame(7600.0, $purchase->cfrTotalUsd());
        $this->assertSame(800, $purchase->totalQuantity());
        $this->assertSame(7990.0, $purchase->netWeightTotalKg());
        $this->assertSame(8410.0, $purchase->grossWeightTotalKg());
        $this->assertTrue($purchase->hasShippingDetailsForDocuments());
    }
}
