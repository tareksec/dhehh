<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ResellerPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ResellerPanelProvider::class,
];
