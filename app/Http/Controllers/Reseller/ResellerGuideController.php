<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\CompanySettingsService;
use App\Services\ResellerOwnCourierService;
use App\Services\StorefrontSiteConfigService;
use App\Support\ModuleGuard;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public reseller landing page: the portal host's front page, and still
 * "/portal/guide" (owner requests 2026-10-07 and 2026-10-22). What the program
 * is and the rules a reseller works under, read before applying, with buttons
 * to apply or sign in, ending with the footer of the company's Next.js website.
 * The how-it-works sections describe what the system actually enforces; the
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
        $brandColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $setting?->theme_color) ? $setting->theme_color : '#0F766E';
        $paymentMethods = app(ResellerOwnCourierService::class)->paymentMethods($company);
        $showFigures = (bool) ($setting?->reseller_guide_show_figures ?? false);
        $site = $this->website($company);
        $logos = $this->logos($company, $site);

        return view('reseller.guide', [
            'company' => $company,
            'companyName' => app(CompanySettingsService::class)->name($company),
            'logoUrl' => $logos['light'],
            'logoDarkUrl' => $logos['dark'],
            'brandColor' => $brandColor,
            // Text on the brand colour: white on a dark brand, near-black on a light one.
            'onBrandColor' => StorefrontSiteConfigService::isDarkColor($brandColor) ? '#FFFFFF' : '#111111',
            'currency' => $company->currency ?: 'BDT',
            'intro' => $setting?->reseller_guide_intro,
            'rules' => collect($setting?->reseller_guide_rules ?? [])
                ->filter(fn ($rule): bool => is_array($rule) && filled($rule['title'] ?? null))
                ->values(),
            'showFigures' => $showFigures,
            'minWithdrawal' => (float) ($setting?->reseller_min_withdrawal ?? 0),
            'packagingCharge' => (float) ($setting?->reseller_packaging_cost_per_unit ?? 0),
            // The same amount ResellerOwnCourierService::chargeReturn() takes from the deposit of a returned order.
            'returnCharge' => (float) ($setting?->reseller_return_charge ?? 0),
            'ownCourierAvailable' => $paymentMethods->isNotEmpty(),
            'paymentMethodNames' => $paymentMethods->pluck('name')->filter()->unique()->values(),
            'subdomainsEnabled' => (bool) $company->reseller_subdomains_enabled && filled($company->domain),
            'customDomainsEnabled' => (bool) $company->reseller_custom_domains_enabled,
            'storeLinkExample' => filled($company->domain) ? $company->domain.'/store/your-shop' : null,
            'subdomainExample' => filled($company->domain) ? 'your-shop.'.$company->domain : null,
            'applyUrl' => $panel->getRegistrationUrl(),
            'loginUrl' => $panel->getLoginUrl(),
            'footer' => $this->footer($request, $company, $site),
        ]);
    }

    /** The company's Next.js site row, null when it has none (or the lookup failed: the page still opens). */
    protected function website(Company $company): ?StorefrontSetting
    {
        return ModuleGuard::run('reseller-landing.website', fn (): ?StorefrontSetting => StorefrontSetting::nextjsFor($company->getKey()), null);
    }

    /**
     * The header logo (owner request 2026-10-23): the Next.js website's own logo pair, so the page wears the
     * same logo as the website, the dark-background one in dark mode. The company's own logos are the
     * fallback when the website has no logo; without a dark logo the light one shows in both modes,
     * as it does on the website.
     *
     * @return array{light: ?string, dark: ?string}
     */
    protected function logos(Company $company, ?StorefrontSetting $site): array
    {
        return ModuleGuard::run('reseller-landing.logo', function () use ($company, $site): array {
            $branding = $site ? (new StorefrontSiteConfigService($company, $site))->branding() : ['logo_url' => null, 'logo_dark_url' => null];
            $settings = app(CompanySettingsService::class);

            return [
                'light' => $branding['logo_url'] ?: $settings->logoUrl($company),
                'dark' => $branding['logo_dark_url'] ?: ($branding['logo_url'] ? null : $settings->darkLogoUrl(false, $company)),
            ];
        }, ['light' => null, 'dark' => null]);
    }

    /**
     * The footer of the company's Next.js website, so the landing page ends like the website does
     * (owner request 2026-10-22). Null when the company has no Next.js site row; a problem building it
     * only costs the page its footer, never the page.
     *
     * @return array<string, mixed>|null
     */
    protected function footer(Request $request, Company $company, ?StorefrontSetting $site): ?array
    {
        return ModuleGuard::run('reseller-landing.footer', fn (): ?array => $site ? (new StorefrontSiteConfigService($company, $site))->footer($request->getScheme()) : null, null);
    }
}
