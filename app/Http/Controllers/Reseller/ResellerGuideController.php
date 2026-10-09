<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanySettingsService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public reseller guide on the portal host ("/portal/guide", owner
 * request 2026-10-07): what the program is and the rules a reseller works
 * under, read before applying, with buttons to apply or sign in. The
 * how-it-works sections describe what the system actually enforces; the
 * intro, the store's own rules and whether money figures are shown are
 * admin-managed on Resellers → Reseller Settings.
 */
class ResellerGuideController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $request->attributes->get('reseller_portal_company');

        abort_unless($company instanceof Company, 404);

        $setting = $company->storefrontSetting;

        abort_if($setting !== null && ! $setting->reseller_guide_enabled, 404);

        $panel = Filament::getPanel('reseller');
        $paymentMethods = app(ResellerOwnCourierService::class)->paymentMethods($company);
        $showFigures = (bool) ($setting?->reseller_guide_show_figures ?? false);

        return view('reseller.guide', [
            'company' => $company,
            'companyName' => app(CompanySettingsService::class)->name($company),
            'logoUrl' => app(CompanySettingsService::class)->logoUrl($company),
            'brandColor' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $setting?->theme_color) ? $setting->theme_color : '#0F766E',
            'currency' => $company->currency ?: 'BDT',
            'intro' => $setting?->reseller_guide_intro,
            'rules' => collect($setting?->reseller_guide_rules ?? [])
                ->filter(fn ($rule): bool => is_array($rule) && filled($rule['title'] ?? null))
                ->values(),
            'showFigures' => $showFigures,
            'minWithdrawal' => (float) ($setting?->reseller_min_withdrawal ?? 0),
            'packagingCharge' => (float) ($setting?->reseller_packaging_cost_per_unit ?? 0),
            'ownCourierAvailable' => $paymentMethods->isNotEmpty(),
            'paymentMethodNames' => $paymentMethods->pluck('name')->filter()->unique()->values(),
            'subdomainsEnabled' => (bool) $company->reseller_subdomains_enabled && filled($company->domain),
            'customDomainsEnabled' => (bool) $company->reseller_custom_domains_enabled,
            'storeLinkExample' => filled($company->domain) ? $company->domain.'/store/your-shop' : null,
            'subdomainExample' => filled($company->domain) ? 'your-shop.'.$company->domain : null,
            'applyUrl' => $panel->getRegistrationUrl(),
            'loginUrl' => $panel->getLoginUrl(),
        ]);
    }
}
