<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\AppConfigs\Pages\EditAppConfig;
use App\Filament\Resources\LegalPages\Pages\CreateLegalPage;
use App\Filament\Resources\LegalPages\Pages\EditLegalPage;
use App\Filament\Resources\LegalPages\Pages\ListLegalPages;
use App\Models\AppConfig;
use App\Models\LegalPage;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Livewire\Livewire;

/** What the website shows is set in the panel, and only real values are accepted. */
class WebsiteSettingsTest extends AdminTestCase
{
    public function test_a_store_link_must_be_the_apps_real_page_and_a_forced_update_needs_one(): void
    {
        $this->signInSuperadmin();
        $ios = AppConfig::query()->where('platform', 'ios')->firstOrFail();
        $android = AppConfig::query()->where('platform', 'android')->firstOrFail();
        $this->assertNull($ios->store_url, 'empty until the app is published');

        Livewire::test(EditAppConfig::class, ['record' => $ios->getRouteKey()])->fillForm(['store_url' => 'https://apps.apple.com/app/zon'])
            ->call('save')->assertHasFormErrors(['store_url']);
        Livewire::test(EditAppConfig::class, ['record' => $ios->getRouteKey()])->fillForm(['min_version' => '3.2.0'])
            ->call('save')->assertHasFormErrors(['min_version']);
        Livewire::test(EditAppConfig::class, ['record' => $android->getRouteKey()])->fillForm(['store_url' => 'https://play.google.com/store/apps/details?id=sa.zon.app'])
            ->call('save')->assertHasFormErrors(['store_url']);

        Livewire::test(EditAppConfig::class, ['record' => $ios->getRouteKey()])
            ->fillForm(['store_url' => 'https://apps.apple.com/sa/app/zon/id6740000000', 'min_version' => '3.2.0'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('https://apps.apple.com/sa/app/zon/id6740000000', $ios->fresh()->store_url);
    }

    public function test_the_footers_lines_are_settings_and_the_email_must_be_one(): void
    {
        $this->signInSuperadmin();

        Livewire::test(ManageSettings::class)->fillForm(['site__email' => 'ليس بريداً'])->call('save')->assertHasFormErrors(['site__email']);
        Livewire::test(ManageSettings::class)->fillForm(['site__legal_name' => 'شركة زون العقارية', 'site__fal_licence' => '1200001234', 'site__email' => 'care@zon.sa'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(['شركة زون العقارية', '1200001234', 'care@zon.sa'],
            [Settings::string(Setting::SiteLegalName), Settings::string(Setting::SiteFalLicence), Settings::string(Setting::SiteEmail)]);
    }

    public function test_a_draft_policy_becomes_version_one_when_first_saved(): void
    {
        $this->signInSuperadmin();
        $page = LegalPage::query()->where('slug', 'terms')->firstOrFail();
        $this->assertNull($page->published_at);

        Livewire::test(EditLegalPage::class, ['record' => $page->getRouteKey()])->fillForm(['body' => "## الشروط\n\nنص معتمد."])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1', $page->fresh()->version);
        $this->assertNotNull($page->fresh()->published_at);

        Livewire::test(EditLegalPage::class, ['record' => $page->getRouteKey()])->fillForm(['body' => "## الشروط\n\nنص معدّل."])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2', $page->fresh()->version);
    }

    public function test_a_new_policy_is_published_after_the_five_on_the_site_and_in_the_api_and_only_it_can_be_removed(): void
    {
        $this->signInSuperadmin();
        foreach (['Refunds', 'terms', 'a', 'ref unds'] as $bad) {
            Livewire::test(CreateLegalPage::class)->fillForm(['slug' => $bad, 'title' => 'x', 'body' => 'x'])->call('create')->assertHasFormErrors(['slug']);
        }
        LegalPage::query()->where('slug', 'privacy')->firstOrFail()->forceFill(['published_at' => now()])->save();

        Livewire::test(CreateLegalPage::class)->fillForm(['slug' => 'refunds', 'title' => 'سياسة الاسترداد', 'body' => "## الاسترداد\n\nخلال 14 يوماً."])
            ->call('create')->assertHasNoFormErrors();
        $page = LegalPage::query()->where('slug', 'refunds')->sole();
        $this->assertSame('1', $page->version);

        $this->get('https://zon.sa/legal/refunds')->assertOk()->assertSee('خلال 14 يوماً.');
        $this->get('https://zon.sa/legal')->assertOk()->assertSeeInOrder(['سياسة الخصوصية', 'سياسة الاسترداد']);
        $this->getJson('/api/v1/legal')->assertOk()->assertJsonPath('data.0.slug', 'privacy')->assertJsonPath('data.1.slug', 'refunds')->assertJsonMissingPath('data.0.body');

        Livewire::test(ListLegalPages::class)->assertTableActionHidden('delete', LegalPage::query()->where('slug', 'privacy')->sole())
            ->callTableAction('delete', $page);
        $this->assertNull(LegalPage::query()->where('slug', 'refunds')->first());
        $this->get('https://zon.sa/legal/refunds')->assertNotFound();
    }
}
