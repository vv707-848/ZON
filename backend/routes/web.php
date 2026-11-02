<?php

// The admin panel is mounted by Filament on /admin (App\Providers\Filament\AdminPanelProvider); nothing else is
// served as HTML.
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
