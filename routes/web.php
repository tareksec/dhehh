<?php

use App\Http\Controllers\Admin\AppUpgradeController;
use App\Http\Controllers\Admin\BackupDownloadController;
use App\Http\Controllers\Admin\CompanySwitchController;
use App\Http\Controllers\Admin\ConversationMediaController;
use App\Http\Controllers\Admin\CustomerCsvController;
use App\Http\Controllers\Admin\ExpenseScanImageController;
use App\Http\Controllers\Admin\InvestmentDocumentDownloadController;
use App\Http\Controllers\Admin\InvestmentReportController;
use App\Http\Controllers\Admin\InvestorContractDownloadController;
use App\Http\Controllers\Admin\InvestorPortalOpenController;
use App\Http\Controllers\Admin\LegacyAdminClusterRedirectController;
use App\Http\Controllers\Admin\OrderPdfController;
use App\Http\Controllers\Admin\ProductCsvController;
use App\Http\Controllers\Admin\PurchaseCsvController;
use App\Http\Controllers\Admin\PurchaseDocumentController;
use App\Http\Controllers\Admin\PushDeviceController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\ReportPdfController;
use App\Http\Controllers\Admin\SupplierCsvController;
use App\Http\Controllers\Admin\SupplierReportPdfController;
use App\Http\Controllers\Admin\VoucherAttachmentDownloadController;
use App\Http\Controllers\Admin\VoucherReceiptController;
use App\Http\Controllers\ChatOrderController;
use App\Http\Controllers\CourierHandoverSheetController;
use App\Http\Controllers\CourierWebhookController;
use App\Http\Controllers\DocumentationGuideController;
use App\Http\Controllers\FirebaseServiceWorkerController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\MobileCrashReportController;
use App\Http\Controllers\PayStationWebhookController;
use App\Http\Controllers\QuotationPublicController;
use App\Http\Controllers\Reseller\ResellerGuideController;
use App\Http\Controllers\Reseller\ResellerOnlinePaymentReturnController;
use App\Http\Controllers\Reseller\ResellerProductPhotosController;
use App\Http\Controllers\Storefront\AccountAuthController as StorefrontAccountAuthController;
use App\Http\Controllers\Storefront\AccountController as StorefrontAccountController;
use App\Http\Controllers\Storefront\AccountOrdersController as StorefrontAccountOrdersController;
use App\Http\Controllers\Storefront\AccountProfileController as StorefrontAccountProfileController;
use App\Http\Controllers\Storefront\CartController as StorefrontCartController;
use App\Http\Controllers\Storefront\CheckoutController as StorefrontCheckoutController;
use App\Http\Controllers\Storefront\ComplaintController as StorefrontComplaintController;
use App\Http\Controllers\Storefront\ContactController as StorefrontContactController;
use App\Http\Controllers\Storefront\HomeController as StorefrontHomeController;
use App\Http\Controllers\Storefront\MetaConsentController as StorefrontMetaConsentController;
use App\Http\Controllers\Storefront\OfferCheckoutController as StorefrontOfferCheckoutController;
use App\Http\Controllers\Storefront\OfferController as StorefrontOfferController;
use App\Http\Controllers\Storefront\OrderTrackController as StorefrontOrderTrackController;
use App\Http\Controllers\Storefront\PageController as StorefrontPageController;
use App\Http\Controllers\Storefront\PreviewController as StorefrontPreviewController;
use App\Http\Controllers\Storefront\ProductIndexController as StorefrontProductIndexController;
use App\Http\Controllers\Storefront\ProductReviewController as StorefrontProductReviewController;
use App\Http\Controllers\Storefront\ProductShowController as StorefrontProductShowController;
use App\Http\Controllers\Storefront\ResellerController;
use App\Http\Controllers\Storefront\ResellerStoreController;
use App\Http\Controllers\Storefront\SeoFilesController;
use App\Http\Controllers\WhatsAppWebWebhookController;
use App\Http\Controllers\WooCommerceWebhookController;
use App\Http\Controllers\ZiniPayWebhookController;
use App\Http\Middleware\ResolveCompanyFromDomain;
use App\Http\Middleware\ResolveCompanyFromResellerPortalDomain;
use App\Http\Middleware\ResolveResellerFromSlug;
use App\Models\Order;
use App\Models\User;
use App\Services\AppUpdateService;
use App\Services\AuditLogService;
use App\Services\CompanySettingsService;
use App\Support\AppDeployment;
use App\Support\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveCompanyFromDomain::class.':optional')
    ->get('/', StorefrontHomeController::class)
    ->name('marketing.home');

// Shareable quotation link: signed URL, no login required, signature can't be guessed.
Route::middleware('signed')
    ->get('/quotation/{quotationNumber}', [QuotationPublicController::class, 'show'])
    ->name('quotation.public');

