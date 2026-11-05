<?php

namespace Tests\Feature\Site;

use App\Enums\Delivery;
use App\Enums\ListingStatus;
use App\Models\AdLicense;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\LegalPage;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The website shows a guest exactly what the app shows a guest — never more (no phone, nothing of a review account,
 * nothing unlicensed) — on its own host, at one address per page, and nothing before it is real.
 */
class PublicPagesTest extends TestCase
{
    private function site(string $path = ''): string
    {
        return 'https://zon.sa/'.ltrim($path, '/');
    }

    private function photo(Listing|Project $owner): Media
    {
        return Media::query()->create(['mediable_type' => $owner->getMorphClass(), 'mediable_id' => $owner->id, 'customer_id' => $owner instanceof Listing ? $owner->customer_id : $owner->developer_id,
            'disk' => 'minio', 'path' => 'media/1/01JTEST.jpg', 'mime' => 'image/jpeg', 'size' => 1000, 'width' => 2048, 'height' => 1536, 'is_cover' => true, 'sizes' => ['large', 'card']]);
    }

    public function test_a_listing_page_shows_what_a_guest_sees_in_the_app_with_its_licence_and_never_a_phone(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'فهد العتيبي', 'phone' => '+966551234567', 'fal_number' => '1100223344']);
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id, 'title' => 'شقة فاخرة في النرجس', 'phones' => ['+966551234567']]);
        AdLicense::query()->where('listing_id', $listing->id)->update(['qr_url' => 'https://rega.gov.sa/verify/7100000001']);
        $this->photo($listing);

        $page = $this->get($this->site("listings/{$listing->id}"))->assertOk();

        $page->assertSee('شقة فاخرة في النرجس')->assertSee('880,000 ريال')->assertSee('فهد العتيبي')->assertSee('1100223344')
            ->assertSee(AdLicense::query()->where('listing_id', $listing->id)->value('licence_number'))
            ->assertSee('<svg role="img" aria-label="رمز التحقق من الترخيص لدى الهيئة العامة للعقار"', false)
            ->assertSee('3 غرف')->assertSee('150 م²')->assertSee('يقبل التمويل البنكي');
        foreach (['0551234567', '551234567', '+966551234567'] as $phone) {
            $page->assertDontSee($phone, false);
        }
        // WhatsApp's preview: og:title, og:description, og:url filled; the image at a stable address of this site.
        $page->assertSee('<meta property="og:title" content="شقة فاخرة في النرجس">', false)
            ->assertSee('<meta property="og:url" content="https://zon.sa/listings/'.$listing->id.'">', false)
            ->assertSee('<meta property="og:image" content="https://zon.sa/listings/'.$listing->id.'/image">', false)
            ->assertSee('<link rel="canonical" href="https://zon.sa/listings/'.$listing->id.'">', false);
        $this->assertStringContainsString("default-src 'self'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame([], $page->headers->getCookies(), 'a reading page leaves no cookie');

        $image = $this->get($this->site("listings/{$listing->id}/image"))->assertRedirect();
        $this->assertStringContainsString('01JTEST-large.jpg', (string) $image->headers->get('Location'));
    }

    public function test_what_the_app_hides_from_a_guest_does_not_exist_on_the_website(): void
    {
        $paused = Listing::factory()->published()->create(['status' => ListingStatus::Paused]);
        $tester = Listing::factory()->published()->create(['customer_id' => Customer::factory()->broker()->tester()]);
        $expired = Listing::factory()->published()->create();
        AdLicense::query()->where('listing_id', $expired->id)->update(['expires_at' => now()->subDays(2)->toDateString()]);
        $seeker = Customer::factory()->seeker()->create(['username' => 'seeker1']);

        foreach ([$paused, $tester, $expired] as $listing) {
            $this->get($this->site("listings/{$listing->id}"))->assertNotFound();
            $this->getJson("/api/v1/listings/{$listing->id}")->assertNotFound();
            $this->get($this->site("listings/{$listing->id}/image"))->assertNotFound();
        }
        $this->get($this->site('users/seeker1'))->assertNotFound();
        $this->get($this->site('listings'))->assertOk()->assertSee('لا عروض')->assertSee('noindex', false);
    }

    public function test_the_feed_has_one_address_per_filter_set(): void
    {
        $listing = Listing::factory()->published()->create(['title' => 'فيلا في الملقا', 'neighborhood' => 'الملقا', 'property_type' => 'villa']);
        Listing::factory()->published()->create(['title' => 'أرض في جدة', 'city' => 'جدة', 'neighborhood' => 'الشاطئ', 'property_type' => 'land']);

        // Unknown values, empty fields, the default sort and page 1 are dropped; the order is fixed.
        $this->get($this->site('listings?type=villa&city=%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6&sort=new&page=1&purpose=&x=1'))
            ->assertRedirect('https://zon.sa/listings?city=%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6&type=villa')->assertStatus(301);
        $this->get($this->site('listings?neighborhood=الملقا'))->assertRedirect('https://zon.sa/listings');

        $page = $this->get($this->site('listings?city=%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6&type=villa'))->assertOk();
        $page->assertSee('<title>فلل في الرياض — زون</title>', false)->assertSee('فيلا في الملقا')->assertDontSee('أرض في جدة')
            ->assertDontSee('noindex', false);
        $this->get($this->site('listings?page=9'))->assertNotFound();
        $this->get($this->site('listings/'.$listing->id))->assertOk();
    }

    public function test_projects_show_their_units_and_a_ready_unit_only_with_its_licence(): void
    {
        $project = Project::factory()->published()->create(['delivery' => Delivery::Ready, 'title' => 'مشروع الواحة']);
        $licensed = ProjectUnit::factory()->create(['project_id' => $project->id, 'model_name' => 'A1']);
        AdLicense::query()->create(['project_unit_id' => $licensed->id, 'customer_id' => $project->developer_id, 'licence_number' => '7200000001', 'source' => 'entered',
            'status' => 'valid', 'verified_at' => now(), 'expires_at' => now()->addYear()->toDateString()]);
        $unlicensed = ProjectUnit::factory()->create(['project_id' => $project->id, 'model_name' => 'B2']);

        $this->get($this->site("projects/{$project->id}"))->assertOk()->assertSee('مشروع الواحة')->assertSee('A1')->assertDontSee('>B2<', false)
            ->assertSee('1,950,000 ريال');
        $this->get($this->site("projects/{$project->id}/units/{$licensed->id}"))->assertOk()->assertSee('7200000001');
        $this->get($this->site("projects/{$project->id}/units/{$unlicensed->id}"))->assertNotFound();
        $this->get($this->site('projects/'.Project::factory()->create()->id))->assertNotFound();          // a draft
    }

    public function test_an_advertisers_page_and_a_request_page(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'مكتب الريم', 'username' => 'alreem', 'fal_number' => '1100998877']);
        Listing::factory()->published()->count(2)->create(['customer_id' => $broker->id]);
        $request = SearchRequest::factory()->create(['description' => 'فيلا بمجلس مستقل وحوش.']);

        $this->get($this->site('users/alreem'))->assertOk()->assertSee('مكتب الريم')->assertSee('1100998877')->assertSee('عرضان');
        $this->get($this->site("requests/{$request->id}"))->assertOk()->assertSee('فيلا بمجلس مستقل')->assertSee('noindex, follow', false)
            ->assertDontSee($request->customer->name);
    }

    public function test_a_policy_is_shown_once_published_and_its_text_cannot_carry_html(): void
    {
        $this->get($this->site('legal/privacy'))->assertNotFound();               // a draft until its owner writes it
        $this->get($this->site('legal'))->assertOk()->assertSee('تُنشر السياسات هنا عند اعتمادها');

        LegalPage::query()->where('slug', 'privacy')->update(['body' => "## ما نجمعه\n\nرقم الجوال.<script>alert(1)</script> [رابط](javascript:alert(1))", 'published_at' => now()]);
        Cache::forget('site.policies');

        $page = $this->get($this->site('legal/privacy'))->assertOk();
        $page->assertSee('<h2>ما نجمعه</h2>', false)->assertDontSee('<script>alert', false)->assertDontSee('javascript:', false);
        $this->get($this->site())->assertSee('https://zon.sa/legal/privacy', false);
    }

    public function test_the_home_shows_real_listings_and_what_is_set_nothing_more(): void
    {
        $this->get($this->site())->assertOk()->assertSee('التطبيق قريباً على App Store وGoogle Play')
            ->assertDontSee('السجل التجاري')->assertDontSee('أحدث العروض')->assertDontSee('apple-itunes-app', false);

        Listing::factory()->published()->create(['title' => 'دور مستقل في العارض']);
        Listing::factory()->published()->create(['title' => 'عرض حساب مراجعة', 'customer_id' => Customer::factory()->broker()->tester()]);
        Settings::put(Setting::SiteCrNumber, '1010123456');
        AppConfig::query()->where('platform', 'ios')->update(['store_url' => 'https://apps.apple.com/sa/app/zon/id6740000000']);
        AppConfig::query()->first()?->save();

        $this->get($this->site())->assertOk()->assertSee('دور مستقل في العارض')->assertDontSee('عرض حساب مراجعة')
            ->assertSee('1010123456')->assertSee('حمّل من App Store')->assertDontSee('حمّل من Google Play')
            ->assertSee('<meta name="apple-itunes-app" content="app-id=6740000000, app-argument=https://zon.sa/">', false);
    }

    public function test_the_website_answers_on_its_host_only_and_tells_crawlers_where_to_go(): void
    {
        $this->get('http://localhost/')->assertRedirect('http://localhost/admin');
        $this->get('http://localhost/listings')->assertNotFound();
        $this->get('http://localhost/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
        $this->get($this->site('robots.txt'))->assertOk()->assertSee('Sitemap: https://zon.sa/sitemap.xml');

        $live = Listing::factory()->published()->create();
        $paused = Listing::factory()->published()->create(['status' => ListingStatus::Paused]);
        $this->get($this->site('sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('https://zon.sa/sitemaps/listings-1.xml');
        $this->get($this->site('sitemaps/listings-1.xml'))->assertOk()->assertSee("https://zon.sa/listings/{$live->id}<", false)->assertDontSee("listings/{$paused->id}<", false);
        $this->get($this->site('sitemaps/pages.xml'))->assertOk()->assertSee('https://zon.sa/listings?city=%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6', false)
            ->assertSee('https://zon.sa/packages<', false);
        $this->get($this->site('robots.txt'))->assertSee('Disallow: /pay/')->assertSee('Disallow: /account');
        $this->get($this->site('sitemaps/users.xml'))->assertOk()->assertSee('https://zon.sa/users/');
    }

    public function test_links_open_the_app_once_its_identifiers_are_set(): void
    {
        $this->get($this->site('.well-known/apple-app-site-association'))->assertNotFound();
        $this->get($this->site('.well-known/assetlinks.json'))->assertNotFound();

        config(['services.app_store.team_id' => 'ABCDE12345', 'services.google_play.cert_fingerprints' => ['AA:BB']]);

        $this->get($this->site('.well-known/apple-app-site-association'))->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'ABCDE12345.com.zon.app')
            ->assertJsonPath('applinks.details.0.components', [['/' => '/listings/*'], ['/' => '/projects/*'], ['/' => '/users/*'], ['/' => '/requests/*']]);
        $this->get($this->site('.well-known/assetlinks.json'))->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.zon.app')->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB']);
    }
}
