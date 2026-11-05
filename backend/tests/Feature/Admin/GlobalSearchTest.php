<?php

namespace Tests\Feature\Admin;

use App\Actions\Support\SupportDesk;
use App\Enums\TicketType;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Payment;
use App\Support\Permissions\Permission;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;

/** ⌘K finds a record by what staff hold in hand — a phone in any format, a licence, a reference — and only what they may open. */
class GlobalSearchTest extends AdminTestCase
{
    /** @return list<string> every result's title, by group */
    private function titles(string $search): array
    {
        $results = Livewire::test(GlobalSearch::class)->set('search', $search)->instance()->getResults();

        return collect($results?->getCategories() ?? [])->flatMap(fn ($group) => collect($group)->map(fn ($r) => (string) $r->title))->values()->all();
    }

    public function test_a_phone_in_any_format_a_licence_a_reference_or_a_payment_finds_its_record(): void
    {
        $this->signInSuperadmin();
        $owner = Customer::factory()->broker()->create(['name' => 'فهد العتيبي', 'phone' => '+966551234567']);
        $listing = Listing::factory()->published()->create(['customer_id' => $owner->id, 'title' => 'فيلا في النرجس']);
        $licence = AdLicense::query()->where('listing_id', $listing->id)->value('licence_number');
        $ticket = app(SupportDesk::class)->open($owner, TicketType::Complaint, 'سعر غير صحيح');
        $payment = Payment::query()->create(['customer_id' => $owner->id, 'purpose' => 'featured', 'amount_halalas' => 10_000, 'provider' => 'apple',
            'provider_ref' => '2000000123456789', 'status' => 'paid', 'paid_at' => now()]);

        foreach (['0551234567', '٠٥٥١٢٣٤٥٦٧', '+966 55 123 4567', '551234567'] as $phone) {
            $this->assertContains('فهد العتيبي', $this->titles($phone), "by the phone {$phone}");
        }
        $this->assertContains('فيلا في النرجس', $this->titles((string) $licence), 'by its advertising licence');
        $this->assertContains('فيلا في النرجس', $this->titles((string) $listing->id), 'by its number');
        $this->assertContains($ticket->reference, $this->titles(strtolower($ticket->reference)), 'by the reference the user quotes');
        $this->assertContains('تمييز «مميز» — 100.00 SAR', $this->titles('2000000123456789'), "by Apple's transaction");
        $this->assertNotEmpty($this->titles($payment->public_id));
    }

    public function test_staff_find_only_what_their_permissions_open(): void
    {
        Customer::factory()->create(['name' => 'سارة القحطاني']);
        Listing::factory()->published()->create(['title' => 'شقة في الملقا']);

        $this->signInStaff(Permission::ViewListings);

        $this->assertSame(['شقة في الملقا'], $this->titles('الملقا'));
        $this->assertSame([], $this->titles('سارة'), 'accounts are not theirs to open');
    }
}
