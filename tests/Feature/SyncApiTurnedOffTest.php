<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The old IMS data-sync API is turned off (IMS-V2/PLAN.md phase 7, 2026-10-19):
 * its paths answer 404 even with a key made for it, an old sync key opens
 * nothing else, and no new sync key can be made.
 */
class SyncApiTurnedOffTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Sync Co', 'slug' => 'sync-'.uniqid(), 'invoice_prefix' => 'SY'.random_int(100, 999), 'is_active' => true]);
    }

    public function test_the_sync_paths_answer_404_even_with_an_old_sync_key(): void
    {
        [, $business] = CompanyApiKey::generate($this->company, 'Old IMS', null, CompanyApiKey::SCOPE_SYNC);
        [, $investors] = CompanyApiKey::generate($this->company, 'Old IMS investors', null, CompanyApiKey::SCOPE_INVESTOR_SYNC);

        foreach (['/api/v1/sync/ping', '/api/v1/sync/products', '/api/v1/sync/purchases'] as $path) {
            $this->getJson($path, ['Authorization' => 'Bearer '.$business])->assertNotFound();
        }

        foreach (['/api/v1/sync/investor/ping', '/api/v1/sync/investor/investors', '/api/v1/sync/investor/payouts/1/proof'] as $path) {
            $this->getJson($path, ['Authorization' => 'Bearer '.$investors])->assertNotFound();
        }
    }

    public function test_an_old_sync_key_does_not_open_the_website_api(): void
    {
        [, $token] = CompanyApiKey::generate($this->company, 'Old IMS', null, CompanyApiKey::SCOPE_SYNC);

        $this->getJson('/api/v1/products', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden()
            ->assertJsonPath('error', 'wrong_key_scope');
    }

    public function test_only_website_keys_can_be_made_now(): void
    {
        $this->assertSame([CompanyApiKey::SCOPE_WEBSITE], CompanyApiKey::CREATABLE_SCOPES);
        $this->assertArrayHasKey(CompanyApiKey::SCOPE_SYNC, CompanyApiKey::SCOPES, 'old keys still show their type');
    }
}
