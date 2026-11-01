<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_installer_refuses_a_weak_first_admin_password(): void
    {
        $csrfToken = 'test-token';

        $this->withSession(['_token' => $csrfToken])->post('/install', $this->payload($csrfToken, 'password1'))
            ->assertSessionHasErrors('admin_password');

        $this->assertSame(0, User::query()->where('role', 'super_admin')->count());
    }

    public function test_the_installer_accepts_a_strong_first_admin_password(): void
    {
        $csrfToken = 'test-token';

        $this->withSession(['_token' => $csrfToken])->post('/install', $this->payload($csrfToken, 'Str0ng!Passphrase'))
            ->assertRedirect('/admin/login');

        $this->assertSame(1, User::query()->where('role', 'super_admin')->count());
    }

    /** @return array<string, string> */
    private function payload(string $token, string $password): array
    {
        return [
            '_token' => $token,
            'company_name' => 'Policy Test Co',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'd M Y',
            'admin_name' => 'Owner Admin',
            'admin_email' => 'owner@example.com',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ];
    }
}
