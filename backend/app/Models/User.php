<?php

namespace App\Models;

use App\Enums\StaffType;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of staff (the admin panel). Never an app account: those are customers.
 * Two-factor login by an authenticator app is mandatory (AdminPanelProvider); the superadmin passes every
 * permission check (AppServiceProvider Gate::before) and alone sees the SuperAgent and the staff accounts.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return [
            'type' => StaffType::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'last_login_at' => 'datetime',
        ];
    }

    public function isSuperadmin(): bool
    {
        return $this->type === StaffType::Superadmin;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }
}
