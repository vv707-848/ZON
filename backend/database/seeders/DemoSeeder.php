<?php

namespace Database\Seeders;

use App\Enums\CustomerRole;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use Illuminate\Database\Seeder;

/** Local development only: one account of each role (password «password»), a few listings, a project, requests. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $broker = Customer::factory()->broker()->create(['username' => 'broker', 'name' => 'فهد العتيبي', 'phone' => '+966551000001']);
        $owner = Customer::factory()->owner()->create(['username' => 'owner', 'name' => 'سارة القحطاني', 'phone' => '+966551000002']);
        $developer = Customer::factory()->developer()->create(['username' => 'developer', 'name' => 'شركة الأفق للتطوير', 'phone' => '+966551000003']);
        $seeker = Customer::factory()->seeker()->create(['username' => 'seeker', 'name' => 'محمد الشهري', 'phone' => '+966551000004']);
        BuyerPreference::factory()->for($seeker)->create();

        foreach ([['النرجس', 880000, 'apartment'], ['الملقا', 2350000, 'villa'], ['العارض', 1150000, 'floor']] as [$hood, $price, $type]) {
            Listing::factory()->published()->for($broker)->create(['neighborhood' => $hood, 'price' => $price, 'property_type' => $type, 'title' => "عرض في {$hood}"]);
        }
        $project = Project::factory()->published()->create(['developer_id' => $developer->id]);
        ProjectUnit::factory()->count(2)->create(['project_id' => $project->id]);
        SearchRequest::factory()->for($seeker)->create();
        SearchRequest::factory()->urgent()->for(Customer::factory()->role(CustomerRole::Seeker))->create(['neighborhoods' => ['النرجس']]);
    }
}
