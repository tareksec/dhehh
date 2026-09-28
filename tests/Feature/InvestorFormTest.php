<?php

namespace Tests\Feature;

use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Models\Company;
use App\Models\Investor;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class InvestorFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Mudarabah Company',
            'slug' => 'mudarabah-company',
            'invoice_prefix' => 'MUD',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin']));
    }

    public function test_the_investor_form_captures_identity_nominee_and_security_document_fields(): void
    {
        Livewire::test(CreateInvestor::class)
            ->fillForm([
                'name' => 'Abdul Karim',
                'display_name' => 'Investor A',
                'guardian_name' => 'Abdul Rahim',
                'date_of_birth' => '1980-05-01',
                'phone' => '01712345678',
                'email' => 'karim@example.com',
                'nid_number' => '1990123456789',
                'nominee_name' => 'Karima Begum',
                'nominee_nid_or_passport' => 'BQ0912345',
                'nominee_phone' => '01812345678',
                'nominee_relation' => 'স্ত্রী',
                'nominee_address' => 'House 4, Dhaka',
                'stamp_number' => '9363512, 9363513, 9363514',
                'cheque_number' => 'SBL-4457821',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $investor = Investor::query()->where('phone', '01712345678')->sole();

        $this->assertSame($this->company->id, $investor->company_id);
        $this->assertSame('Investor A', $investor->display_name);
        $this->assertSame('Abdul Rahim', $investor->guardian_name);
        $this->assertSame('1980-05-01', $investor->date_of_birth->toDateString());
        $this->assertSame('Karima Begum', $investor->nominee_name);
        $this->assertSame('BQ0912345', $investor->nominee_nid_or_passport);
        $this->assertSame('01812345678', $investor->nominee_phone);
        $this->assertSame('স্ত্রী', $investor->nominee_relation);
        $this->assertSame('House 4, Dhaka', $investor->nominee_address);
        $this->assertSame('9363512, 9363513, 9363514', $investor->stamp_number);
        $this->assertSame('SBL-4457821', $investor->cheque_number);
    }

    public function test_editing_an_investor_updates_the_nominee_and_security_fields(): void
    {
        $investor = Investor::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Nurul Islam',
            'phone' => '01999888777',
            'nid_number' => '1985111222333',
        ]);

        Livewire::test(EditInvestor::class, ['record' => $investor->getKey()])
            ->fillForm([
                'nominee_name' => 'Ayesha Islam',
                'nominee_phone' => '01988877766',
                'stamp_number' => '7001',
                'cheque_number' => 'CHQ-1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $investor->refresh();

        $this->assertSame('Ayesha Islam', $investor->nominee_name);
        $this->assertSame('01988877766', $investor->nominee_phone);
        $this->assertSame('7001', $investor->stamp_number);
        $this->assertSame('CHQ-1', $investor->cheque_number);
    }
}
