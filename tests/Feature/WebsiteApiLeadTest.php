<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Lead;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteApiLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_lead_sourced_from_the_website(): void
    {
        $company = Company::query()->create([
            'name' => 'Tasneem Knitting',
            'slug' => 'tasneem-knitting',
            'invoice_prefix' => 'TK',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        [, $token] = CompanyApiKey::generate($company);

        $response = $this->postJson('/api/v1/leads', [
            'name' => 'Karim Traders',
            'phone' => '01811223344',
            'interest' => 'Bulk cotton yarn order',
        ], ['Authorization' => "Bearer {$token}"])
            ->assertCreated()
            ->assertJsonPath('data.status', 'new');

        app(CompanyContext::class)->set($company);
        $lead = Lead::query()->findOrFail($response->json('data.id'));

        $this->assertSame('website', $lead->source);
        $this->assertSame('Karim Traders', $lead->name);
    }

    public function test_name_and_phone_are_required(): void
    {
        $company = Company::query()->create([
            'name' => 'Tasneem Knitting', 'slug' => 'tasneem-knitting', 'invoice_prefix' => 'TK',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        [, $token] = CompanyApiKey::generate($company);

        $this->postJson('/api/v1/leads', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);
    }

    public function test_a_retried_submission_with_the_same_external_reference_returns_the_existing_lead(): void
    {
        $company = Company::query()->create([
            'name' => 'Tasneem Knitting', 'slug' => 'tasneem-knitting', 'invoice_prefix' => 'TK',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        [, $token] = CompanyApiKey::generate($company);
        $payload = ['external_reference' => 'TK-QUOTE-123456', 'name' => 'Karim Traders', 'phone' => '01811223344'];

        $first = $this->postJson('/api/v1/leads', $payload, ['Authorization' => "Bearer {$token}"])
            ->assertCreated()
            ->assertJsonPath('data.external_reference', 'TK-QUOTE-123456');

        $this->postJson('/api/v1/leads', $payload, ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        app(CompanyContext::class)->set($company);
        $this->assertSame(1, Lead::query()->where('external_reference', 'TK-QUOTE-123456')->count());
    }

    public function test_the_same_external_reference_in_another_company_is_a_separate_lead(): void
    {
        $tasneem = Company::query()->create([
            'name' => 'Tasneem Knitting', 'slug' => 'tasneem-knitting', 'invoice_prefix' => 'TK',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $noor = Company::query()->create([
            'name' => 'Noor Solar', 'slug' => 'noor-solar', 'invoice_prefix' => 'NS',
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        [, $tasneemToken] = CompanyApiKey::generate($tasneem);
        [, $noorToken] = CompanyApiKey::generate($noor);
        $payload = ['external_reference' => 'Q-1', 'name' => 'Karim Traders', 'phone' => '01811223344'];

        $this->postJson('/api/v1/leads', $payload, ['Authorization' => "Bearer {$tasneemToken}"])->assertCreated();
        $this->postJson('/api/v1/leads', $payload, ['Authorization' => "Bearer {$noorToken}"])->assertCreated();

        $this->assertSame(2, Lead::withoutGlobalScopes()->where('external_reference', 'Q-1')->count());
    }
}
