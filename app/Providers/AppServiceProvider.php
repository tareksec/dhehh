<?php

namespace App\Providers;

use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Account;
use App\Models\AgreementTemplate;
use App\Models\Category;
use App\Models\ChannelPartner;
use App\Models\ChannelPartnerPayout;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerBlacklist;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\InvestmentWitness;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorCycleElection;
use App\Models\InvestorRequest;
use App\Models\InvestorSecurityInstrument;
use App\Models\Order;
use App\Models\OrderCost;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\Product;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SettlementPayout;
use App\Models\StockMovement;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\CompanyNotificationObserver;
use App\Observers\OrderNotificationObserver;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\ResellerPricing;
use App\Services\StorageSettingsService;
use App\Support\DefaultTableSort;
use App\Support\FileUploadClearAction;
use App\Support\GuardedViewManager;
use App\Support\ModuleHealth;
use App\Support\MoneyFormatter;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\View\ViewManager;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class);
        $this->app->alias(CompanyContext::class, 'company.context');
        $this->app->singleton(StorageSettingsService::class);
        $this->app->singleton(CompanyStorageService::class);
        $this->app->scoped(ResellerPricing::class);
        $this->app->scoped(ModuleHealth::class);

        // Every Filament render hook runs through ModuleGuard: one failing hook
        // must not break every page (extend() works whichever provider binds first).
        $this->app->extend(ViewManager::class, fn (): GuardedViewManager => new GuardedViewManager);
    }

    public function boot(): void
    {
        // A migration run may have created a missing table: re-check (ModuleHealth).
        Event::listen(MigrationsEnded::class, function (): void {
            ModuleHealth::forget();
        });

        // The headless Next.js storefront reads the catalog from ONE server, so
        // the default per-IP limiter would put every visitor in a single bucket.
        // Bucket by the presented API token instead (this runs before the key is
        // resolved, so a wrong key is throttled too) and keep a high per-IP
        // ceiling as a backstop against anyone hammering the endpoint.
        RateLimiter::for('storefront-api', fn (Request $request): array => [
            Limit::perMinute(600)->by('storefront-api:token:'.sha1((string) $request->bearerToken())),
            Limit::perMinute(3000)->by('storefront-api:ip:'.$request->ip()),
        ]);

        // Sign-in, registration, one-time codes and password resets from the
        // Next.js site: every visitor reaches the ERP from the website's one
        // server address, so the limit is per VISITOR (the IP the website
        // forwards as client_ip), not per caller. Wrong passwords, codes and
        // resets are additionally counted per account (CustomerAccountService).
        RateLimiter::for('storefront-auth', fn (Request $request): Limit => Limit::perMinute(20)->by(
            'storefront-auth:'.sha1((string) $request->bearerToken()).':'.($request->input('client_ip') ?: $request->ip())
        ));

        TextColumn::macro('moneyWithoutTrailingZeroes', function (string|\BackedEnum|\Closure|null $currency = null, int|\Closure $divideBy = 0, string|\BackedEnum|\Closure|null $locale = null, int|\Closure|null $decimalPlaces = null): static {
            $this->formatStateUsing(static function (TextColumn $column, $state) use ($currency, $divideBy, $decimalPlaces): ?string {
                if (blank($state)) {
                    return null;
                }

                if (! is_numeric($state)) {
                    return $state;
                }

                $resolvedCurrency = $column->evaluate($currency) ?? $column->getTable()->getDefaultCurrency() ?? 'BDT';
                $resolvedDecimals = $column->evaluate($decimalPlaces) ?? 2;

                if ($resolvedCurrency instanceof \BackedEnum) {
                    $resolvedCurrency = (string) $resolvedCurrency->value;
                }

                if ($resolvedDivideBy = $column->evaluate($divideBy)) {
                    $state /= $resolvedDivideBy;
                }

                return MoneyFormatter::currency($state, (string) $resolvedCurrency, $resolvedDecimals);
            });

            return $this;
        });

        TextEntry::macro('moneyWithoutTrailingZeroes', function (string|\BackedEnum|\Closure|null $currency = null, int|\Closure $divideBy = 0, string|\BackedEnum|\Closure|null $locale = null, int|\Closure|null $decimalPlaces = null): static {
            $this->formatStateUsing(static function (TextEntry $entry, $state) use ($currency, $divideBy, $decimalPlaces): ?string {
                if (blank($state)) {
                    return null;
                }

                if (! is_numeric($state)) {
                    return $state;
                }

                $resolvedCurrency = $entry->evaluate($currency) ?? $entry->getContainer()->getDefaultCurrency() ?? 'BDT';
                $resolvedDecimals = $entry->evaluate($decimalPlaces) ?? 2;

                if ($resolvedCurrency instanceof \BackedEnum) {
                    $resolvedCurrency = (string) $resolvedCurrency->value;
                }

                if ($resolvedDivideBy = $entry->evaluate($divideBy)) {
                    $state /= $resolvedDivideBy;
                }

                return MoneyFormatter::currency($state, (string) $resolvedCurrency, $resolvedDecimals);
            });

            return $this;
        });

        Summarizer::macro('moneyWithoutTrailingZeroes', function (string|\BackedEnum|\Closure|null $currency = null, int $divideBy = 0, string|\BackedEnum|\Closure|null $locale = null, int|\Closure|null $decimalPlaces = null): static {
            $this->formatStateUsing(static function ($state, Summarizer $summarizer) use ($currency, $divideBy, $decimalPlaces): ?string {
                if (blank($state)) {
                    return null;
                }

                if (! is_numeric($state)) {
                    return $state;
                }

                $resolvedCurrency = $summarizer->evaluate($currency) ?? $summarizer->getTable()->getDefaultCurrency() ?? 'BDT';
                $resolvedDecimals = $summarizer->evaluate($decimalPlaces) ?? 2;

                if ($resolvedCurrency instanceof \BackedEnum) {
                    $resolvedCurrency = (string) $resolvedCurrency->value;
                }

                if ($divideBy) {
                    $state /= $divideBy;
                }

                return MoneyFormatter::currency($state, (string) $resolvedCurrency, $resolvedDecimals);
            });

            return $this;
        });

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // App-wide "newest first" default for every Filament table that
        // doesn't set its own ->defaultSort() -- see DefaultTableSort's
        // docblock for why this is needed at all.
        Table::configureUsing(function (Table $table): void {
            $table->defaultSort(fn (Builder $query): string => DefaultTableSort::column($query), 'desc');
        });

        // Every FileUpload gets a label-row "Remove" action so an image whose
        // preview is stuck loading (slow/failing media URL, offline webview)
        // can still be cleared -- FilePond's native "×" only appears once a
        // file has finished loading. See App\Support\FileUploadClearAction.
        // A Closure (not a shared Action instance) so each field gets its own.
        FileUpload::configureUsing(function (FileUpload $upload): void {
            $upload->hintAction(fn () => FileUploadClearAction::make());
        });

        $this->configureCloudStorage();

        // Queue workers are long-lived. Refresh DB-backed storage settings
        // before each worker loop so an admin disk switch does not leave chat
        // media jobs writing with stale credentials until a process restart.
        Queue::looping(function (): void {
            try {
                $settings = $this->app->make(StorageSettingsService::class);
                $settings->forgetCachedSettings();
                $settings->configureNamedDisks();
                $this->app->make(CompanyStorageService::class)->forgetLocations();
            } catch (Throwable) {
                // The worker will surface the actual storage/database error on
                // the job; a failed refresh must not terminate its loop.
            }
        });

        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            $subject = $arguments[0] ?? null;
            $modelClass = is_object($subject) ? $subject::class : $subject;

            if (! is_string($modelClass) || ! array_key_exists($modelClass, User::MODEL_MODULES)) {
                return null;
            }

            // A model whose table is missing (e.g. a failed migration) has its
            // pages turned off instead of failing; super admins are told.
            if (! app(ModuleHealth::class)->modelAvailable($modelClass)) {
                return false;
            }

            if ($subject instanceof User && ! $user->canSeeUser($subject)) {
                return false;
            }

            if ($subject instanceof User && ! in_array($ability, ['view', 'viewAny'], true) && ! $user->canModifyUser($subject)) {
                return false;
            }

            return $user->canPerformModelAbility($ability, $modelClass);
        });

        foreach ([
            Account::class,
            Category::class,
            Company::class,
            Customer::class,
            CustomerPayment::class,
            CustomerBlacklist::class,
            Expense::class,
            ExpenseCategory::class,
            InvestmentProject::class,
            Investor::class,
            Investment::class,
            ProjectCostItem::class,
            InvestorSecurityInstrument::class,
            InvestmentWitness::class,
            InvestmentDocument::class,
            InvestorCycleElection::class,
            InvestmentWithdrawalNotice::class,
            ProjectSettlement::class,
            SettlementPayout::class,
            ChannelPartner::class,
            ChannelPartnerPayout::class,
            InvestorBankAccount::class,
            InvestorRequest::class,
            AgreementTemplate::class,
            PayoutBatch::class,
            PayoutBatchItem::class,
            Order::class,
            OrderItem::class,
            OrderPayment::class,
            OrderCost::class,
            Product::class,
            Purchase::class,
            PurchaseItem::class,
            StockMovement::class,
            StorefrontPage::class,
            StorefrontSetting::class,
            Supplier::class,
            SupplierPayment::class,
            TransactionLedger::class,
            User::class,
        ] as $model) {
            $model::observe(AuditObserver::class);
        }

        Order::observe(OrderNotificationObserver::class);
        Company::observe(CompanyNotificationObserver::class);

        Livewire::component('app.filament.resources.products.pages.list-products', ListProducts::class);
        Livewire::component('app.filament.resources.products.pages.create-product', CreateProduct::class);
        Livewire::component('app.filament.resources.products.pages.edit-product', EditProduct::class);
        Livewire::component('app.filament.resources.category-resource.pages.list-categories', ListCategories::class);
        Livewire::component('app.filament.resources.category-resource.pages.create-category', CreateCategory::class);
        Livewire::component('app.filament.resources.category-resource.pages.edit-category', EditCategory::class);
        Livewire::component('filament.livewire.notifications', Notifications::class);
    }

    /**
     * Hydrate the stable R2 disk names from encrypted database settings.
     * The local "public" and "local" disks retain their configured meaning
     * so legacy objects can still be located and migrated safely.
     *
     * Wrapped defensively because this also runs before fresh installs have
     * a database or app_settings table.
     */
    protected function configureCloudStorage(): void
    {
        try {
            $this->app->make(StorageSettingsService::class)->configureNamedDisks();
        } catch (Throwable) {
            // Database not reachable yet (fresh install, artisan key:generate,
            // etc.) — fall back to the default local "public" disk.
        }
    }
}
