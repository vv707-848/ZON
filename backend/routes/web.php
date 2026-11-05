<?php

use App\Http\Controllers\Site\RobotsController;
use App\Support\Site\Site;
use Illuminate\Support\Facades\Route;

// The admin panel is mounted by Filament on /admin (App\Providers\Filament\AdminPanelProvider); the website has its
// own host and routes (routes/site.php). robots.txt answers on every host: the website opens its pages to search
// engines, the API's host (api.zon.sa) closes everything.
Route::get('robots.txt', RobotsController::class);

// api.zon.sa/ → the panel. Where the API and the website share one host (local development), the website's home wins.
$apiHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
if ($apiHost !== Site::host()) {
    Route::domain($apiHost)->group(fn () => Route::redirect('/', '/admin'));
}
