<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CompanySettingsService;
use App\Services\LicenseActivationService;
use App\Services\ProductSetupService;
use App\Support\AdminPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstallController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if ($this->hasAdminUser()) {
            return redirect('/admin/login');
        }

        return view('install');
    }

    public function store(
        Request $request,
        CompanySettingsService $company,
        ProductSetupService $setup,
        LicenseActivationService $licenses,
    ): RedirectResponse {
        if ($this->hasAdminUser()) {
            return redirect('/admin/login');
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'company_email' => ['nullable', 'email', 'max:120'],
            'company_phone' => ['nullable', 'string', 'max:60'],
            'currency' => ['required', 'string', 'max:12'],
            'timezone' => ['required', 'timezone'],
            'date_format' => ['required', 'string', 'max:30'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:120', 'unique:users,email'],
            // The first account is a Super Admin, so it follows the same strong-password policy as every admin (12+ characters, mixed case, numbers, symbols).
            'admin_password' => ['required', 'string', AdminPassword::rule(), 'confirmed'],
            'license_key' => ['nullable', 'string', 'max:32'],
            'demo_mode' => ['nullable', 'boolean'],
        ]);

        $company->save([
            'name' => $data['company_name'],
            'email' => $data['company_email'] ?? null,
            'phone' => $data['company_phone'] ?? null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'date_format' => $data['date_format'],
        ]);

        User::query()->create([
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
            'role' => 'super_admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if (filled($data['license_key'] ?? null)) {
            $licenses->activate([
                'key' => $data['license_key'],
                'licensed_to' => $data['company_name'],
                'support_email' => $data['company_email'] ?? $data['admin_email'],
            ]);
        }

        $setup->save([
            'installed' => true,
            'onboarding_completed' => true,
            'demo_mode' => (bool) ($data['demo_mode'] ?? false),
            'demo_notice' => 'Demo mode is enabled. Sample data is safe to explore.',
        ]);

        return redirect('/admin/login')->with('status', 'Installation completed. Sign in with your admin account.');
    }

    protected function hasAdminUser(): bool
    {
        return User::query()->where('role', 'super_admin')->exists();
    }
}
