<?php

namespace App\Support;

use App\Filament\Resources\Vouchers\VoucherResource;
use Filament\Facades\Filament;

/**
 * Links drawn by the ERP's investor screens, which are shown in two panels:
 * the ERP admin and the IMS office (IMS-V2/ARCHITECTURE.md §4). The office
 * lives on the IMS domain, where the ERP's /admin addresses do not open, so
 * file downloads use the office's own copy of the route, and a voucher (an
 * ERP accounting record) opens in the ERP admin on the ERP's own address.
 */
class ImsPanelLinks
{
    /** The office copies of the ERP's investor download routes. */
    public const FILE_ROUTES = [
        'investment-documents.download',
        'investor-security-instruments.contract',
        'investments.reports.investor-payout',
        'investments.reports.project-register',
        'investor-portal.open-investor',
        'investor-portal.open-partner',
    ];

    public static function inOffice(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'office';
    }

    /** route($name, $parameters), or the office's copy of it while in the office. */
    public static function route(string $name, mixed $parameters = []): string
    {
        if (static::inOffice() && in_array($name, self::FILE_ROUTES, true)) {
            return route('filament.office.files.'.$name, $parameters);
        }

        return route($name, $parameters);
    }

    /** A voucher's page in the ERP admin; from the office, on the ERP's own address. */
    public static function voucher(int|string|null $voucherId): ?string
    {
        if (blank($voucherId)) {
            return null;
        }

        if (! static::inOffice()) {
            return VoucherResource::getUrl('view', ['record' => $voucherId]);
        }

        return rtrim((string) config('app.url'), '/').VoucherResource::getUrl('view', ['record' => $voucherId], isAbsolute: false, panel: 'admin');
    }
}
