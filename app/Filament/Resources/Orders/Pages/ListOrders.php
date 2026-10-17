<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\NuportOrderImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importNuportOrdersAction(),

            // The marker class keeps "New order" on the same row as the page
            // title on mobile (see .zz-orders-mobile-header-action in
            // resources/css/filament/admin/theme.css); Filament otherwise
            // stacks the header below its own sm breakpoint.
            CreateAction::make()
                ->extraAttributes(['class' => 'zz-orders-mobile-header-action']),
        ];
    }

    /**
     * Copies past orders (what each customer bought, payment, status) out of
     * Nuport's "Export As CSV" file. Starts as a preview that saves nothing so
     * the owner can check the counts, unmatched products and totals first.
     */
    protected function importNuportOrdersAction(): Action
    {
        $canImport = fn (): bool => (bool) (auth()->user()?->canPerformModelAbility('create', Order::class)
            && auth()->user()?->canPerformModelAbility('update', Order::class));

        return Action::make('importNuportOrders')
            ->label(__('Import Nuport orders'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible($canImport)
            ->modalHeading(__('Import Nuport orders'))
            ->modalDescription(__('Past orders from Nuport are copied in as history. Stock and account balances are never changed.'))
            ->modalSubmitActionLabel(__('Start'))
            ->schema([
                FileUpload::make('csv')
                    ->label(__('Nuport orders CSV'))
                    ->disk('local')
                    ->directory('imports/nuport-orders')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->required()
                    ->helperText(__('In Nuport open Orders, then Action, then Export As CSV. Uploading a newer export again never duplicates orders.')),
                Toggle::make('preview_only')
                    ->label(__('Preview only (save nothing)'))
                    ->helperText(__('Turn this off to save the orders.'))
                    ->default(true),
            ])
            ->action(function (array $data, NuportOrderImportService $service) use ($canImport): void {
                abort_unless($canImport(), 403);

                $file = is_array($data['csv'] ?? null) ? reset($data['csv']) : ($data['csv'] ?? null);

                if (! $file) {
                    return;
                }

                $preview = (bool) ($data['preview_only'] ?? true);

                try {
                    $report = $service->run(Storage::disk('local')->path($file), dryRun: $preview);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Nuport import failed'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($file);
                }

                $notification = Notification::make()
                    ->title($preview ? __('Nuport import preview') : __('Nuport orders imported'))
                    ->body(new HtmlString(collect($service->summary($report))->map(fn (string $line): string => e($line))->implode('<br>')))
                    ->persistent();

                if ($preview || $report['errors'] !== []) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * Order-status quick-filter tabs above the table (Nuport-style order
     * dashboard): "All" plus one tab per Order::STATUSES value, each showing
     * a live count and scoping the list to that status when clicked. The
     * Status column filter in OrdersTable is kept so dashboard "see all"
     * deep links (?filters[status]=…) and filter-combining still work.
     */
    public function getTabs(): array
    {
        $counts = Order::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tabs = [
            'all' => Tab::make('All')->badge($counts->sum()),
        ];

        foreach (Order::STATUSES as $value => $label) {
            $tabs[$value] = Tab::make($label)
                ->badge($counts->get($value, 0))
                ->badgeColor(self::statusTabColor($value))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $value));
        }

        return $tabs;
    }

    protected static function statusTabColor(string $status): string
    {
        return match ($status) {
            Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED => 'success',
            Order::STATUS_PROCESSING => 'warning',
            Order::STATUS_CANCELLED, Order::STATUS_RETURNED => 'danger',
            default => 'gray',
        };
    }
}
