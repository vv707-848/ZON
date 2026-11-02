<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\SuperAgent;
use App\Filament\Resources\ContentReports\ContentReportResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\SearchRequests\SearchRequestResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Widgets\MismatchReasons;
use App\Filament\Widgets\TopSearchMisses;
use App\Models\AiRequest;
use App\Models\BuyerPreference;
use App\Models\ContentReport;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchFeedback;
use App\Models\SearchMiss;
use App\Models\SearchRequest;
use App\Models\User;
use App\Support\Permissions\Permission;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Every screen of the panel opens for the superadmin, and each one is closed to staff without its permission. */
class PanelSmokeTest extends AdminTestCase
{
    public function test_every_list_and_page_opens_with_data_in_it(): void
    {
        $listing = Listing::factory()->published()->create();
        $project = Project::factory()->published()->create();
        ProjectUnit::factory()->create(['project_id' => $project->id]);
        $request = SearchRequest::factory()->create();
        BuyerPreference::factory()->create();
        ContentReport::query()->create(['reporter_id' => Customer::factory()->create()->id, 'target_type' => 'listing', 'target_id' => $listing->id, 'details' => 'مباع']);
        $payment = Payment::query()->create(['customer_id' => $listing->customer_id, 'purpose' => 'featured', 'target_type' => 'listing', 'target_id' => $listing->id,
            'amount_halalas' => 10000, 'provider' => 'fake', 'status' => 'paid', 'paid_at' => now()]);
        $this->signInSuperadmin();

        $panel = Filament::getPanel('admin');
        foreach ($panel->getResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
        }
        foreach ($panel->getPages() as $page) {
            $this->get($page::getUrl())->assertOk();
        }
        foreach ([
            CustomerResource::getUrl('view', ['record' => $listing->customer_id]),
            ListingResource::getUrl('view', ['record' => $listing]),
            ProjectResource::getUrl('view', ['record' => $project]),
            SearchRequestResource::getUrl('view', ['record' => $request]),
            PaymentResource::getUrl('view', ['record' => $payment]),
            CustomerResource::getUrl('edit', ['record' => $listing->customer_id]),
        ] as $url) {
            $this->assertSame(200, $this->get($url)->status(), $url);
        }
    }

    /**
     * Widgets load lazily, after the page itself: a page test alone never runs their queries. Each one is rendered
     * here with data (a grouped table once failed only in the browser, on MySQL's only_full_group_by).
     */
    public function test_every_widget_renders_with_data(): void
    {
        $customer = Customer::factory()->create();
        SearchMiss::query()->create(['customer_id' => $customer->id, 'context' => 'search', 'city' => 'الرياض', 'neighborhood' => 'العارض', 'property_type' => 'duplex', 'created_at' => now()]);
        SearchFeedback::query()->create(['customer_id' => $customer->id, 'context' => 'matching', 'result_type' => 'listing', 'result_id' => 1, 'is_match' => false,
            'reason' => 'بعيد عن المدارس', 'query_snapshot' => [], 'created_at' => now()]);
        AiRequest::query()->create(['customer_id' => $customer->id, 'agent' => 'matching', 'status' => 'ok', 'tokens_in' => 900, 'tokens_out' => 120, 'latency_ms' => 2100,
            'request_hash' => str_repeat('a', 64), 'created_at' => now()]);
        $this->signInSuperadmin();

        foreach (glob(app_path('Filament/Widgets/*.php')) as $file) {
            $widget = 'App\\Filament\\Widgets\\'.basename($file, '.php');
            if ((new \ReflectionClass($widget))->isAbstract()) {
                continue;
            }
            Livewire::test($widget)->assertOk();
        }
        Livewire::test(TopSearchMisses::class)->assertSee('العارض');
        Livewire::test(MismatchReasons::class)->assertSee('بعيد عن المدارس');
    }

    public function test_a_content_moderator_sees_content_but_no_money_settings_or_staff(): void
    {
        $this->signInStaff(...Permission::contentModerator());

        $this->get(ListingResource::getUrl('index'))->assertOk();
        $this->get(ContentReportResource::getUrl('index'))->assertOk();
        $this->get(PaymentResource::getUrl('index'))->assertForbidden();
        $this->get(ManageSettings::getUrl())->assertForbidden();
        $this->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->get(SuperAgent::getUrl())->assertForbidden();
    }

    public function test_the_panel_requires_an_authenticator_and_the_allowed_addresses(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'web');
        $this->get(Dashboard::getUrl())->assertRedirect();               // to the authenticator set-up

        config(['zon.admin_allowed_ips' => ['10.0.0.9']]);
        $this->signInSuperadmin();
        $this->get(Dashboard::getUrl())->assertForbidden();
    }
}
