<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Enums\NafathStatus;
use App\Enums\NoticePref;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An account of the app: seeker · broker · owner · developer. Signs in with a Sanctum token (guard `api`).
 * Phone is E.164 and verified by SMS before the account exists (App\Actions\Accounts\Register).
 */
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'signup_device_hash', 'signup_ip_hash'];

    /** The table's defaults, so a new model reads them before it is reloaded. */
    protected $attributes = ['status' => 'active', 'fal_status' => 'none', 'nafath_status' => 'none', 'is_tester' => false];

    protected function casts(): array
    {
        return [
            'role' => CustomerRole::class,
            'status' => AccountStatus::class,
            'fal_status' => FalStatus::class,
            'nafath_status' => NafathStatus::class,
            'password' => 'hashed',
            'is_tester' => 'boolean',
            'notification_prefs' => 'array',
            'phone_verified_at' => 'datetime',
            'fal_checked_at' => 'datetime',
            'fal_expires_at' => 'date',
            'nafath_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function hasRole(CustomerRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /** A switch of the app's notification settings (on unless turned off). */
    public function wants(NoticePref $pref): bool
    {
        return (bool) ($this->notification_prefs[$pref->value] ?? true);
    }

    /** @return array<string, bool> all six switches */
    public function noticePrefs(): array
    {
        $prefs = [];
        foreach (NoticePref::cases() as $pref) {
            $prefs[$pref->value] = $this->wants($pref);
        }

        return $prefs;
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'developer_id');
    }

    /** @return HasMany<SearchRequest, $this> */
    public function searchRequests(): HasMany
    {
        return $this->hasMany(SearchRequest::class);
    }

    /** @return HasMany<BuyerPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(BuyerPreference::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<CustomerDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(CustomerDevice::class);
    }

    /** @return HasMany<CrmClient, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(CrmClient::class, 'broker_id');
    }

    /** @return HasMany<PropertyOwner, $this> */
    public function propertyOwners(): HasMany
    {
        return $this->hasMany(PropertyOwner::class, 'broker_id');
    }

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'broker_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<OwnerCase, $this> */
    public function ownerCases(): HasMany
    {
        return $this->hasMany(OwnerCase::class);
    }

    /** @return HasMany<MarketingContract, $this> */
    public function marketingContracts(): HasMany
    {
        return $this->hasMany(MarketingContract::class, 'developer_id');
    }

    /** @return HasMany<Rating, $this> */
    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'rated_id');
    }

    /** @return BelongsToMany<Customer, $this> accounts following this one */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')->withPivot('created_at');
    }

    /** @return BelongsToMany<Customer, $this> accounts this one follows */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')->withPivot('created_at');
    }

    /** @return HasOne<DeletionRequest, $this> the deletion waiting for its grace period to end */
    public function pendingDeletion(): HasOne
    {
        return $this->hasOne(DeletionRequest::class)->whereNull('cancelled_at')->whereNull('purged_at')->latestOfMany();
    }

    /** @return list<string> FCM tokens of the account's phones (Integrations\Push) */
    public function routeNotificationForPush(): array
    {
        return $this->devices()->pluck('push_token')->all();
    }
}
