<?php

use App\Http\Controllers\Site;
use Illuminate\Support\Facades\Route;

/*
| The website (https://zon.sa, config zon.deep_link_base), served by the same backend on its own host
| (bootstrap/app.php). Paths of shared pages are the app's own (/listings/{id}, /projects/{id}, /users/{name},
| /requests/{id}), so a shared link opens the app where it is installed (apple-app-site-association,
| assetlinks.json) and this page everywhere else.
|
| Reading pages carry no session (group «site»): a crawler visiting thousands of listings leaves nothing behind.
*/

Route::middleware('site')->group(function () {
    Route::get('/', Site\HomeController::class)->name('site.home');

    Route::get('listings', [Site\ListingPageController::class, 'index'])->name('site.listings');
    Route::get('listings/{listing}', [Site\ListingPageController::class, 'show'])->whereNumber('listing')->name('site.listing');
    Route::get('listings/{listing}/image', [Site\ListingPageController::class, 'image'])->whereNumber('listing')->name('site.listing.image');

    Route::get('projects/{project}', [Site\ProjectPageController::class, 'show'])->whereNumber('project')->name('site.project');
    Route::get('projects/{project}/image', [Site\ProjectPageController::class, 'image'])->whereNumber('project')->name('site.project.image');
    Route::get('projects/{project}/units/{unit}', [Site\ProjectPageController::class, 'unit'])->whereNumber(['project', 'unit'])->name('site.unit');

    Route::get('users/{customer:username}', Site\ProfilePageController::class)->name('site.profile');
    Route::get('requests/{searchRequest}', Site\RequestPageController::class)->whereNumber('searchRequest')->name('site.request');

    Route::get('legal', [Site\LegalPageController::class, 'index'])->name('site.legal');
    Route::get('legal/{slug}', [Site\LegalPageController::class, 'show'])->name('site.legal.page');

    Route::get('sitemap.xml', [Site\SitemapController::class, 'index'])->name('site.sitemap');
    Route::get('sitemaps/{part}.xml', [Site\SitemapController::class, 'part'])->where('part', 'pages|listings-\d+|projects|users')->name('site.sitemap.part');

    Route::get('.well-known/apple-app-site-association', [Site\WellKnownController::class, 'apple']);
    Route::get('.well-known/assetlinks.json', [Site\WellKnownController::class, 'android']);
});

// Forms: a session and CSRF protection (group «web»), and the website's headers.
Route::middleware(['web', 'site.headers'])->group(function () {
    // REGA's rule (Art. 8(8) of the marketing and advertising regulation, 1 May 2026): a complaint form, its number
    // and date, the actions taken and the processing time.
    Route::get('complaints', [Site\ComplaintController::class, 'create'])->name('site.complaints');
    Route::post('complaints', [Site\ComplaintController::class, 'store'])->middleware('throttle:site-forms');
    Route::get('complaints/track', [Site\ComplaintController::class, 'lookup'])->name('site.complaints.track');
    Route::post('complaints/track', [Site\ComplaintController::class, 'find'])->middleware('throttle:site-lookup');
    Route::get('complaints/{ticket:reference}', [Site\ComplaintController::class, 'show'])->middleware('signed')->name('site.complaint');
    Route::post('complaints/{ticket:reference}', [Site\ComplaintController::class, 'reply'])->middleware(['signed', 'throttle:site-forms'])->name('site.complaint.reply');
});

// The app's account on the website: signing in (to buy a plan here), and «حذف الحساب» (Google Play's rule: a web
// page where an account is deleted without the app).
Route::middleware(['web', 'site.headers'])->group(function () {
    Route::get('login', [Site\AuthController::class, 'form'])->name('site.login');
    Route::post('login', [Site\AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('logout', [Site\AuthController::class, 'logout'])->name('site.logout');
    Route::get('password/forgot', [Site\AuthController::class, 'forgotForm'])->name('site.password.forgot');
    Route::post('password/forgot', [Site\AuthController::class, 'forgot'])->middleware('throttle:otp');
    Route::get('password/reset', [Site\AuthController::class, 'resetForm'])->name('site.password.reset');
    Route::post('password/reset', [Site\AuthController::class, 'reset'])->middleware('throttle:login');

    Route::get('account', [Site\AccountController::class, 'show'])->name('site.account');
    Route::get('account/delete', [Site\AccountController::class, 'deletion'])->name('site.account.delete');
    Route::post('account/delete', [Site\AccountController::class, 'delete'])->middleware('throttle:login');
});
