<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\IntegrationServiceProvider;

return [
    AppServiceProvider::class,
    IntegrationServiceProvider::class,
    AdminPanelProvider::class,
];
