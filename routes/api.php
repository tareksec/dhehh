<?php

use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StorefrontAccountAuthController;
use App\Http\Controllers\Api\V1\StorefrontAccountController;
use App\Http\Controllers\Api\V1\StorefrontCheckoutController;
use App\Http\Controllers\Api\V1\StorefrontController;
use App\Http\Controllers\Api\V1\StorefrontOrderTrackingController;
use App\Http\Controllers\Api\V1\Sync\SyncController;
use App\Http\Middleware\AuthenticateStorefrontCustomer;
use App\Http\Middleware\EnsureNextjsSiteEnabled;
use App\Http\Middleware\EnsureStorefrontAccountsEnabled;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveCompanyFromApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| External Website Integration API (v1)
|--------------------------------------------------------------------------
|
| Server-to-server routes for an external company website (e.g. Tasneem
| Knitting Industry's) to sync products/stock, submit orders and inquiries,
| and read order status. Auth is a per-company Bearer API key (see
| CompanyApiKey + ResolveCompanyFromApiKey), which also sets CompanyContext
| for the rest of the request — every route below relies on that.
|
| See docs/api/website-integration.md and
| docs/api/website-integration.openapi.yaml for the external-facing contract.
*/

Route::prefix('v1')
    ->middleware(['throttle:120,1', ResolveCompanyFromApiKey::class])
    ->group(function (): void {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{sku}', [ProductController::class, 'show']);
        Route::patch('products/{sku}', [ProductController::class, 'update']);

        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order_number}', [OrderController::class, 'show']);

        Route::post('leads', [LeadController::class, 'store']);
    });

/*
|--------------------------------------------------------------------------
| Data sync API (v1) — read-only feed for IMS
|--------------------------------------------------------------------------
|
| Needs a "Data sync" key (CompanyApiKey::SCOPE_SYNC); a website key gets a
| 403 here, and a sync key gets a 403 on the website API. GET only, so the
| outside system can read the company's business data and change nothing.
| See docs/api/ims-sync-api.md.
*/

Route::prefix('v1/sync')
    ->middleware(['throttle:sync-api', ForceJsonResponse::class, ResolveCompanyFromApiKey::class.':sync'])
    ->group(function (): void {
        Route::get('ping', [SyncController::class, 'ping']);
        Route::get('{resource}', [SyncController::class, 'index'])->where('resource', '[a-z-]+');
    });

/*
|--------------------------------------------------------------------------
| Storefront API (v1) — catalog and checkout, for the headless Next.js site
|--------------------------------------------------------------------------
|
| Same per-company Bearer key as above, but only usable while the owner has
| switched on Site → Storefront Settings → Next.js Site (EnsureNextjsSiteEnabled).
| Called by the Next.js *server*, never by browsers — the key must stay in the
| server environment. Rate limited per key ("storefront-api", see
| AppServiceProvider), not per IP, because every call comes from one server.
| See docs/api/storefront-api.md.
*/

Route::prefix('v1/storefront')
    ->middleware(['throttle:storefront-api', ForceJsonResponse::class, ResolveCompanyFromApiKey::class, EnsureNextjsSiteEnabled::class])
    ->group(function (): void {
        Route::get('site', [StorefrontController::class, 'site']);
        Route::get('categories', [StorefrontController::class, 'categories']);
        Route::get('products', [StorefrontController::class, 'products']);
        Route::get('products/{slug}', [StorefrontController::class, 'product']);
        Route::get('slides', [StorefrontController::class, 'slides']);
        Route::get('collections', [StorefrontController::class, 'collections']);
        Route::get('pages', [StorefrontController::class, 'pages']);
        Route::get('pages/{slug}', [StorefrontController::class, 'page']);

        // Checkout (Phase 2): the ERP prices everything; see StorefrontCheckoutController.
        Route::post('checkout/quote', [StorefrontCheckoutController::class, 'quote']);
        Route::post('checkout', [StorefrontCheckoutController::class, 'place']);
        Route::post('checkout/payments/{payment}/finalize', [StorefrontCheckoutController::class, 'finalizePayment']);
        Route::get('orders/{order_number}', [StorefrontCheckoutController::class, 'order']);

        // Order tracking for visitors who are not signed in (Phase 3).
        Route::post('orders/track', [StorefrontOrderTrackingController::class, 'track']);
        Route::get('orders/{order_number}/tracking', [StorefrontOrderTrackingController::class, 'byToken']);

        // Customer accounts (Phase 3): sign-in, then the account area behind the customer token.
        Route::prefix('account')->middleware(EnsureStorefrontAccountsEnabled::class)->group(function (): void {
            Route::middleware('throttle:storefront-auth')->group(function (): void {
                Route::post('register', [StorefrontAccountAuthController::class, 'register']);
                Route::post('login', [StorefrontAccountAuthController::class, 'login']);
                Route::post('otp', [StorefrontAccountAuthController::class, 'sendOtp']);
                Route::post('otp/verify', [StorefrontAccountAuthController::class, 'verifyOtp']);
                Route::post('password/forgot', [StorefrontAccountAuthController::class, 'forgotPassword']);
                Route::post('password/reset', [StorefrontAccountAuthController::class, 'resetPassword']);
            });

            Route::middleware(AuthenticateStorefrontCustomer::class)->group(function (): void {
                Route::post('logout', [StorefrontAccountAuthController::class, 'logout']);
                Route::get('/', [StorefrontAccountController::class, 'show']);
                Route::patch('profile', [StorefrontAccountController::class, 'updateProfile']);
                Route::put('password', [StorefrontAccountController::class, 'updatePassword']);
                Route::get('activity', [StorefrontAccountController::class, 'activity']);
                Route::get('orders', [StorefrontAccountController::class, 'orders']);
                Route::get('orders/{order_number}', [StorefrontAccountController::class, 'order']);
                Route::post('orders/{order_number}/reorder', [StorefrontAccountController::class, 'reorder']);
                Route::post('orders/{order_number}/reviews', [StorefrontAccountController::class, 'storeReview']);
            });
        });
    });
