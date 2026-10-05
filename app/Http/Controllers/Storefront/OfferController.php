<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Offer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\OfferPricingService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function __construct(protected CompanyContext $context, protected OfferPricingService $pricing) {}

    public function index(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->indexView($request, $company, $setting);
    }

    public function indexPreview(Request $request, Company $company): View
    {
        $setting = $this->previewStorefront($company);

        return $this->indexView($request, $company, $setting, $company->slug);
    }

    public function show(Request $request, string $slug): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->showView($this->findPublishedOffer($slug), $company, $setting);
    }

    public function showPreview(Request $request, Company $company, string $slug): View
    {
        $setting = $this->previewStorefront($company);

        // Unlike the public show() above, preview intentionally is not
        // restricted to Published — the whole point of previewing is to
        // review a Draft offer's AI-generated/hand-built landing page
        // before flipping it to Published (same "preview shows what isn't
        // live yet" convention as the main storefront preview).
        return $this->showView($this->findOfferForPreview($slug), $company, $setting, $company->slug);
    }

    protected function indexView(Request $request, Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        $type = $request->query('type');
        $type = in_array($type, [Offer::TYPE_SINGLE, Offer::TYPE_COMBO], true) ? $type : null;

        $offers = Offer::query()
            ->landingPages()
            ->published()
            ->when($type, fn ($query) => $query->where('type', $type))
            ->with('items.product')
            ->latest()
            ->get();

        return StorefrontThemeRegistry::page('offers.index', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'offers' => $offers,
            'activeType' => $type,
            'pricing' => $this->pricing,
        ]);
    }

    protected function showView(Offer $offer, Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        $offer->load(['items.product', 'items.productVariant']);

        return StorefrontThemeRegistry::page('offers.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'offer' => $offer,
            'componentsSubtotal' => $this->pricing->componentsSubtotal($offer),
            'finalPrice' => $this->pricing->finalPrice($offer),
            // VAT for ONE bundle, from the same per-line split the checkout
            // stores; the page multiplies it by the chosen quantity.
            'vatPerBundle' => $setting->vatAmountForItems(collect($this->pricing->explodeToOrderLines($offer, 1))->map(fn (array $line): array => [
                'subtotal' => (float) $line['unit_price'] * (int) $line['quantity'],
                'product' => $offer->items->firstWhere('product_id', $line['product_id'])?->product,
            ])),
            'reviews' => $this->reviewsForBlocks($offer),
        ]);
    }

    /**
     * @return array<string, Collection>
     */
    protected function reviewsForBlocks(Offer $offer): array
    {
        $approved = $offer->approvedReviews()->latest()->limit(50)->get();

        return collect($offer->blocks ?? [])
            ->filter(fn (array $block): bool => ($block['type'] ?? null) === 'testimonials')
            ->mapWithKeys(function (array $block) use ($approved): array {
                $reviewIds = (array) ($block['data']['review_ids'] ?? []);
                $selected = $reviewIds === []
                    ? $approved->sortByDesc('rating')->take(6)
                    : $approved->whereIn('id', $reviewIds);

                return [$block['id'] => $selected->values()];
            })
            ->all();
    }

    protected function findPublishedOffer(string $slug): Offer
    {
        return Offer::query()
            ->landingPages()
            ->where('slug', trim($slug))
            ->where('status', Offer::STATUS_PUBLISHED)
            ->firstOrFail();
    }

    /** Preview may show a Draft/Archived offer too — CompanyScope (already set by previewStorefront()) still keeps this to the previewed company only. */
    protected function findOfferForPreview(string $slug): Offer
    {
        return Offer::query()->landingPages()->where('slug', trim($slug))->firstOrFail();
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }

    protected function previewStorefront(Company $company): StorefrontSetting
    {
        abort_unless(app()->environment(['local', 'testing']) || auth()->check(), 404);

        // Matches Storefront\PreviewController::previewCompany() — an
        // authenticated preview request must belong to a company the
        // signed-in staff member actually has access to (super admins pass
        // via the existing canAccessCompany() bypass).
        if (auth()->check()) {
            abort_unless(auth()->user()->canAccessCompany($company->getKey()), 404);
        }

        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting([
            'company_id' => $company->getKey(),
            'theme_color' => '#F59E0B',
            'meta_title' => $company->name,
            'is_published' => true,
        ]);
    }
}
