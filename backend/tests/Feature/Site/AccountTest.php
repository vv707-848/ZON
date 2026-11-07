<?php

namespace Tests\Feature\Site;

use App\Actions\Accounts\PurgeAccount;
use App\Actions\Accounts\ResetPassword;
use App\Enums\ListingStatus;
use App\Models\Customer;
use App\Models\DeletionRequest;
use App\Models\Listing;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The account on the website: Google Play's deletion page (requested without the app, what is deleted and kept),
 * signing in with the app's account (which cancels a pending deletion, as in the app), the forgotten password.
 */
class AccountTest extends TestCase
{
    private function site(string $path = ''): string
    {
        return 'https://zon.sa/'.ltrim($path, '/');
    }

    private function broker(): Customer
    {
        return Customer::factory()->broker()->create(['username' => 'fahad', 'phone' => '+966551234567', 'password' => 'a-good-secret']);
    }

    public function test_the_deletion_page_says_what_goes_what_stays_and_asks_nothing_of_the_app(): void
    {
        $this->get($this->site('account/delete'))->assertOk()
            ->assertSee('حذف حسابك في تطبيق زون')->assertSee('ما يُحذف')->assertSee('ما يُحفظ ولماذا')->assertSee('بعد المهلة (30 يوماً)')
            ->assertSee('المدفوعات والفواتير')->assertSee('ألغِ تجديده من إعدادات المتجر')
            ->assertSee('name="login"', false)->assertSee('name="confirm"', false);
    }

    public function test_an_account_is_deleted_from_the_website_without_the_app_and_without_signing_in(): void
    {
        $broker = $this->broker();
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $broker->createToken('phone');

        $this->post($this->site('account/delete'), ['login' => 'fahad', 'password' => 'wrong-secret', 'confirm' => '1'])->assertSessionHasErrors('login');
        $this->post($this->site('account/delete'), ['login' => '0551234567', 'password' => 'a-good-secret'])->assertSessionHasErrors('confirm');
        $this->assertSame(0, DeletionRequest::query()->count());

        $this->post($this->site('account/delete'), ['login' => '0551234567', 'password' => 'a-good-secret', 'confirm' => '1'])
            ->assertRedirect('https://zon.sa/account/delete');
        $deletion = DeletionRequest::query()->sole();
        $this->assertTrue($deletion->isPending());
        $this->assertSame(ListingStatus::Hidden, $listing->fresh()->status, 'public things hide at once');
        $this->getJson("/api/v1/listings/{$listing->id}")->assertNotFound();
        $this->assertSame(0, $broker->tokens()->count(), 'signed out of the app on every phone');
        $this->get($this->site('account/delete'))->assertSee('سُجّل طلب حذف حسابك')->assertSee('يُحذف الحساب نهائياً في');

        // Asking again changes nothing and cancels nothing: the deletion page never signs in.
        $this->post($this->site('account/delete'), ['login' => 'fahad', 'password' => 'a-good-secret', 'confirm' => '1']);
        $this->get($this->site('account/delete'))->assertSee('طلب حذف حسابك مسجّل من قبل');
        $this->assertTrue($deletion->fresh()->isPending());
        $this->assertSame(1, DeletionRequest::query()->count());
    }

    public function test_signing_in_on_the_website_cancels_a_pending_deletion_and_says_so(): void
    {
        $broker = $this->broker();
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $this->post($this->site('account/delete'), ['login' => 'fahad', 'password' => 'a-good-secret', 'confirm' => '1']);

        $this->get($this->site('account'))->assertRedirect('https://zon.sa/login');
        $this->post($this->site('login'), ['login' => 'fahad', 'password' => 'a-good-secret'])->assertRedirect('https://zon.sa/account');
        $this->get($this->site('account'))->assertOk()->assertSee('أُلغي طلب حذف حسابك')->assertSee($broker->name);

        $this->assertFalse(DeletionRequest::query()->sole()->isPending());
        $this->assertSame(ListingStatus::Published, $listing->fresh()->status, 'what the request hid is back');
    }

