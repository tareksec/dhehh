<?php

use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StorefrontCheckoutController;
use App\Http\Controllers\Api\V1\StorefrontController;
use App\Http\Middleware\EnsureNextjsSiteEnabled;
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
    });