Route::get('/internal/tls-ask', \App\Http\Controllers\TlsAskController::class)
    ->middleware('throttle:60,1')
    ->name('internal.tls-ask');

Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])
    ->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle'])
    ->middleware('throttle:600,1')
    ->name('webhooks.meta.handle');

// Signed (HMAC) by the self-hosted whatsapp-bridge; see WhatsAppWebWebhookController.
Route::middleware('throttle:600,1')->group(function (): void {
    Route::post('/webhooks/whatsapp-web/{channel}', [WhatsAppWebWebhookController::class, 'handle'])
        ->whereNumber('channel')
        ->name('webhooks.whatsapp-web.handle');
    Route::post('/webhooks/whatsapp-web/{channel}/media', [WhatsAppWebWebhookController::class, 'media'])
        ->whereNumber('channel')
        ->name('webhooks.whatsapp-web.media');
});

Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/o/{token}', [ChatOrderController::class, 'show'])
        ->name('chat-order.show');
    Route::post('/o/{token}', [ChatOrderController::class, 'store'])
        ->name('chat-order.store');
});

Route::middleware(ResolveCompanyFromDomain::class.':optional')
    ->get('/pages/{slug}', [StorefrontPageController::class, 'show'])
    ->name('storefront.pages.show');

// robots.txt and the sitemap answer for whichever domain asks; the app's own
// domain has no company, so it keeps the admin-only rules and has no sitemap.
Route::middleware(ResolveCompanyFromDomain::class.':optional')->group(function (): void {
    Route::get('/robots.txt', [SeoFilesController::class, 'robots'])->name('storefront.robots');
    Route::get('/sitemap.xml', [SeoFilesController::class, 'sitemap'])->name('storefront.sitemap');
    Route::get('/sitemap-{page}.xml', [SeoFilesController::class, 'sitemap'])->whereNumber('page')->name('storefront.sitemap.page');
});

Route::get('/storefront', [StorefrontPreviewController::class, 'home'])
    ->name('storefront.preview.index');

