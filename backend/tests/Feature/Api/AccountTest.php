<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\LegacyClaim;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * His account as the app will do it with a server: signing out this phone only, a new password (the other phones
 * sign in again), a new mobile only after its own code, the sign-up code sent again (and nothing said about a
 * phone that is not signing up), and a claim on the previous version's data. Its answers are the app's recorded
 * contract.
 */
class AccountTest extends TestCase
{
    private function login(string $login, string $password, string $device): string
    {
        $this->flushSession();
        Auth::forgetGuards();

        return (string) $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password, 'device_name' => $device])->assertOk()->json('data.token');
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_signing_out_this_phone_only_and_a_new_password_signs_the_others_out(): void
    {
        Customer::factory()->broker()->create(['phone' => '+966554444444', 'password' => 'secret-123']);
        $phone = $this->login('0554444444', 'secret-123', 'phone');
        $tablet = $this->login('0554444444', 'secret-123', 'tablet');
        $laptop = $this->login('0554444444', 'secret-123', 'laptop');

        $this->apiContract('logout', $this->as($laptop)->postJson('/api/v1/auth/logout')->assertOk());
        $this->as($laptop)->getJson('/api/v1/me')->assertStatus(401);
        $this->as($phone)->getJson('/api/v1/me')->assertOk();

        $this->as($phone)->putJson('/api/v1/me/password', ['current_password' => 'wrong-one', 'password' => 'brand-new-1'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);
        $this->as($tablet)->getJson('/api/v1/me')->assertOk();                                  // nothing changed
        $this->apiContract('password_changed', $this->as($phone)->putJson('/api/v1/me/password', ['current_password' => 'secret-123', 'password' => 'brand-new-1'])->assertOk());
        $this->as($phone)->getJson('/api/v1/me')->assertOk();                                   // this phone stays in
        $this->as($tablet)->getJson('/api/v1/me')->assertStatus(401);                           // the others sign in again
        $this->postJson('/api/v1/auth/login', ['login' => '0554444444', 'password' => 'secret-123'])->assertStatus(401);
        $this->login('0554444444', 'brand-new-1', 'tablet');
    }

    public function test_a_new_mobile_only_after_its_own_code(): void
    {
        Customer::factory()->create(['phone' => '+966557777777']);
        $me = Customer::factory()->broker()->create(['phone' => '+966554444444', 'password' => 'secret-123']);
        $token = $this->login('0554444444', 'secret-123', 'phone');

        $this->as($token)->postJson('/api/v1/me/phone', ['phone' => '0557777777'])->assertStatus(422);              // someone else's
        $this->apiContract('phone_code_sent', $this->as($token)->postJson('/api/v1/me/phone', ['phone' => '0558888888'])->assertStatus(202));
        $this->assertSame('+966554444444', $me->refresh()->phone, 'nothing changes before its code');

        $this->as($token)->postJson('/api/v1/me/phone/verify', ['phone' => '0558888888', 'code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
        $this->as($token)->postJson('/api/v1/me/phone/verify', ['phone' => '0559999999', 'code' => $this->lastCode('+966558888888')])->assertStatus(422);   // its code, another number
        $verified = $this->as($token)->postJson('/api/v1/me/phone/verify', ['phone' => '0558888888', 'code' => $this->lastCode('+966558888888')])->assertOk()
            ->assertJsonPath('data.phone', '0558888888');
        $this->apiContract('phone_changed', $verified);
        $this->assertSame('+966558888888', $me->refresh()->phone);
        $this->login('0558888888', 'secret-123', 'tablet');
        $this->postJson('/api/v1/auth/login', ['login' => '0554444444', 'password' => 'secret-123'])->assertStatus(401);
    }

    public function test_the_sign_up_code_sent_again_and_nothing_said_of_another_phone(): void
    {
        $this->postJson('/api/v1/auth/register', ['role' => 'seeker', 'name' => 'محمد', 'phone' => '0551234567', 'city' => 'الرياض',
            'username' => 'mohammed.s', 'password' => 'secret-123'])->assertStatus(202);
        $first = $this->lastCode('+966551234567');

        $resent = $this->postJson('/api/v1/auth/otp/resend', ['phone' => '0551234567'])->assertOk();
        $this->apiContract('otp_resent', $resent);
        $this->assertCount(2, $this->sms()->sent());
        $this->postJson('/api/v1/auth/otp/resend', ['phone' => '0559999999'])->assertOk()->assertExactJson($resent->json());   // the same answer
        $this->assertCount(2, $this->sms()->sent(), 'no code to a phone that is not signing up');

        $second = $this->lastCode('+966551234567');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0551234567', 'code' => $first])->assertStatus(422);   // the new code replaces the old
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0551234567', 'code' => $second])->assertCreated();
    }

    public function test_a_claim_on_the_previous_versions_data(): void
    {
        $me = $this->signIn(Customer::factory()->broker()->create());
        $this->postJson('/api/v1/account/legacy-claims', [])->assertStatus(422);
        $this->apiContract('legacy_claim', $this->postJson('/api/v1/account/legacy-claims', ['listings' => ['u-1700000000000', 'u-1700000000001'], 'requests' => ['r-1']])
            ->assertCreated()->assertJsonPath('data.status', 'pending'));
        $this->assertSame([$me->id, ['listings' => ['u-1700000000000', 'u-1700000000001'], 'requests' => ['r-1']]],
            [LegacyClaim::query()->sole()->customer_id, LegacyClaim::query()->sole()->items]);
    }
}
