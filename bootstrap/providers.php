<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    App\Providers\SurveyProgressServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
];
