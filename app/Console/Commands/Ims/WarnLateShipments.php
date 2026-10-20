<?php

namespace App\Console\Commands\Ims;

use App\Models\Company;
use App\Models\InvestmentProject;
use App\Models\Shipment;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Hourly: a shipment of a project's purchases that is past its expected date
 * and has not arrived is reported to the office once (TRD §6).
 */
class WarnLateShipments extends ImsCompanyCommand
{
    protected $signature = 'ims:shipment-delays {--company= : Company id (default: every company with IMS on)}';

    protected $description = 'IMS v2: tell the office about late project shipments (once each).';

    protected function job(): string
    {
        return 'shipment_delays';
    }

    protected function runFor(Company $company): int
    {
        $purchaseIds = InvestmentProject::query()->where('status', '!=', 'settled')->get()
            ->flatMap(fn (InvestmentProject $project): array => $project->allPurchaseIds())->unique()->values()->all();

        if ($purchaseIds === []) {
            return 0;
        }

        $late = Shipment::query()->whereIn('purchase_id', $purchaseIds)
            ->whereNull('received_at')->whereNotIn('status', ['received', 'cancelled'])
            ->whereNotNull('estimated_arrival')->where('estimated_arrival', '<', now()->toDateString())
            ->whereNull('ims_delay_alerted_at')
            ->get();

        if ($late->isEmpty()) {
            return 0;
        }

        $staff = $company->users()->where('users.is_active', true)->get()
            ->filter(fn (User $user): bool => $user->hasPermission('ims.updates.manage'));

        foreach ($late as $shipment) {
            foreach ($staff as $user) {
                Notification::make()
                    ->title(__('A shipment is late'))
                    ->body(__('It was expected on :date and has not arrived. Tell the project\'s investors with an update.', ['date' => $shipment->estimated_arrival?->toDateString()]))
                    ->warning()
                    ->sendToDatabase($user);
            }

            $shipment->forceFill(['ims_delay_alerted_at' => now()])->saveQuietly();
        }

        return $late->count();
    }
}
