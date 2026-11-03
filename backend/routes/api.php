<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| The app's API: https://api.zon.sa/api/v1 (prefix set in bootstrap/app.php).
| Every response is the envelope of App\Support\Api; every request carries a trace id (X-Request-Id).
| Groups, from open to closed: public → signed in → signed in with a wish (seekers) → per role.
| Ids are numbers; `mine` / `area` routes are declared before the {id} ones.
*/

Route::middleware('throttle:api')->group(function () {

    // ── Public ────────────────────────────────────────────────────────────────────────────────────────────
    Route::get('app/config', [V1\AppController::class, 'config']);
    Route::get('catalog', [V1\AppController::class, 'catalog']);
    Route::get('legal/{slug}', [V1\AppController::class, 'legal']);
    Route::get('packages', [V1\BillingController::class, 'packages']);
    Route::post('payments/webhook/{provider}', [V1\PaymentWebhookController::class, 'handle'])->withoutMiddleware('throttle:api');
    Route::post('store/notices/{store}', [V1\StoreNoticeController::class, 'handle'])->whereIn('store', ['apple', 'google'])->withoutMiddleware('throttle:api');

    Route::prefix('auth')->controller(V1\AuthController::class)->group(function () {
        Route::post('register', 'register')->middleware('throttle:otp');
        Route::post('otp/verify', 'verify');
        Route::post('otp/resend', 'resend')->middleware('throttle:otp');
        Route::post('login', 'login')->middleware('throttle:login');
        Route::post('password/forgot', 'forgot')->middleware('throttle:otp');
        Route::post('password/reset', 'reset')->middleware('throttle:login');
    });

    Route::get('listings', [V1\ListingController::class, 'index']);
    Route::get('listings/{listing}', [V1\ListingController::class, 'show'])->whereNumber('listing');
    Route::get('projects', [V1\ProjectController::class, 'index']);
    Route::get('projects/{project}', [V1\ProjectController::class, 'show'])->whereNumber('project');
    Route::get('projects/{project}/units/{unit}', [V1\ProjectController::class, 'unit'])->whereNumber(['project', 'unit']);
    Route::get('requests', [V1\RequestController::class, 'index']);
    Route::get('requests/{searchRequest}', [V1\RequestController::class, 'show'])->whereNumber('searchRequest');
    Route::get('customers/{customer:username}', [V1\ProfileController::class, 'show']);
    Route::get('customers/{customer:username}/listings', [V1\ProfileController::class, 'listings']);
    Route::post('support-tickets', [V1\SupportController::class, 'store'])->middleware('throttle:reports');

    // ── Signed in ─────────────────────────────────────────────────────────────────────────────────────────
    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);

        Route::prefix('me')->controller(V1\MeController::class)->group(function () {
            Route::get('/', 'show');
            Route::patch('/', 'update');
            Route::post('phone', 'changePhone')->middleware('throttle:otp');
            Route::post('phone/verify', 'verifyPhone');
            Route::put('password', 'changePassword')->middleware('throttle:login');
            Route::put('notification-preferences', 'notificationPrefs');
            Route::post('devices', 'registerDevice');
            Route::post('nafath', 'startNafath')->middleware('throttle:otp');
            Route::get('nafath', 'nafathStatus');
        });

        Route::post('account/delete', [V1\AccountController::class, 'requestDeletion']);
        Route::post('account/delete/cancel', [V1\AccountController::class, 'cancelDeletion']);
        Route::post('account/legacy-claims', [V1\AccountController::class, 'legacyClaim'])->middleware('throttle:reports');

        Route::apiResource('preferences', V1\PreferenceController::class)->except('show')->whereNumber('preference');
        Route::get('notifications', [V1\NotificationController::class, 'index']);
        Route::post('notifications/read', [V1\NotificationController::class, 'read']);
        Route::get('support-tickets', [V1\SupportController::class, 'index']);
        Route::get('support-tickets/{ticket:reference}', [V1\SupportController::class, 'show']);
        Route::post('support-tickets/{ticket:reference}/messages', [V1\SupportController::class, 'reply'])->middleware('throttle:messages');

        // ── … and, for a seeker, with a wish ──────────────────────────────────────────────────────────────
        Route::middleware('preference')->group(function () {
            Route::get('me/followers', [V1\SocialController::class, 'followers']);
            Route::get('me/following', [V1\SocialController::class, 'following']);
            Route::post('customers/{customer:username}/follow', [V1\SocialController::class, 'follow']);
            Route::delete('customers/{customer:username}/follow', [V1\SocialController::class, 'unfollow']);
            Route::post('customers/{customer:username}/rating', [V1\SocialController::class, 'rate']);
            Route::post('customers/{customer:username}/block', [V1\SocialController::class, 'block']);
            Route::delete('customers/{customer:username}/block', [V1\SocialController::class, 'unblock']);

            Route::get('favorites', [V1\FavoriteController::class, 'index']);
            Route::post('favorites', [V1\FavoriteController::class, 'store']);
            Route::delete('favorites/{type}/{id}', [V1\FavoriteController::class, 'destroy'])->whereIn('type', ['listing', 'project'])->whereNumber('id');
            Route::post('reports', [V1\ReportController::class, 'store'])->middleware('throttle:reports');

            Route::get('conversations', [V1\ConversationController::class, 'index']);
            Route::post('conversations', [V1\ConversationController::class, 'store'])->middleware('throttle:messages');
            Route::get('conversations/{conversation}/messages', [V1\ConversationController::class, 'messages'])->whereNumber('conversation');
            Route::post('conversations/{conversation}/messages', [V1\ConversationController::class, 'send'])->whereNumber('conversation')->middleware('throttle:messages');
            Route::post('conversations/{conversation}/read', [V1\ConversationController::class, 'markRead'])->whereNumber('conversation');

            Route::post('media', [V1\MediaController::class, 'store'])->middleware('throttle:uploads');
            Route::delete('media/{media}', [V1\MediaController::class, 'destroy'])->whereNumber('media');

            // Listings of an advertiser (broker · owner · developer).
            Route::get('listings/mine', [V1\ListingController::class, 'mine']);
            Route::middleware('role:broker,owner,developer')->controller(V1\ListingController::class)->group(function () {
                Route::post('listings', 'store')->middleware('throttle:wording');
                Route::patch('listings/{listing}', 'update')->whereNumber('listing')->middleware('throttle:wording');
                Route::delete('listings/{listing}', 'destroy')->whereNumber('listing');
                Route::get('listings/{listing}/review', 'review')->whereNumber('listing');
                Route::post('listings/{listing}/review', 'decide')->whereNumber('listing');
                Route::post('listings/{listing}/pause', 'pause')->whereNumber('listing');
                Route::post('listings/{listing}/resume', 'resume')->whereNumber('listing');
                Route::post('listings/{listing}/licence', 'renewLicence')->whereNumber('listing')->middleware('throttle:licence');
                Route::post('listings/{listing}/sold', 'sold')->whereNumber('listing');
                Route::post('listings/{listing}/boost', 'boost')->whereNumber('listing');
            });

            // Projects of a developer.
            Route::middleware('role:developer')->group(function () {
                Route::get('projects/mine', [V1\ProjectController::class, 'mine']);
                Route::controller(V1\ProjectController::class)->group(function () {
                    Route::post('projects', 'store')->middleware('throttle:wording');
                    Route::patch('projects/{project}', 'update')->whereNumber('project')->middleware('throttle:wording');
                    Route::get('projects/{project}/review', 'review')->whereNumber('project');
                    Route::post('projects/{project}/review', 'decide')->whereNumber('project');
                    Route::post('projects/{project}/publish', 'publish')->whereNumber('project');
                    Route::post('projects/{project}/pause', 'pause')->whereNumber('project');
                    Route::post('projects/{project}/resume', 'resume')->whereNumber('project');
                });
                Route::controller(V1\ProjectUnitController::class)->group(function () {
                    Route::post('projects/{project}/units', 'store')->whereNumber('project')->middleware('throttle:wording');
                    Route::post('projects/{project}/units/review', 'decide')->whereNumber('project');
                    Route::patch('projects/{project}/units/{unit}', 'update')->whereNumber(['project', 'unit'])->middleware('throttle:wording');
                    Route::delete('projects/{project}/units/{unit}', 'destroy')->whereNumber(['project', 'unit']);
                    Route::post('projects/{project}/units/{unit}/licence', 'licence')->whereNumber(['project', 'unit']);
                    Route::post('projects/{project}/units/{unit}/sold', 'sold')->whereNumber(['project', 'unit']);
                });
            });

            // The owner's path.
            Route::middleware('role:owner')->group(function () {
                Route::get('owner-cases', [V1\OwnerCaseController::class, 'index']);
                Route::post('owner-cases', [V1\OwnerCaseController::class, 'store']);
                Route::get('owner-cases/{ownerCase}', [V1\OwnerCaseController::class, 'show'])->whereNumber('ownerCase');
            });

            // Search requests.
            Route::get('requests/mine', [V1\RequestController::class, 'mine']);
            Route::get('requests/area', [V1\RequestController::class, 'area'])->middleware('role:broker');
            Route::post('requests', [V1\RequestController::class, 'store']);
            Route::post('requests/{searchRequest}/close', [V1\RequestController::class, 'close'])->whereNumber('searchRequest');
            Route::post('requests/{searchRequest}/to-client', [V1\RequestController::class, 'toClient'])->whereNumber('searchRequest')->middleware('role:broker,developer');

            // The desk: clients, owners, stock (zero AI except the summary button), tasks.
            Route::middleware('role:broker,developer')->group(function () {
                Route::apiResource('clients', V1\ClientController::class)->whereNumber('client');
                Route::get('clients/{client}/notes', [V1\ClientController::class, 'notes'])->whereNumber('client');
                Route::post('clients/{client}/notes', [V1\ClientController::class, 'addNote'])->whereNumber('client');
                Route::delete('clients/{client}/notes/{note}', [V1\ClientController::class, 'deleteNote'])->whereNumber(['client', 'note']);
                Route::apiResource('inventory', V1\InventoryController::class)->parameters(['inventory' => 'item'])->whereNumber('item');
                Route::post('inventory/{item}/publish', [V1\InventoryController::class, 'publish'])->whereNumber('item')->middleware('throttle:wording');
            });
            Route::middleware('role:broker')->group(function () {
                Route::apiResource('property-owners', V1\PropertyOwnerController::class)->parameters(['property-owners' => 'owner'])->whereNumber('owner');
                Route::get('property-owners/{owner}/notes', [V1\PropertyOwnerController::class, 'notes'])->whereNumber('owner');
                Route::post('property-owners/{owner}/notes', [V1\PropertyOwnerController::class, 'addNote'])->whereNumber('owner');
                Route::delete('property-owners/{owner}/notes/{note}', [V1\PropertyOwnerController::class, 'deleteNote'])->whereNumber(['owner', 'note']);
            });
            Route::apiResource('tasks', V1\TaskController::class)->except('show')->whereNumber('task');
            Route::post('tasks/{task}/done', [V1\TaskController::class, 'done'])->whereNumber('task');

            // Plans and payments.
            Route::get('subscription', [V1\BillingController::class, 'subscription']);
            Route::post('checkout', [V1\BillingController::class, 'checkout']);
            Route::get('payments/{payment}', [V1\BillingController::class, 'payment']);
            Route::post('payments/{payment}/confirm', [V1\BillingController::class, 'confirm']);
            Route::get('store/products', [V1\StoreController::class, 'products']);
            Route::post('store/check', [V1\StoreController::class, 'check']);
            Route::post('store/purchases', [V1\StoreController::class, 'purchase']);
            Route::get('invoices', [V1\BillingController::class, 'invoices']);
            Route::get('invoices/{invoice}', [V1\BillingController::class, 'invoice'])->whereNumber('invoice');

            // The AI services (App\Ai\AiGateway; quotas per account, device and IP).
            Route::prefix('ai')->controller(V1\AiController::class)->group(function () {
                Route::get('quota', 'quota');
                Route::post('search', 'search')->middleware('ai.quota:search');
                Route::post('search/requests', 'searchRequests')->middleware(['role:broker', 'ai.quota:search']);
                Route::post('matching', 'matching')->middleware(['role:seeker,developer', 'ai.quota:matching']);
                Route::post('matching/projects/{project}/searchers', 'searchers')->whereNumber('project')->middleware(['role:developer', 'ai.quota:matching']);
                Route::get('matching/projects/{project}/brokers', 'brokers')->whereNumber('project')->middleware('role:developer');
                Route::post('chat', 'chat')->middleware('ai.quota:chat');
                Route::get('chat', 'chatHistory');
                Route::post('summaries', 'summarize')->middleware('ai.quota:summary');   // client · owner · tasks: checked per scope
                Route::post('feedback', 'feedback');
            });
        });
    });
});
