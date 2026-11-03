<?php

namespace App\Actions\Admin;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Filament\Support\Audit;
use App\Jobs\CheckBrokerLicence;
use App\Models\Customer;
use App\Models\User;
use App\Support\Sandbox;
use Illuminate\Support\Str;
use LogicException;

/**
 * «حساب مراجعة»: an account for the stores' reviewers, or for testing in production, made by the superadmin only.
 * It signs in with its username and a password shown once (the stores ask for both), and lives in the sandbox
 * from its first second (Sandbox): nothing it does reaches a real user. Its phone is one the superadmin answers,
 * for a code the reviewer may ask for. Nothing is waived: a broker's FAL goes to REGA, a listing needs its licence.
 */
final class MakeReviewAccount
{
    /** The roles a reviewer can try without a contract: a developer starts pending until his marketing contract. */
    public const ROLES = [CustomerRole::Seeker, CustomerRole::Broker, CustomerRole::Owner];

    /**
     * @param  array{role: string, name: string, username: string, phone: string, city: string, fal_number?: ?string}  $form  phone in E.164
     * @return array{0: Customer, 1: string} the account and its password — shown once, kept nowhere
     */
    public function __invoke(array $form, User $by): array
    {
        $role = CustomerRole::from($form['role']);
        if (! in_array($role, self::ROLES, true)) {
            throw new LogicException("A review account cannot be a {$role->value}.");
        }
        $fal = $role === CustomerRole::Broker ? (trim((string) ($form['fal_number'] ?? '')) ?: null) : null;
        $password = self::password();

        $customer = new Customer;
        $customer->forceFill([
            'role' => $role,
            'name' => trim($form['name']),
            'username' => trim($form['username']),
            'phone' => $form['phone'],
            'phone_verified_at' => now(),
            'city' => $form['city'],
            'password' => $password,                 // hashed by the model's cast
            'status' => AccountStatus::Active,
            'fal_number' => $fal,
            'fal_status' => $fal === null ? FalStatus::None : FalStatus::Pending,
            'is_tester' => true,
        ])->save();
        Sandbox::forget();

        Audit::log('review_account_created', 'إنشاء حساب مراجعة', $customer, ['role' => $role->value, 'username' => $customer->username], $by);
        if ($fal !== null) {
            CheckBrokerLicence::dispatch($customer->id);
        }

        return [$customer, $password];
    }

    /** A new password for a review account (the old one stops working, and every device is signed out). */
    public function newPassword(Customer $customer, User $by): string
    {
        if (! $customer->is_tester) {
            throw new LogicException('Only a review account gets its password from the panel.');
        }
        $password = self::password();
        $customer->forceFill(['password' => $password])->save();
        $customer->tokens()->delete();
        Audit::log('review_password_reset', 'كلمة سر جديدة لحساب مراجعة', $customer, by: $by);

        return $password;
    }

    /** Sixteen letters and digits: typed by a reviewer from the review notes, strong enough for production. */
    private static function password(): string
    {
        return Str::password(16, symbols: false);
    }
}
