<?php

namespace App\Filament\Resources\StorefrontPaymentMethods\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StorefrontPaymentMethods\StorefrontPaymentMethodResource;

/**
 * Deleted checkout payment methods (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedStorefrontPaymentMethods extends TrashedRecords
{
    protected static string $resource = StorefrontPaymentMethodResource::class;
}
