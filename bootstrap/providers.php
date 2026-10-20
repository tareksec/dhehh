<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ImsOfficePanelProvider;
use App\Providers\Filament\InvestorPanelProvider;
use App\Providers\Filament\ResellerPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ResellerPanelProvider::class,
    InvestorPanelProvider::class,
    ImsOfficePanelProvider::class,
];
