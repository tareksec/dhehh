<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ImsOfficePanelProvider;
use App\Providers\Filament\InvestorPanelProvider;
use App\Providers\Filament\ResellerPanelProvider;
use App\Providers\Filament\TradePortalPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ResellerPanelProvider::class,
    InvestorPanelProvider::class,
    ImsOfficePanelProvider::class,
    TradePortalPanelProvider::class,
];
