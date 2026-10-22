<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel is a Livewire SPA. A link to a file that Filament renders
 * with `wire:navigate` is fetched and painted into the page, so the owner saw
 * the raw bytes of a report PDF as text (2026-10-19). Download links must be
 * plain links.
 */
class ReportDownloadLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_csv_and_pdf_buttons_are_plain_links(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get('/admin/reports/reports?report_type=sales&date_from=2026-06-03&date_to=2026-06-03')
            ->assertOk()
            ->getContent();

        $csv = $this->anchorFor($html, 'reports/export/sales?');
        $pdf = $this->anchorFor($html, 'reports/export/sales/pdf?');

        $this->assertNotNull($csv, 'CSV link not found');
        $this->assertNotNull($pdf, 'PDF link not found');
        $this->assertStringNotContainsString('wire:navigate', $csv);
        $this->assertStringNotContainsString('wire:navigate', $pdf);
    }

    public function test_supplier_report_pdf_button_is_a_plain_link(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Link Supplier', 'opening_balance' => 0, 'is_active' => true]);

        $html = $this->actingAs(User::factory()->create())
            ->get('/admin/reports/supplier-report?supplier='.$supplier->id)
            ->assertOk()
            ->getContent();

        $pdf = $this->anchorFor($html, 'reports/supplier/'.$supplier->id.'/pdf');

        $this->assertNotNull($pdf, 'PDF link not found');
        $this->assertStringNotContainsString('wire:navigate', $pdf);
    }

    public function test_ordinary_admin_links_still_use_spa_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/reports/reports')
            ->assertOk()
            ->assertSee('wire:navigate', false);
    }

    private function anchorFor(string $html, string $hrefPart): ?string
    {
        preg_match_all('/<a\b[^>]*>/i', $html, $matches);

        foreach ($matches[0] as $anchor) {
            if (str_contains($anchor, $hrefPart)) {
                return $anchor;
            }
        }

        return null;
    }
}