Route::prefix('/storefront/{company:slug}')->group(function (): void {
    Route::get('/', [StorefrontPreviewController::class, 'home'])
        ->name('storefront.preview.show');

    Route::get('/products', [StorefrontPreviewController::class, 'products'])
        ->name('storefront.preview.products.index');

    Route::get('/category/{slug}', [StorefrontPreviewController::class, 'products'])
        ->name('storefront.preview.categories.show');

    Route::get('/product/{slug}', [StorefrontPreviewController::class, 'product'])
        ->name('storefront.preview.products.show');

    Route::get('/cart', [StorefrontCartController::class, 'showPreview'])
        ->name('storefront.preview.cart.show');

    Route::post('/cart/items/{slug}', [StorefrontCartController::class, 'addPreview'])
        ->name('storefront.preview.cart.add');

    Route::patch('/cart/items/{slug}', [StorefrontCartController::class, 'updatePreview'])
        ->name('storefront.preview.cart.update');

    Route::delete('/cart/items/{slug}', [StorefrontCartController::class, 'removePreview'])
        ->name('storefront.preview.cart.remove');

    Route::get('/checkout', [StorefrontCheckoutController::class, 'showPreview'])
        ->name('storefront.preview.checkout.show');

    Route::post('/checkout', [StorefrontCheckoutController::class, 'storePreview'])
        ->name('storefront.preview.checkout.store');

    Route::post('/checkout/autosave', [StorefrontCheckoutController::class, 'autosavePreview'])
        ->middleware('throttle:30,1')
        ->name('storefront.preview.checkout.autosave');

    Route::get('/checkout/success/{order}', [StorefrontCheckoutController::class, 'successPreview'])
        ->name('storefront.preview.checkout.success');

    Route::get('/checkout/payment/{payment}/return', [StorefrontCheckoutController::class, 'paymentReturnPreview'])
        ->name('storefront.preview.checkout.payment-return');

    Route::get('/complaints', [StorefrontComplaintController::class, 'showPreview'])
        ->name('storefront.preview.complaints.show');

    Route::post('/complaints', [StorefrontComplaintController::class, 'storePreview'])
        ->middleware('throttle:5,1')
        ->name('storefront.preview.complaints.store');

    Route::get('/track', [StorefrontOrderTrackController::class, 'indexPreview'])
        ->name('storefront.preview.track.index');

    Route::get('/track/{orderNo}', [StorefrontOrderTrackController::class, 'showPreview'])
        ->name('storefront.preview.track.show');

    Route::get('/account/orders', [StorefrontAccountOrdersController::class, 'indexPreview'])
        ->name('storefront.preview.account.orders');

    Route::post('/account/orders/{orderNo}/reorder', [StorefrontAccountOrdersController::class, 'reorderPreview'])
        ->name('storefront.preview.account.reorder');

    Route::get('/account/orders/{orderNo}/review', [StorefrontProductReviewController::class, 'createPreview'])
        ->name('storefront.preview.account.reviews.create');

    Route::post('/account/orders/{orderNo}/review', [StorefrontProductReviewController::class, 'storePreview'])
        ->name('storefront.preview.account.reviews.store');

    Route::get('/account', [StorefrontAccountController::class, 'indexPreview'])
        ->name('storefront.preview.account.index');
    Route::get('/account/activity', [StorefrontAccountController::class, 'activityPreview'])
        ->name('storefront.preview.account.activity');
    Route::get('/account/login', [StorefrontAccountAuthController::class, 'showLoginPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.login');
    Route::post('/account/login', [StorefrontAccountAuthController::class, 'loginPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.login.store');
    Route::get('/account/otp', [StorefrontAccountAuthController::class, 'showOtpPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.otp');
    Route::post('/account/otp', [StorefrontAccountAuthController::class, 'sendOtpPreview'])
        ->middleware('throttle:5,1')
        ->name('storefront.preview.account.otp.send');
    Route::get('/account/otp/verify', [StorefrontAccountAuthController::class, 'showOtpVerifyPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.otp.verify');
    Route::post('/account/otp/verify', [StorefrontAccountAuthController::class, 'verifyOtpPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.otp.verify.store');
    Route::get('/account/register', [StorefrontAccountAuthController::class, 'showRegisterPreview'])
        ->name('storefront.preview.account.register');
    Route::post('/account/register', [StorefrontAccountAuthController::class, 'registerPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.register.store');
    Route::get('/account/register/verify', [StorefrontAccountAuthController::class, 'showRegisterVerifyPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.register.verify');
    Route::post('/account/register/verify', [StorefrontAccountAuthController::class, 'verifyRegisterPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.register.verify.store');
    Route::post('/account/register/resend', [StorefrontAccountAuthController::class, 'resendRegisterCodePreview'])
        ->middleware('throttle:5,1')
        ->name('storefront.preview.account.register.resend');
    Route::get('/account/forgot-password', [StorefrontAccountAuthController::class, 'showForgotPasswordPreview'])
        ->name('storefront.preview.account.forgot-password');
    Route::post('/account/forgot-password', [StorefrontAccountAuthController::class, 'forgotPasswordPreview'])
        ->middleware('throttle:5,1')
        ->name('storefront.preview.account.forgot-password.store');
    Route::get('/account/reset-password', [StorefrontAccountAuthController::class, 'showResetPasswordPreview'])
        ->name('storefront.preview.account.reset-password');
    Route::post('/account/reset-password', [StorefrontAccountAuthController::class, 'resetPasswordPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.reset-password.store');
    Route::post('/account/logout', [StorefrontAccountAuthController::class, 'logoutPreview'])
        ->name('storefront.preview.account.logout');
    Route::get('/account/profile', [StorefrontAccountProfileController::class, 'showPreview'])
        ->name('storefront.preview.account.profile');
    Route::patch('/account/profile', [StorefrontAccountProfileController::class, 'updatePreview'])
        ->name('storefront.preview.account.profile.update');
    Route::put('/account/password', [StorefrontAccountProfileController::class, 'updatePasswordPreview'])
        ->middleware('throttle:10,1')
        ->name('storefront.preview.account.password.update');
    Route::get('/account/reseller', [ResellerStoreController::class, 'editPreview'])
        ->name('storefront.preview.account.reseller');
    Route::patch('/account/reseller', [ResellerStoreController::class, 'updateSlugPreview'])
        ->name('storefront.preview.account.reseller.slug');
    Route::post('/account/reseller/products/{product}', [ResellerStoreController::class, 'toggleProductPreview'])
        ->name('storefront.preview.account.reseller.products.toggle');
    Route::patch('/account/reseller/products/{product}/price', [ResellerStoreController::class, 'updatePricePreview'])
        ->name('storefront.preview.account.reseller.products.price');
    Route::post('/account/reseller/payouts', [ResellerStoreController::class, 'requestPayoutPreview'])
        ->name('storefront.preview.account.reseller.payouts.store');

    Route::get('/reseller', [ResellerController::class, 'showPreview'])
        ->name('storefront.preview.reseller.show');

    Route::post('/reseller', [ResellerController::class, 'storePreview'])
        ->name('storefront.preview.reseller.store');

    Route::get('/pages/{slug}', [StorefrontPageController::class, 'showPreview'])
        ->name('storefront.preview.pages.show');

    Route::get('/contact', [StorefrontContactController::class, 'showPreview'])
        ->name('storefront.preview.contact');

    Route::get('/offers', [StorefrontOfferController::class, 'indexPreview'])
        ->name('storefront.preview.offers.index');

    Route::get('/offers/{slug}', [StorefrontOfferController::class, 'showPreview'])
        ->name('storefront.preview.offers.show');

    Route::post('/offers/{slug}/checkout', [StorefrontOfferCheckoutController::class, 'storePreview'])
        ->name('storefront.preview.offers.checkout');

    Route::get('/offers/{slug}/thank-you/{order}', [StorefrontOfferCheckoutController::class, 'thankYouPreview'])
        ->name('storefront.preview.offers.thank-you');
});

Route::middleware(ResolveCompanyFromDomain::class)->group(function (): void {
    Route::post('/meta-consent', [StorefrontMetaConsentController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('storefront.meta-consent.store');

    Route::get('/products', StorefrontProductIndexController::class)
        ->name('storefront.products.index');

    Route::get('/category/{slug}', StorefrontProductIndexController::class)
        ->name('storefront.categories.show');

    Route::get('/product/{slug}', StorefrontProductShowController::class)
        ->name('storefront.products.show');

    Route::get('/cart', [StorefrontCartController::class, 'show'])
        ->name('storefront.cart.show');

    Route::post('/cart/items/{slug}', [StorefrontCartController::class, 'add'])
        ->name('storefront.cart.add');

    Route::patch('/cart/items/{slug}', [StorefrontCartController::class, 'update'])
        ->name('storefront.cart.update');

    Route::delete('/cart/items/{slug}', [StorefrontCartController::class, 'remove'])
        ->name('storefront.cart.remove');

    Route::get('/cart/recover/{cart}/{token}', [StorefrontCartController::class, 'recover'])
        ->middleware('throttle:20,1')
        ->name('storefront.cart.recover');

    Route::get('/checkout', [StorefrontCheckoutController::class, 'show'])
        ->name('storefront.checkout.show');

    Route::post('/checkout', [StorefrontCheckoutController::class, 'store'])
        ->name('storefront.checkout.store');

    Route::post('/checkout/autosave', [StorefrontCheckoutController::class, 'autosave'])
        ->middleware('throttle:30,1')
        ->name('storefront.checkout.autosave');

    Route::get('/checkout/success/{order}', [StorefrontCheckoutController::class, 'success'])
        ->name('storefront.checkout.success');

    Route::get('/checkout/payment/{payment}/return', [StorefrontCheckoutController::class, 'paymentReturn'])
        ->name('storefront.checkout.payment-return');

    Route::get('/complaints', [StorefrontComplaintController::class, 'show'])
        ->name('storefront.complaints.show');

    Route::post('/complaints', [StorefrontComplaintController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('storefront.complaints.store');

    Route::get('/track', [StorefrontOrderTrackController::class, 'index'])
        ->name('storefront.track.index');

    Route::get('/track/{orderNo}', [StorefrontOrderTrackController::class, 'show'])
        ->name('storefront.track.show');

    Route::get('/account/orders', [StorefrontAccountOrdersController::class, 'index'])
        ->name('storefront.account.orders');

    Route::post('/account/orders/{orderNo}/reorder', [StorefrontAccountOrdersController::class, 'reorder'])
        ->name('storefront.account.reorder');

    Route::get('/account/orders/{orderNo}/review', [StorefrontProductReviewController::class, 'create'])
        ->name('storefront.account.reviews.create');

    Route::post('/account/orders/{orderNo}/review', [StorefrontProductReviewController::class, 'store'])
        ->name('storefront.account.reviews.store');

    Route::middleware('throttle:10,1')->group(function (): void {
        Route::get('/account/login', [StorefrontAccountAuthController::class, 'showLogin'])
            ->name('storefront.account.login');
        Route::post('/account/login', [StorefrontAccountAuthController::class, 'login'])
            ->name('storefront.account.login.store');
        Route::get('/account/otp', [StorefrontAccountAuthController::class, 'showOtp'])
            ->name('storefront.account.otp');
        Route::get('/account/otp/verify', [StorefrontAccountAuthController::class, 'showOtpVerify'])
            ->name('storefront.account.otp.verify');
        Route::post('/account/otp/verify', [StorefrontAccountAuthController::class, 'verifyOtp'])
            ->name('storefront.account.otp.verify.store');
        Route::get('/account/register', [StorefrontAccountAuthController::class, 'showRegister'])
            ->name('storefront.account.register');
        Route::post('/account/register', [StorefrontAccountAuthController::class, 'register'])
            ->name('storefront.account.register.store');
        Route::get('/account/register/verify', [StorefrontAccountAuthController::class, 'showRegisterVerify'])
            ->name('storefront.account.register.verify');
        Route::post('/account/register/verify', [StorefrontAccountAuthController::class, 'verifyRegister'])
            ->name('storefront.account.register.verify.store');
        Route::get('/account/reset-password', [StorefrontAccountAuthController::class, 'showResetPassword'])
            ->name('storefront.account.reset-password');
        Route::post('/account/reset-password', [StorefrontAccountAuthController::class, 'resetPassword'])
            ->name('storefront.account.reset-password.store');
    });

    Route::middleware('throttle:5,1')->group(function (): void {
        Route::get('/account/forgot-password', [StorefrontAccountAuthController::class, 'showForgotPassword'])
            ->name('storefront.account.forgot-password');
        Route::post('/account/forgot-password', [StorefrontAccountAuthController::class, 'forgotPassword'])
            ->name('storefront.account.forgot-password.store');
        Route::post('/account/otp', [StorefrontAccountAuthController::class, 'sendOtp'])
            ->name('storefront.account.otp.send');
        Route::post('/account/register/resend', [StorefrontAccountAuthController::class, 'resendRegisterCode'])
            ->name('storefront.account.register.resend');
    });

    Route::post('/account/logout', [StorefrontAccountAuthController::class, 'logout'])
        ->name('storefront.account.logout');

    Route::get('/account', [StorefrontAccountController::class, 'index'])
        ->name('storefront.account.index');
    Route::get('/account/activity', [StorefrontAccountController::class, 'activity'])
        ->name('storefront.account.activity');

    Route::get('/account/profile', [StorefrontAccountProfileController::class, 'show'])
        ->name('storefront.account.profile');
    Route::patch('/account/profile', [StorefrontAccountProfileController::class, 'update'])
        ->name('storefront.account.profile.update');
    Route::put('/account/password', [StorefrontAccountProfileController::class, 'updatePassword'])
        ->middleware('throttle:10,1')
        ->name('storefront.account.password.update');
    Route::get('/account/reseller', [ResellerStoreController::class, 'edit'])
        ->name('storefront.account.reseller');
    Route::patch('/account/reseller', [ResellerStoreController::class, 'updateSlug'])
        ->name('storefront.account.reseller.slug');
    Route::post('/account/reseller/products/{product}', [ResellerStoreController::class, 'toggleProduct'])
        ->name('storefront.account.reseller.products.toggle');
    Route::patch('/account/reseller/products/{product}/price', [ResellerStoreController::class, 'updatePrice'])
        ->name('storefront.account.reseller.products.price');
    Route::post('/account/reseller/payouts', [ResellerStoreController::class, 'requestPayout'])
        ->name('storefront.account.reseller.payouts.store');

    Route::get('/reseller', [ResellerController::class, 'show'])
        ->name('storefront.reseller.show');

    Route::post('/reseller', [ResellerController::class, 'store'])
        ->name('storefront.reseller.store');

    Route::get('/contact', [StorefrontContactController::class, 'show'])
        ->name('storefront.contact');

    Route::get('/offers', [StorefrontOfferController::class, 'index'])
        ->name('storefront.offers.index');

    Route::get('/offers/{slug}', [StorefrontOfferController::class, 'show'])
        ->name('storefront.offers.show');

    Route::post('/offers/{slug}/checkout', [StorefrontOfferCheckoutController::class, 'store'])
        ->name('storefront.offers.checkout');

    Route::get('/offers/{slug}/thank-you/{order}', [StorefrontOfferCheckoutController::class, 'thankYou'])
        ->name('storefront.offers.thank-you');

});

// A reseller's own curated mini-storefront, path-based under their
// company's existing domain (no wildcard DNS/SSL needed -- the owner's
// explicit choice, see UPDATE_NOTES.md). Reuses the exact same controllers
// as the main storefront above; ResolveResellerFromSlug additively scopes
// them via the `storefront_reseller` request attribute rather than
// duplicating any product/cart/checkout logic.
Route::middleware([ResolveCompanyFromDomain::class, ResolveResellerFromSlug::class])
    ->prefix('/store/{resellerSlug}')
    ->group(function (): void {
        Route::get('/', StorefrontProductIndexController::class)
            ->name('storefront.reseller_store.show');

        Route::get('/products', StorefrontProductIndexController::class)
            ->name('storefront.reseller_store.products.index');

        Route::get('/category/{slug}', StorefrontProductIndexController::class)
            ->name('storefront.reseller_store.categories.show');

        Route::get('/product/{slug}', StorefrontProductShowController::class)
            ->name('storefront.reseller_store.products.show');

        Route::get('/cart', [StorefrontCartController::class, 'show'])
            ->name('storefront.reseller_store.cart.show');

        Route::post('/cart/items/{slug}', [StorefrontCartController::class, 'add'])
            ->name('storefront.reseller_store.cart.add');

        Route::patch('/cart/items/{slug}', [StorefrontCartController::class, 'update'])
            ->name('storefront.reseller_store.cart.update');

        Route::delete('/cart/items/{slug}', [StorefrontCartController::class, 'remove'])
            ->name('storefront.reseller_store.cart.remove');

        Route::get('/checkout', [StorefrontCheckoutController::class, 'show'])
            ->name('storefront.reseller_store.checkout.show');

        Route::post('/checkout', [StorefrontCheckoutController::class, 'store'])
            ->name('storefront.reseller_store.checkout.store');
    });

// The reseller portal's own product-photos download (Phase 4 of
// 13_RESELLER_PORTAL_PLAN.md). Path-prefixed with "portal" so
// RestrictPortalHosts's allow-list lets it through on the portal
// host; it needs the portal's own company resolution and login, which the
// Filament panel's middleware stack normally provides, so both are applied
// explicitly here since this is a plain route, not a panel page.
Route::get('/portal/products/{product}/photos', ResellerProductPhotosController::class)
    ->middleware([ResolveCompanyFromResellerPortalDomain::class, 'auth:customer'])
    ->name('reseller.portal.products.photos');

// Where the online gateway sends a reseller back after paying an own-courier
// advance. Signed, so no login is needed to land here; the page it ends on
// (the portal's Orders) asks for one.
Route::get('/portal/orders/payment/{payment}/return', ResellerOnlinePaymentReturnController::class)
    ->middleware(ResolveCompanyFromResellerPortalDomain::class)
    ->name('reseller.portal.orders.payment-return');

// The public reseller guide (rules before applying), on the portal host only;
// RestrictPortalHosts keeps "/portal/*" off every other host.
Route::get('/portal/guide', ResellerGuideController::class)
    ->middleware(ResolveCompanyFromResellerPortalDomain::class)
    ->name('reseller.portal.guide');

Route::view('/pricing', 'marketing.pricing')->name('marketing.pricing');

Route::view('/docs', 'marketing.docs')->name('marketing.docs');

// The in-app "Documentation" module's public read-only mirror — for sharing
// step-by-step guides with people who don't have an admin panel login (e.g.
// an external developer team), without exposing anything else in the app.
Route::get('/guides', [DocumentationGuideController::class, 'index'])->name('guides.index');
Route::get('/guides/{category}/{article}', [DocumentationGuideController::class, 'show'])->name('guides.show');

Route::get('/health/version', function () {
    $roleOptions = User::roleOptions();
    $release = AppRelease::current();
    $publishedRelease = AppRelease::latestPublished();
    $deployment = AppDeployment::current();
    $ready = app(AppUpdateService::class)->isDeploymentEligible($deployment);

    return response()
        ->json([
            'app' => 'zamzam-erp',
            'app_name' => config('app.name'),
            'version' => $release['version'],
            'published_version' => $publishedRelease['version'],
            'release_type' => $release['type'],
            'release_label' => $release['type_label'],
            'release_date' => $release['date'],
            'marker' => 'roles-built-in-v2',
            'commit' => $deployment['commit'],
            'deployment_id' => $deployment['deployment_id'],
            'deployment_ready' => $deployment['ready'],
            'ready' => $ready,
            'built_at' => $deployment['built_at'],
            'source_id' => $deployment['source_id'],
            'assets_id' => $deployment['assets_id'],
            'role_option_keys' => array_keys($roleOptions),
            'has_sales_staff_role' => array_key_exists('sales_staff', $roleOptions),
        ])
        ->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Expires' => '0',
            'Pragma' => 'no-cache',
        ]);
})->name('health.version');

Route::get('/install', [InstallController::class, 'create'])->name('install.create');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

Route::post('/webhooks/couriers/{provider}', CourierWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('couriers.webhook');

Route::post('/webhooks/zinipay/{payment}', ZiniPayWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('zinipay.webhook');

Route::post('/webhooks/paystation/{payment}', PayStationWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('paystation.webhook');

Route::post('/webhooks/woocommerce/{company}', WooCommerceWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('woocommerce.webhook');

// Public and unauthenticated on purpose: the Android app uploads a saved
// crash report on its next successful launch, which can be before login
// (no session/CSRF token available at that point). See
// MobileCrashReportController and CrashReporter.java.
Route::post('/webhooks/mobile-crash-reports', [MobileCrashReportController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('mobile-crash-reports.store');

// Public and unauthenticated on purpose: a service worker script is fetched
// directly by the browser (including background update checks that may not
// carry a session), and its Firebase Web config is not a secret -- Firebase
// projects are protected server-side, not by hiding this value. It must be
// served at the site root so its scope covers the whole origin, which is
// why it's rendered dynamically here instead of being a normal Vite asset.
Route::get('/firebase-messaging-sw.js', FirebaseServiceWorkerController::class)
    ->name('firebase-messaging-sw');

Route::middleware('auth')->get('/admin/orders/{order}/print', function (Order $order, Request $request) {
    abort_unless($request->user()?->canPerformModelAbility('view', Order::class), 403);

    // Feeds OrderActivityFeedService's narrated "Order printed by X" line
    // on the order's Activity section — see AuditLogService::record()'s
    // arbitrary-action support, no schema change needed. Every hit of
    // this route is a genuine print/print-preview open (the page always
    // triggers window.print() on load via ?print=1 or the Print button),
    // so this isn't deduped the way the ViewOrder 'viewed' log is.
    app(AuditLogService::class)->record('printed', $order);

    return view('orders.print', [
        'order' => $order->load(['company', 'customer', 'items.product', 'latestCourierBooking.provider']),
        'company' => app(CompanySettingsService::class)->profile($order->company),
        'invoice' => app(CompanySettingsService::class)->invoice($order->company),
    ]);
})->name('orders.print');

// Bulk invoice print for the Orders list's "Print invoices" bulk action —
// takes a comma-separated list of order IDs (built from the selected
// table rows) instead of a single {order} binding. `Order::query()` is
// still scoped by CompanyScope (BelongsToCompany), so IDs belonging to
// another company are silently dropped rather than leaking across
// companies.
Route::middleware('auth')->get('/admin/orders/print-bulk', function (Request $request) {
    abort_unless($request->user()?->canPerformModelAbility('view', Order::class), 403);

    $orderIds = collect(explode(',', (string) $request->query('orders')))
        ->map(fn (string $id): int => (int) trim($id))
        ->filter()
        ->unique();

    abort_if($orderIds->isEmpty(), 404);

    $ordersById = Order::query()
        ->whereIn('id', $orderIds)
        ->with(['company', 'customer', 'items.product', 'latestCourierBooking.provider'])
        ->get()
        ->keyBy('id');

    $orders = $orderIds->map(fn (int $id) => $ordersById->get($id))->filter()->values();

    abort_if($orders->isEmpty(), 404);

    $company = $orders->first()->company;

    // Same 'printed' narration as the single-order route, once per order
    // in the batch.
    $orders->each(fn (Order $order) => app(AuditLogService::class)->record('printed', $order));

    return view('orders.print-bulk', [
        'orders' => $orders,
        'company' => app(CompanySettingsService::class)->profile($company),
        'invoice' => app(CompanySettingsService::class)->invoice($company),
    ]);
})->name('orders.print.bulk');

Route::middleware('auth')
    ->get('/admin/orders/{order}/pdf', OrderPdfController::class)
    ->name('orders.pdf');

// Printable courier handover sheet (parcels booked through one courier on one
// day). CourierProvider is company-scoped, so another company's id is a 404.
Route::middleware('auth')
    ->get('/admin/courier-handover-sheet', CourierHandoverSheetController::class)
    ->name('courier.handover-sheet');

// Purchase trade documents: {type} is 'pi' (Proforma Invoice), 'ci'
// (Commercial Invoice), or 'pl' (Packing List) — see
// PurchaseDocumentController::TYPES, which validates it (a bad value 404s
// cleanly from the controller itself rather than relying on a route regex,
// so a mismatched {type} can't silently fall through to some unrelated
// catch-all route). Streamed (not downloaded) so it opens inline in the new
// tab the Filament "Generate ..." action opens, giving a print-preview the
// browser's native print/save handles the "printable" requirement for.
Route::middleware('auth')
    ->get('/admin/purchases/{purchase}/documents/{type}', PurchaseDocumentController::class)
    ->name('purchases.documents');

Route::middleware('auth')
    ->get('/admin/reports/export/{type}', ReportExportController::class)
    ->name('reports.export');

Route::middleware('auth')
    ->get('/admin/reports/export/{type}/pdf', ReportPdfController::class)
    ->name('reports.export.pdf');

Route::middleware('auth')
    ->get('/admin/reports/supplier/{supplier}/pdf', SupplierReportPdfController::class)
    ->name('reports.supplier.pdf');

Route::middleware('auth')->group(function (): void {
    Route::post('/admin/push-devices', [PushDeviceController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('admin.push-devices.store');

    Route::delete('/admin/push-devices', [PushDeviceController::class, 'destroy'])
        ->middleware('throttle:12,1')
        ->name('admin.push-devices.destroy');

    Route::post('/admin/app-updates/sync', [AppUpgradeController::class, 'synchronize'])
        ->middleware('throttle:12,1')
        ->name('admin.app-updates.sync');

    Route::post('/admin/app-upgrade', [AppUpgradeController::class, 'upgrade'])
        ->middleware('throttle:6,1')
        ->name('admin.app-upgrade');

    Route::redirect('/admin/companies', '/admin/company-management/companies')
        ->name('admin.companies.legacy');

    Route::redirect('/admin/company-settings', '/admin/company-management/company-settings')
        ->name('admin.company-settings.legacy');

    Route::post('/admin/company/switch', CompanySwitchController::class)
        ->name('admin.company.switch');

    Route::get('/admin/products/export/csv', [ProductCsvController::class, 'export'])
        ->name('products.export.csv');

    Route::get('/admin/products/import/sample', [ProductCsvController::class, 'sample'])
        ->name('products.import.sample');

    Route::get('/admin/products/stock/sample', [ProductCsvController::class, 'stockSample'])
        ->name('products.stock.sample');

    Route::get('/admin/customers/export/csv', [CustomerCsvController::class, 'export'])
        ->name('customers.export.csv');

    Route::get('/admin/customers/import/sample', [CustomerCsvController::class, 'sample'])
        ->name('customers.import.sample');

    Route::get('/admin/suppliers/export/csv', [SupplierCsvController::class, 'export'])
        ->name('suppliers.export.csv');

    Route::get('/admin/suppliers/import/sample', [SupplierCsvController::class, 'sample'])
        ->name('suppliers.import.sample');

    Route::get('/admin/purchases/import/sample', [PurchaseCsvController::class, 'sample'])
        ->name('purchases.import.sample');

    Route::get('/admin/backups/download/{filename}', BackupDownloadController::class)
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('backups.download');

    Route::get('/admin/conversation-messages/{message}/media', ConversationMediaController::class)
        ->whereNumber('message')
        ->name('conversation-messages.media');

    Route::get('/admin/expense-scans/{scan}/images/{index}', ExpenseScanImageController::class)
        ->whereNumber(['scan', 'index'])
        ->name('expense-scans.image');

    Route::get('/admin/voucher-attachments/{attachment}/download', VoucherAttachmentDownloadController::class)
        ->whereNumber('attachment')
        ->name('voucher-attachments.download');

    Route::get('/admin/investor-security-instruments/{instrument}/contract', InvestorContractDownloadController::class)
        ->whereNumber('instrument')
        ->name('investor-security-instruments.contract');

    Route::get('/admin/investment-documents/{document}/download', InvestmentDocumentDownloadController::class)
        ->whereNumber('document')
        ->name('investment-documents.download');

    // "Open investor portal" on an investor's / channel partner's page: opens in a new tab and sends it on to the portal host with a one-time token.
    Route::get('/admin/investor-portal/investors/{investor}/open', [InvestorPortalOpenController::class, 'investor'])
        ->whereNumber('investor')
        ->middleware('throttle:30,1')
        ->name('investor-portal.open-investor');

    Route::get('/admin/investor-portal/channel-partners/{partner}/open', [InvestorPortalOpenController::class, 'partner'])
        ->whereNumber('partner')
        ->middleware('throttle:30,1')
        ->name('investor-portal.open-partner');

    Route::get('/admin/investments/settlement-payouts/{payout}/report', [InvestmentReportController::class, 'investorPayout'])
        ->whereNumber('payout')
        ->name('investments.reports.investor-payout');

    Route::get('/admin/investments/projects/{project}/register', [InvestmentReportController::class, 'projectRegister'])
        ->whereNumber('project')
        ->name('investments.reports.project-register');

    Route::get('/admin/finance/{legacy}/{path?}', LegacyAdminClusterRedirectController::class)
        ->whereIn('legacy', ['fund-sources', 'fund-transfers'])
        ->where('path', '.*')
        ->name('admin.finance.legacy-fund-pages');

    // AI provider/key pages moved from AI Tools to Settings (opened from Integrations).
    Route::get('/admin/ai-tools/{page}', fn (string $page) => redirect("/admin/settings/{$page}", 301))
        ->whereIn('page', ['image-provider-settings', 'video-provider-settings', 'prompt-enhancer-settings', 'expense-scan-settings'])
        ->name('admin.ai-tools.legacy-provider-pages');

    Route::get('/admin/{legacy}/{path?}', LegacyAdminClusterRedirectController::class)
        ->whereIn('legacy', LegacyAdminClusterRedirectController::legacySegments())
        ->where('path', '.*')
        ->name('admin.clusters.legacy');
});

// Shareable Money Receipt: signed URL, no login required, signature can't be guessed.
Route::middleware('signed')
    ->get('/vouchers/{voucher:voucher_number}/receipt', VoucherReceiptController::class)
    ->name('vouchers.receipt');