    public function test_signed_in_the_password_alone_confirms_and_the_session_ends(): void
    {
        $this->broker();
        $this->post($this->site('login'), ['login' => 'fahad', 'password' => 'a-good-secret']);
        $this->get($this->site('account/delete'))->assertSee('أنت داخل بحساب')->assertDontSee('name="login"', false);

        $this->post($this->site('account/delete'), ['password' => 'wrong-secret', 'confirm' => '1'])->assertSessionHasErrors('password');
        $this->post($this->site('account/delete'), ['password' => 'a-good-secret', 'confirm' => '1'])->assertRedirect();

        $this->assertTrue(DeletionRequest::query()->sole()->isPending());
        $this->get($this->site('account'))->assertRedirect('https://zon.sa/login');
    }

    public function test_a_suspended_account_is_told_and_a_forgotten_password_is_reset_by_sms(): void
    {
        Customer::factory()->broker()->suspended()->create(['username' => 'stopped', 'password' => 'a-good-secret']);
        $this->post($this->site('account/delete'), ['login' => 'stopped', 'password' => 'a-good-secret', 'confirm' => '1'])
            ->assertSessionHasErrors(['login' => 'الحساب موقوف. تواصل مع الدعم.']);

        $broker = $this->broker();
        $this->post($this->site('password/forgot'), ['phone' => '٠٥٥١٢٣٤٥٦٧'])->assertRedirect('https://zon.sa/password/reset');
        $this->get($this->site('password/reset'))->assertOk()->assertSee('0551234567');
        $this->post($this->site('password/reset'), ['code' => '000000', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])->assertSessionHasErrors('code');
        $this->post($this->site('password/reset'), ['code' => $this->lastCode('+966551234567'), 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])
            ->assertRedirect('https://zon.sa/login');

        $this->post($this->site('login'), ['login' => 'fahad', 'password' => 'new-secret-1'])->assertRedirect('https://zon.sa/account');
        $this->assertTrue(auth('site')->id() === $broker->id);

        // A number without an account gets the same answer, and no SMS.
        $sent = count($this->sms()->sent());
        $this->post($this->site('password/forgot'), ['phone' => '0559999999'])->assertRedirect('https://zon.sa/password/reset');
        $this->assertCount($sent, $this->sms()->sent());
    }

    public function test_a_password_reset_ends_the_websites_sessions_opened_before_it(): void
    {
        $broker = $this->broker();
        $this->post($this->site('login'), ['login' => 'fahad', 'password' => 'a-good-secret'])->assertRedirect('https://zon.sa/account');
        $this->get($this->site('account/invoices'))->assertOk();

        $reset = app(ResetPassword::class);                               // from the app, on another phone
        $reset->start('+966551234567');
        $reset->finish('+966551234567', $this->lastCode('+966551234567'), 'new-secret-1');
        $this->app['auth']->forgetGuards();                               // the next request is another process

        $this->get($this->site('account/invoices'))->assertRedirect('https://zon.sa/login');
        $this->get($this->site('account'))->assertDontSee($broker->name);
        $this->post($this->site('login'), ['login' => 'fahad', 'password' => 'new-secret-1'])
            ->assertRedirect('https://zon.sa/account/invoices');            // back to the page that asked for it
        $this->get($this->site('account/invoices'))->assertOk();          // a session opened with the new one stays
    }

    public function test_the_purge_deletes_the_photos_of_what_was_advertised_and_keeps_a_contracts_documents(): void
    {
        $broker = $this->broker();
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $photo = Media::query()->create(['mediable_type' => 'listing', 'mediable_id' => $listing->id, 'customer_id' => $broker->id, 'disk' => 'minio',
            'path' => 'media/1/01PHOTO.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'sizes' => ['large', 'card']]);
        $deed = Media::query()->create(['mediable_type' => 'owner_case', 'mediable_id' => 1, 'customer_id' => $broker->id, 'disk' => 'minio',
            'path' => 'media/1/01DEED.jpg', 'mime' => 'image/jpeg', 'size' => 10]);
        foreach ([...$photo->paths(), $deed->path] as $path) {
            Storage::disk('minio')->put($path, 'x');
        }
        $this->post($this->site('account/delete'), ['login' => 'fahad', 'password' => 'a-good-secret', 'confirm' => '1']);
        $this->travel(31)->days();

        app(PurgeAccount::class)(DeletionRequest::query()->sole());

        $this->assertNull(Media::query()->find($photo->id));
        $this->assertSame([$deed->path], Storage::disk('minio')->allFiles(), 'the listing\'s three files are gone, the deed stays');
        $this->assertNotNull($deed->fresh());
        $this->assertSame('حساب محذوف', $broker->fresh()->name);
    }
}
