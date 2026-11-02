<?php

namespace App\Providers;

use App\Models;
use App\Support\Phone;
use App\Support\RequestCache;
use App\Support\Trace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /** The name stored in every *_type column (polymorphic relations, the audit log, tokens, notifications). */
    public const MORPH_MAP = [
        'customer' => Models\Customer::class,
        'user' => Models\User::class,
        'listing' => Models\Listing::class,
        'project' => Models\Project::class,
        'project_unit' => Models\ProjectUnit::class,
        'inventory_item' => Models\InventoryItem::class,
        'request' => Models\SearchRequest::class,
        'preference' => Models\BuyerPreference::class,
        'package' => Models\Package::class,
        'subscription' => Models\Subscription::class,
        'payment' => Models\Payment::class,
        'invoice' => Models\Invoice::class,
        'boost_usage' => Models\BoostUsage::class,
        'owner_case' => Models\OwnerCase::class,
        'ad_license' => Models\AdLicense::class,
        'marketing_contract' => Models\MarketingContract::class,
        'commission_due' => Models\CommissionDue::class,
        'crm_client' => Models\CrmClient::class,
        'property_owner' => Models\PropertyOwner::class,
        'task' => Models\Task::class,
        'conversation' => Models\Conversation::class,
        'message' => Models\Message::class,
        'rating' => Models\Rating::class,
        'content_report' => Models\ContentReport::class,
        'support_ticket' => Models\SupportTicket::class,
        'deletion_request' => Models\DeletionRequest::class,
        'legacy_claim' => Models\LegacyClaim::class,
        'kb_document' => Models\KbDocument::class,
        'search_term' => Models\SearchTerm::class,
        'city' => Models\City::class,
        'neighborhood' => Models\Neighborhood::class,
        'app_config' => Models\AppConfig::class,
        'legal_page' => Models\LegalPage::class,
        'setting' => Models\SettingValue::class,
        'announcement' => Models\Announcement::class,
        'index_event' => Models\IndexEvent::class,
        'exec_report' => Models\ExecReport::class,
        'role' => Role::class,
        'permission' => Permission::class,
    ];

    public function boot(): void
    {
        Relation::enforceMorphMap(self::MORPH_MAP);
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        JsonResource::withoutWrapping();                                   // App\Support\Api wraps
        Password::defaults(fn () => Password::min(8));                     // the app's rule (LocalAuth.passwordError)

        // The panel's superadmin passes every check; the SuperAgent and staff accounts check isSuperadmin() itself.
        Gate::before(fn ($user) => $user instanceof Models\User && $user->isSuperadmin() ? true : null);

        // Every audit entry carries the trace id of the action that wrote it («تتبّع طلب»).
        Activity::creating(function (Activity $activity): void {
            $activity->properties = $activity->properties->put('trace_id', Trace::id());
        });

        // Per-request answers (blocks, entitlements) never outlive their request or job.
        Event::listen(RequestHandled::class, fn () => RequestCache::flush());
        Event::listen(JobProcessing::class, fn () => RequestCache::flush());

        $this->rateLimits();
    }

    /** Limits of the API (backend doc §14.3); nginx limits each IP before PHP as well. */
    private function rateLimits(): void
    {
        $who = fn (Request $request) => $request->user('api')?->getAuthIdentifier() ?? $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('api:'.$who($request)));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by('login:'.mb_strtolower((string) $request->input('login')).'|'.$request->ip()));
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('otp:'.(Phone::normalize((string) $request->input('phone')) ?? 'none')),
            Limit::perHour(10)->by('otp-ip:'.$request->ip()),
        ]);
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(30)->by('messages:'.$who($request)));
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(20)->by('reports:'.$who($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(40)->by('uploads:'.$who($request)));
        // Saving a text sends it to the wording agents (free for the advertiser, not for the platform).
        RateLimiter::for('wording', fn (Request $request) => Limit::perHour(60)->by('wording:'.$who($request)));
        // Each one asks REGA.
        RateLimiter::for('licence', fn (Request $request) => Limit::perHour(20)->by('licence:'.$who($request)));
    }
}
