<?php

namespace App\Livewire;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\OrderStockCheckService;
use App\Support\CompanySwitcher;
use App\Support\ModuleGuard;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Resources\Resource;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The phone-width bottom bar of the admin panel (owner request 2026-11-01).
 *
 * It takes over from the header's navigation icons below the large
 * breakpoint, where the sidebar is hidden: Dashboard and the company switch on
 * the left, a raised "+" in the middle, Orders and Products on the right.
 * The "+" opens a small menu — create an order, add a product, a purchase or
 * an expense — and each choice opens the real create form in a popup, so the
 * form is filled in right where the user is, without leaving the page.
 *
 * The popups reuse each resource's own form schema and the same rules as its
 * Create page (order: stock check + first payment account; product: stock
 * from the form), so a record made here is the same record the page makes.
 */
class AdminBottomNavigation extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions {
        callMountedAction as protected callMountedActionWithoutReporting;
    }
    use InteractsWithSchemas;

    private const PANEL = 'admin';

    /** Which tab is the page being viewed (dashboard / orders / products), fixed when the page loads. */
    public string $activeTab = '';

    public function mount(): void
    {
        $this->activeTab = ModuleGuard::run('admin.bottom-navigation.active-tab', fn (): string => $this->resolveActiveTab(), '');
    }

    public function render(): View
    {
        $data = ModuleGuard::run(
            'admin.bottom-navigation',
            fn (): array => $this->viewData(),
            ['hidden' => true],
        );

        return view('filament.partials.admin-bottom-navigation', $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        $tabs = [
            'dashboard' => [
                'label' => __('Dashboard'),
                'icon' => 'heroicon-o-home',
                'url' => Dashboard::getUrl(panel: self::PANEL),
                'visible' => true,
            ],
            'orders' => [
                'label' => __('Orders'),
                'icon' => 'heroicon-o-clipboard-document-list',
                'url' => OrderResource::getUrl('index', panel: self::PANEL),
                'visible' => OrderResource::canViewAny(),
            ],
            'products' => [
                'label' => __('Products'),
                'icon' => 'heroicon-o-cube',
                'url' => ProductResource::getUrl('index', panel: self::PANEL),
                'visible' => ProductResource::canViewAny(),
            ],
        ];

        $quickActions = array_values(array_filter([
            ['name' => 'createOrder', 'label' => __('Create New Order'), 'icon' => 'heroicon-o-shopping-cart', 'allowed' => OrderResource::canCreate()],
            ['name' => 'createProduct', 'label' => __('Add New Product'), 'icon' => 'heroicon-o-cube', 'allowed' => ProductResource::canCreate()],
            ['name' => 'createPurchase', 'label' => __('Add New Purchase'), 'icon' => 'heroicon-o-shopping-bag', 'allowed' => PurchaseResource::canCreate()],
            ['name' => 'createExpense', 'label' => __('Add New Expense'), 'icon' => 'heroicon-o-receipt-percent', 'allowed' => ExpenseResource::canCreate()],
        ], fn (array $action): bool => $action['allowed']));

        return [
            'hidden' => false,
            'tabs' => $tabs,
            'activeTab' => $this->activeTab,
            'quickActions' => $quickActions,
            'company' => CompanySwitcher::for(auth()->user()),
        ];
    }

    protected function resolveActiveTab(): string
    {
        $current = rtrim(url()->current(), '/');

        $isAt = fn (string $url): bool => $current === rtrim($url, '/')
            || str_starts_with($current, rtrim($url, '/').'/');

        return match (true) {
            $current === rtrim(Dashboard::getUrl(panel: self::PANEL), '/') => 'dashboard',
            $isAt(OrderResource::getUrl('index', panel: self::PANEL)) => 'orders',
            $isAt(ProductResource::getUrl('index', panel: self::PANEL)) => 'products',
            default => '',
        };
    }

    /**
     * An order's items are saved after the order itself, and a stock movement
     * rejected there raises its error under a key such as "quantity" that no
     * field of the form owns — so nothing showed and the save just seemed to do
     * nothing (the same trap ReportsStockErrors closes on the Create page).
     * Show those errors as a notification; errors that belong to a form field
     * stay on the field.
     */
    public function callMountedAction(array $arguments = []): mixed
    {
        try {
            return $this->callMountedActionWithoutReporting($arguments);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $fieldErrors = array_filter($errors, fn (string $key): bool => str_starts_with($key, 'mountedActions.'), ARRAY_FILTER_USE_KEY);

            if (count($fieldErrors) === count($errors)) {
                throw $exception;
            }

            Notification::make()
                ->title(__('The record could not be saved'))
                ->body(collect($errors)->flatten()->unique()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            if ($fieldErrors !== []) {
                $this->setErrorBag($fieldErrors);
            }

            return null;
        }
    }

    public function createOrderAction(): CreateAction
    {
        return $this->quickCreate('createOrder', Order::class, OrderResource::class)
            ->modalHeading(__('Create New Order'))
            ->modalSubmitActionLabel(__('Create Order'))
            ->successNotificationTitle(__('Order created'))
            // The order, its items and their stock movements are written by
            // separate model hooks; one failing must not leave half an order.
            ->databaseTransaction()
            ->before(function (Action $action): void {
                $this->haltWhenStockIsShort($action);
            })
            ->using(function (array $data, string $model): Model {
                /** @var Order $record */
                $record = new $model($data);

                // The "Payment Method" account is not an order column; hand it
                // to the order so its first Payments History row posts into it.
                $accountId = $this->mountedActionState()['payment_account_id'] ?? null;
                $record->initialPaymentAccountId = filled($accountId) ? (int) $accountId : null;
                $record->save();

                return $record;
            });
    }

    public function createProductAction(): CreateAction
    {
        $requestedStock = 0;

        return $this->quickCreate('createProduct', Product::class, ProductResource::class)
            ->modalHeading(__('Add New Product'))
            ->modalSubmitActionLabel(__('Add Product'))
            ->successNotificationTitle(__('Product added'))
            ->using(function (array $data, string $model) use (&$requestedStock): Model {
                // Same as CreateProduct: the sale price is the price, and the
                // stock typed in the form becomes an opening-stock movement
                // once the product (and its variants) exist.
                $data['price'] = $data['sale_price'];
                $requestedStock = (int) ($data['stock'] ?? 0);
                unset($data['stock']);

                $record = new $model($data);
                $record->save();

                return $record;
            })
            ->after(function (Model $record) use (&$requestedStock): void {
                /** @var Product $record */
                $record->setStockFromProductForm($requestedStock);
            });
    }

    public function createPurchaseAction(): CreateAction
    {
        return $this->quickCreate('createPurchase', Purchase::class, PurchaseResource::class)
            ->modalHeading(__('Add New Purchase'))
            ->modalSubmitActionLabel(__('Add Purchase'))
            ->successNotificationTitle(__('Purchase added'));
    }

    public function createExpenseAction(): CreateAction
    {
        return $this->quickCreate('createExpense', Expense::class, ExpenseResource::class)
            ->modalHeading(__('Add New Expense'))
            ->modalSubmitActionLabel(__('Add Expense'))
            ->successNotificationTitle(__('Expense added'))
            ->modalWidth(Width::FourExtraLarge);
    }

    /**
     * One popup per "+" menu entry: the resource's own create form, shown in a
     * modal. After saving, the user lands on that resource's list, as the
     * Create page does.
     *
     * @param  class-string<Model>  $model
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    protected function quickCreate(string $name, string $model, string $resource): CreateAction
    {
        return CreateAction::make($name)
            ->model($model)
            ->authorize(fn (): bool => $resource::canCreate())
            ->createAnother(false)
            ->modalWidth(Width::SevenExtraLarge)
            // The resource forms branch on the operation ("create" shows the
            // payment account, "edit" locks fields); outside a Create page the
            // schema would otherwise report the Livewire class name.
            ->schema(fn (Schema $schema): Schema => $resource::form($schema->operation('create')))
            ->successRedirectUrl(fn (): string => $resource::getUrl('index', panel: self::PANEL));
    }

    /**
     * The state of the form in the open popup, as typed (not yet dehydrated).
     *
     * @return array<string, mixed>
     */
    protected function mountedActionState(): array
    {
        return $this->getMountedActionSchema()?->getRawState() ?? [];
    }

    /**
     * A confirmed / processing / completed order takes its items out of stock.
     * When any product is short, stop before saving and list them — the full
     * order form can also add the missing stock, this popup only reports it.
     */
    protected function haltWhenStockIsShort(Action $action): void
    {
        $state = $this->mountedActionState();

        if (! in_array($state['status'] ?? null, Order::ACCOUNTED_STATUSES, true)) {
            return;
        }

        $shortages = app(OrderStockCheckService::class)->shortages($state['items'] ?? []);

        if ($shortages === []) {
            return;
        }

        $lines = collect($shortages)
            ->map(fn (array $shortage): string => $shortage['name'].' — '.__('Ordered: :ordered · In stock: :available · Short by: :short', [
                'ordered' => $shortage['ordered'],
                'available' => $shortage['available'],
                'short' => $shortage['short'],
            ]))
            ->push(__('Lower the quantities, save the order as Draft, or use the full order form to add the missing stock.'))
            ->map(fn (string $line): string => e($line));

        Notification::make()
            ->title(__('Not enough stock'))
            ->body(new HtmlString($lines->implode('<br>')))
            ->danger()
            ->persistent()
            ->actions([
                Action::make('openFullOrderForm')
                    ->label(__('Open full order form'))
                    ->button()
                    ->url(OrderResource::getUrl('create', panel: self::PANEL)),
            ])
            ->send();

        $action->halt(shouldRollBackDatabaseTransaction: true);
    }
}
