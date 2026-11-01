<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Courier;
use App\Models\Order;
use App\Services\OrderStatusWorkflowService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Scan parcels (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.8): scan or type invoice
 * numbers, then move the whole batch to one stage. Every order goes through
 * OrderStatusWorkflowService, so each allowed transition and each status
 * log still applies; an order that cannot make the move is skipped with the
 * reason. A USB scanner types the number and presses Enter; a phone camera
 * reads the barcode printed on the invoice.
 */
class ScanParcels extends Page
{
    protected static ?string $cluster = Courier::class;

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected string $view = 'filament.pages.scan-parcels';

    public string $code = '';

    /** @var list<int> newest first */
    public array $ids = [];

    public string $stage = Order::STAGE_SHIPPING;

    /** @var list<string> */
    public array $skipped = [];

    public static function getNavigationLabel(): string
    {
        return __('Scan parcels');
    }

    public function getTitle(): string
    {
        return __('Scan parcels');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('sales.update') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Stages a batch may be moved to; the ones that need a reason are left to the order itself. */
    public function stageOptions(): array
    {
        return collect(Order::WORKFLOW_STAGES)
            ->except(Order::REASON_REQUIRED_STAGES)
            ->map(fn (string $label): string => __($label))
            ->all();
    }

    public function scan(): void
    {
        abort_unless(static::canAccess(), 403);

        $code = trim($this->code);
        $this->code = '';
        if ($code === '') {
            return;
        }

        $order = Order::query()->whereRaw('LOWER(order_number) = ?', [mb_strtolower($code)])->first();
        if (! $order) {
            Notification::make()->title(__('No order has the number :number.', ['number' => $code]))->danger()->send();

            return;
        }
        if (in_array($order->getKey(), $this->ids, true)) {
            Notification::make()->title(__(':number is already in the list.', ['number' => $order->order_number]))->warning()->send();

            return;
        }

        array_unshift($this->ids, $order->getKey());
        $this->skipped = [];
    }

    public function remove(int $id): void
    {
        $this->ids = array_values(array_diff($this->ids, [$id]));
    }

    public function clear(): void
    {
        $this->ids = [];
        $this->skipped = [];
    }

    /** @return Collection<int, Order> the scanned orders, newest scan first */
    public function orders(): Collection
    {
        if ($this->ids === []) {
            return collect();
        }

        $orders = Order::query()->whereIn('id', $this->ids)->with('customer:id,name,phone')->get()->keyBy('id');

        return collect($this->ids)->map(fn (int $id) => $orders->get($id))->filter()->values();
    }

    public function move(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! array_key_exists($this->stage, $this->stageOptions())) {
            return;
        }

        $moved = 0;
        $skipped = [];
        foreach ($this->orders() as $order) {
            // A scan only moves a parcel on; going back to an earlier stage is done from the order itself.
            if (in_array($this->stage, $order->stepBackWorkflowStages(), true)) {
                $skipped[] = $order->order_number.': '.__('A scan only moves an order forward. Use Change status on the order to go back.');

                continue;
            }

            try {
                app(OrderStatusWorkflowService::class)->transition($order, $this->stage, null, 'scan');
                $moved++;
                $this->ids = array_values(array_diff($this->ids, [$order->getKey()]));
            } catch (ValidationException $exception) {
                $skipped[] = $order->order_number.': '.collect($exception->errors())->flatten()->implode(' ');
            }
        }
        $this->skipped = $skipped;

        $notification = Notification::make()->title(__(':count order(s) moved to :stage', ['count' => $moved, 'stage' => __(Order::WORKFLOW_STAGES[$this->stage])]));
        if ($moved === 0) {
            $notification->danger();
        } elseif ($skipped !== []) {
            $notification->warning()->body(__(':count order(s) were skipped:', ['count' => count($skipped)]).' '.implode(' | ', $skipped));
        } else {
            $notification->success();
        }
        $notification->send();
    }
}
