<?php

namespace Tests\Feature;

use App\Http\Middleware\SetStorefrontApiLocale;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorefrontApiLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SetStorefrontApiLocale::class)
            ->get('/_locale-probe', fn () => response()->json(['locale' => app()->getLocale(), 'text' => __('Your cart is empty.')]));
    }

    public function test_the_visitors_language_header_switches_the_messages_the_erp_writes(): void
    {
        $this->getJson('/_locale-probe', ['X-Locale' => 'bn'])
            ->assertOk()
            ->assertJsonPath('locale', 'bn')
            ->assertJsonPath('text', 'আপনার কার্ট খালি।');

        $this->getJson('/_locale-probe', ['X-Locale' => 'en'])
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('text', 'Your cart is empty.');
    }

    public function test_the_lang_query_works_and_unknown_values_keep_the_application_locale(): void
    {
        $this->getJson('/_locale-probe?lang=bn')->assertJsonPath('locale', 'bn');
        $this->getJson('/_locale-probe', ['X-Locale' => 'fr'])->assertJsonPath('locale', config('app.locale'));
        $this->getJson('/_locale-probe')->assertJsonPath('locale', config('app.locale'));
    }
}
