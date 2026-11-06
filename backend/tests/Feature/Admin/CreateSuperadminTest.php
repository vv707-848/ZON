<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffType;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** `php artisan zon:create-superadmin`: the panel's first account, its password asked for and stored hashed. */
class CreateSuperadminTest extends TestCase
{
    private const PROMPT = 'كلمة السر (12 حرفاً على الأقل)';

    public function test_the_first_superadmin_is_created_once_with_a_long_hashed_password(): void
    {
        $this->artisan('zon:create-superadmin', ['email' => 'ceo@zon.sa'])->expectsQuestion(self::PROMPT, 'short')->assertFailed();
        $this->assertFalse(User::query()->where('email', 'ceo@zon.sa')->exists(), 'a short password creates nothing');

        $this->artisan('zon:create-superadmin', ['email' => 'ceo@zon.sa'])->expectsQuestion(self::PROMPT, 'a-long-enough-secret')->assertSuccessful();
        $admin = User::query()->where('email', 'ceo@zon.sa')->sole();
        $this->assertSame([StaffType::Superadmin, true], [$admin->type, $admin->is_active]);
        $this->assertTrue(Hash::check('a-long-enough-secret', $admin->password));
        $this->assertNotSame('a-long-enough-secret', $admin->getRawOriginal('password'));

        $this->artisan('zon:create-superadmin', ['email' => 'ceo@zon.sa'])->expectsQuestion(self::PROMPT, 'another-long-secret')->assertFailed();
        $this->assertSame(1, User::query()->where('email', 'ceo@zon.sa')->count(), 'one account per e-mail');
    }
}
