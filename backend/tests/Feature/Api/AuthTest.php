<?php

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Enums\FalStatus;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\DeletionRequest;
use App\Models\Listing;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Sign-up (form → code → account), sign-in by username or phone, forgotten password, and the app gates. */
class AuthTest extends TestCase
{
    private function signUp(array $form = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', $form + [
            'role' => 'seeker', 'name' => 'محمد', 'phone' => '٠٥٥١٢٣٤٥٦٧', 'city' => 'الرياض', 'username' => 'mohammed.s', 'password' => 'secret-123',
        ]);
    }

    public function test_an_account_exists_only_after_its_phone_code(): void
    {
        $this->signUp()->assertStatus(202)->assertJsonPath('data.phone', '0551234567');
        $this->assertDatabaseCount('customers', 0);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0551234567', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+966551234567', 'code' => $this->lastCode('+966551234567'), 'push_token' => 'tok-1', 'platform' => 'ios'])
            ->assertCreated()
            ->assertJsonPath('data.me.username', 'mohammed.s')
            ->assertJsonPath('data.me.phone', '0551234567')
            ->assertJsonPath('data.me.has_preference', false)
            ->assertJsonStructure(['data' => ['token']]);

        $customer = Customer::query()->sole();
        $this->assertSame('+966551234567', $customer->phone);
        $this->assertSame('tok-1', $customer->devices()->value('push_token'));
    }

    public function test_sign_up_rules_follow_the_app(): void
    {
        Customer::factory()->create(['username' => 'taken']);

        $this->signUp(['username' => 'taken', 'phone' => '0512', 'city' => 'باريس', 'password' => 'short', 'role' => 'broker'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['username', 'phone', 'city', 'password', 'fal_number']], 'trace_id']]);
    }

    public function test_a_developer_waits_for_his_contract_and_a_broker_licence_is_checked(): void
    {
        $this->signUp(['role' => 'developer', 'username' => 'afaq', 'phone' => '0551111111']);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0551111111', 'code' => $this->lastCode('+966551111111')])
            ->assertCreated()->assertJsonPath('data.me.status', 'pending');

        $this->signUp(['role' => 'broker', 'username' => 'fahad', 'phone' => '0552222222', 'fal_number' => '١١٠٠٠١٢٣٤٥']);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0552222222', 'code' => $this->lastCode('+966552222222')])->assertCreated();

        $broker = Customer::query()->where('username', 'fahad')->sole();
        $this->assertSame('1100012345', $broker->fal_number);
        $this->assertSame(FalStatus::Valid, $broker->fal_status, 'checked with REGA (simulated) right after sign-up');
        $this->assertSame(AccountStatus::Active, $broker->status);
    }

    public function test_sign_in_with_the_username_or_the_phone(): void
    {
        Customer::factory()->create(['username' => 'sara', 'phone' => '+966553333333', 'password' => 'secret-123']);

        $this->postJson('/api/v1/auth/login', ['login' => 'sara', 'password' => 'secret-123'])->assertOk()->assertJsonPath('data.me.username', 'sara');
        $this->postJson('/api/v1/auth/login', ['login' => '0553333333', 'password' => 'secret-123'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'sara', 'password' => 'wrong-pass'])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_a_suspended_account_is_signed_out_everywhere(): void
    {
        $customer = Customer::factory()->create(['password' => 'secret-123']);
        $token = $customer->createToken('phone')->plainTextToken;
        $customer->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
        $this->assertSame(0, $customer->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['login' => $customer->username, 'password' => 'secret-123'])->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    }

    public function test_a_forgotten_password_is_replaced_with_a_code_and_every_phone_signs_in_again(): void
    {
        $customer = Customer::factory()->create(['phone' => '+966554444444']);
        $customer->createToken('old-phone');

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0554444444'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0559999999'])->assertOk();   // same answer, no code sent
        $this->assertCount(1, $this->sms()->sent());

        $this->postJson('/api/v1/auth/password/reset', ['phone' => '0554444444', 'code' => $this->lastCode('+966554444444'), 'password' => 'brand-new-1'])->assertOk();
        $this->assertSame(0, $customer->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['login' => '0554444444', 'password' => 'brand-new-1'])->assertOk();
    }

    public function test_signing_in_during_the_grace_period_cancels_the_deletion_and_restores_only_what_it_hid(): void
    {
        $broker = Customer::factory()->broker()->create(['password' => 'secret-123']);
        $public = Listing::factory()->published()->for($broker)->create();
        $this->signIn($broker);
        $this->postJson('/api/v1/account/delete', ['password' => 'secret-123'])->assertStatus(202);
        $this->assertSame('hidden', $public->fresh()->status->value);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => $broker->username, 'password' => 'secret-123'])->assertOk();

        $this->assertSame('published', $public->fresh()->status->value);
        $this->assertNotNull(DeletionRequest::query()->sole()->cancelled_at);
    }

    public function test_every_answer_carries_a_trace_id_and_errors_use_the_envelope(): void
    {
        $this->getJson('/api/v1/me', ['X-Request-Id' => 'app-trace-0001'])
            ->assertStatus(401)
            ->assertHeader('X-Request-Id', 'app-trace-0001')
            ->assertExactJson(['success' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'سجّل الدخول للمتابعة.', 'details' => [], 'trace_id' => 'app-trace-0001']]);

        $id = $this->getJson('/api/v1/listings/999999', ['X-Request-Id' => 'bad id!'])->assertNotFound()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $id, 'an unsafe id is replaced by a ULID');
    }

    public function test_old_app_versions_must_update_and_maintenance_lets_testers_in(): void
    {
        $headers = ['X-App-Platform' => 'ios', 'X-App-Version' => '3.0.0'];
        $this->getJson('/api/v1/catalog', $headers)->assertStatus(426)->assertJsonPath('error.details.min_version', '3.1.2');

        AppConfig::query()->where('platform', 'ios')->update(['maintenance_mode' => true, 'maintenance_message' => 'نحدّث الخوادم']);
        AppConfig::query()->first()->save();   // the cached copy is dropped on save
        $headers['X-App-Version'] = '3.1.2';
        $this->getJson('/api/v1/catalog', $headers)->assertStatus(503)->assertJsonPath('error.message', 'نحدّث الخوادم');
        $this->getJson('/api/v1/app/config', $headers)->assertOk()->assertJsonPath('data.platform.maintenance.message', 'نحدّث الخوادم');

        $tester = Customer::factory()->create(['is_tester' => true]);
        $this->withToken($tester->createToken('t')->plainTextToken)->getJson('/api/v1/catalog', $headers)->assertOk();
    }

    public function test_a_profile_edit_is_audited_by_the_fields_it_changed(): void
    {
        $me = $this->signIn();

        $this->patchJson('/api/v1/me', ['name' => 'اسم جديد'], ['X-Request-Id' => 'app-trace-0002'])
            ->assertOk()->assertJsonPath('data.name', 'اسم جديد');

        $entry = Activity::query()->where('event', 'profile_updated')->sole();
        $this->assertSame($me->id, $entry->subject_id);
        $this->assertSame(['fields' => ['name'], 'trace_id' => 'app-trace-0002'], $entry->properties->all());
    }

    public function test_a_seeker_without_a_wish_is_sent_to_the_wish_form(): void
    {
        $this->signIn(Customer::factory()->seeker()->create());

        $this->getJson('/api/v1/favorites')->assertStatus(428)->assertJsonPath('error.code', 'PREFERENCE_REQUIRED');
        $this->getJson('/api/v1/me')->assertOk();
    }
}
