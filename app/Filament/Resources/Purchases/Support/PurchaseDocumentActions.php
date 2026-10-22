<?php

namespace App\Filament\Resources\Purchases\Support;

use App\Models\Purchase;
use App\Services\PurchaseWorkflowService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Builds the "Generate PI" / "Generate CI" / "Generate PL" actions shared by
 * the Purchase table row actions and the View/Edit page header actions.
 * Each action fills the document's number/date if still blank (see
 * PurchaseWorkflowService::ensureDocumentNumber()), then opens the PDF
 * route in a new tab — the same $livewire->js('window.open(...)') pattern
 * used for bulk invoice printing in OrdersTable.
 */
class PurchaseDocumentActions
{
    protected const LABELS = [
        'pi' => 'Proforma Invoice',
        'ci' => 'Commercial Invoice',
        'pl' => 'Packing List',
    ];

    public static function make(string $type): Action
    {
        $label = isset(self::LABELS[$type]) ? __(self::LABELS[$type]) : strtoupper($type);

        return Action::make("generate_{$type}")
            ->label(__('Generate :type', ['type' => strtoupper($type)]))
            ->tooltip($label)
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->action(function (Purchase $record, $livewire) use ($type, $label): void {
                if (! self::canGenerate($record, $type)) {
                    Notification::make()
                        ->title(__("Can't generate :document yet", ['document' => $label]))
                        ->body(self::missingRequirementsMessage($record, $type))
                        ->warning()
                        ->send();

                    return;
                }

                app(PurchaseWorkflowService::class)->ensureDocumentNumber($record, $type);

                $url = route('purchases.documents', ['purchase' => $record, 'type' => $type]);
                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    protected static function canGenerate(Purchase $record, string $type): bool
    {
        if (! $record->supplier_id || $record->items->isEmpty()) {
            return false;
        }

        if (in_array($type, ['ci', 'pl'], true) && ! $record->hasShippingDetailsForDocuments()) {
            return false;
        }

        return true;
    }

    protected static function missingRequirementsMessage(Purchase $record, string $type): string
    {
        if (! $record->supplier_id || $record->items->isEmpty()) {
            return __('Add a supplier and at least one item first.');
        }

        return __('Fill in Delivery Terms, Port of Loading, and Port of Discharge (Trade Documents section) first.');
    }
}
