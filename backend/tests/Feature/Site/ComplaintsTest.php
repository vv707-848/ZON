<?php

namespace Tests\Feature\Site;

use App\Actions\Support\SupportDesk;
use App\Enums\ListingStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SupportTicket;
use App\Models\User;
use Tests\TestCase;

/**
 * The complaints page REGA requires (marketing and advertising regulation, Art. 8(8)): a form, the complaint's
 * number and date, the actions taken and the processing time — on a private page the visitor keeps.
 */
class ComplaintsTest extends TestCase
{
    private function site(string $path = ''): string
    {
        return 'https://zon.sa/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> a complaint as a person sends it: the form open for a while, the hidden field empty */
    private function form(array $fields = []): array
    {
        return $fields + ['type' => 'complaint', 'name' => 'نورة السبيعي', 'phone' => '٠٥٥ ١٢٣ ٤٥٦٧', 'body' => 'السعر في الإعلان يختلف عمّا قاله المعلن.',
            'started_at' => encrypt(now()->getTimestamp() - 20), 'website' => ''];
    }

    public function test_a_visitor_files_a_complaint_about_a_listing_and_gets_its_number_date_and_page(): void
    {
        $listing = Listing::factory()->published()->create(['title' => 'شقة في الياسمين']);

        $this->get($this->site("complaints?listing={$listing->id}"))->assertOk()->assertSee('شقة في الياسمين')->assertSee('نرد خلال');
        $sent = $this->post($this->site('complaints'), $this->form(['listing' => "https://zon.sa/listings/{$listing->id}"]));

        $ticket = SupportTicket::query()->sole();
        $this->assertSame([TicketType::Complaint, null, 'نورة السبيعي', '+966551234567', $listing->id],
            [$ticket->type, $ticket->customer_id, $ticket->name, $ticket->phone, $ticket->about_id]);
        $sent->assertRedirect();
        $link = (string) $sent->headers->get('Location');
        $this->assertStringStartsWith("https://zon.sa/complaints/{$ticket->reference}?signature=", $link);

        $this->get($link)->assertOk()->assertSee('سُجّلت شكواك برقم')->assertSee($ticket->reference)->assertSee('تاريخ التقديم')
            ->assertSee('مدة المعالجة المعلنة')->assertSee('الرد خلال 48 ساعة')->assertSee('السعر في الإعلان يختلف')->assertSee('شقة في الياسمين')
            ->assertSee('noindex, nofollow', false);
    }

    public function test_the_actions_taken_and_the_time_it_took_are_on_the_visitors_page(): void
    {
        $this->post($this->site('complaints'), $this->form());
        $ticket = SupportTicket::query()->sole();
        $staff = User::factory()->create();
        $this->travel(5)->hours();
        app(SupportDesk::class)->replyAsStaff($ticket, $staff, 'تواصلنا مع المعلن وطلبنا تصحيح السعر.');
        $this->travel(2)->days();
        app(SupportDesk::class)->close($ticket->fresh(), $staff, 'عُدّل السعر في الإعلان.');

        $page = $this->get(\URL::signedRoute('site.complaint', ['ticket' => $ticket->reference]))->assertOk();
        $page->assertSee('فريق زون')->assertSee('تواصلنا مع المعلن')->assertSee('الإجراء المتخذ:')->assertSee('عُدّل السعر في الإعلان.')
            ->assertSee('أول رد')->assertSee('أُغلقت في')->assertSee('بعد يومين')->assertDontSee($staff->name);

        // A message from the visitor opens the complaint again, for staff to see.
        $this->post(\URL::signedRoute('site.complaint', ['ticket' => $ticket->reference]), ['body' => 'ما زال السعر خطأ.', 'started_at' => encrypt(now()->getTimestamp() - 20)])
            ->assertRedirect();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_the_page_is_private_and_a_lost_link_is_found_with_the_number_and_phone(): void
    {
        $this->post($this->site('complaints'), $this->form());
        $ticket = SupportTicket::query()->sole();
        $appUser = app(SupportDesk::class)->open(Customer::factory()->create(), TicketType::Question, 'سؤال من التطبيق');

        $this->get($this->site("complaints/{$ticket->reference}"))->assertForbidden();
        $this->get($this->site("complaints/{$ticket->reference}?signature=forged"))->assertForbidden();
        $this->get(\URL::signedRoute('site.complaint', ['ticket' => $appUser->reference]))->assertNotFound();   // read in the app

        $this->post($this->site('complaints/track'), ['reference' => $ticket->reference, 'phone' => '0559999999'])->assertSessionHasErrors('reference');
        $found = $this->post($this->site('complaints/track'), ['reference' => strtolower($ticket->reference), 'phone' => '+966 55 123 4567']);
        $found->assertRedirect(\URL::signedRoute('site.complaint', ['ticket' => $ticket->reference]));
    }

    public function test_a_bot_and_a_wrong_listing_are_refused_and_ten_forms_an_hour_is_the_limit(): void
    {
        $hidden = Listing::factory()->published()->create(['status' => ListingStatus::Hidden]);

        $this->post($this->site('complaints'), $this->form(['website' => 'http://spam.example']))->assertSessionHasErrors('body');
        $this->post($this->site('complaints'), $this->form(['started_at' => encrypt(now()->getTimestamp())]))->assertSessionHasErrors('body');
        $this->post($this->site('complaints'), $this->form(['listing' => (string) $hidden->id]))->assertSessionHasErrors('listing');
        $this->post($this->site('complaints'), $this->form(['phone' => '12345']))->assertSessionHasErrors('phone');
        $this->assertSame(0, SupportTicket::query()->count());

        for ($i = 0; $i < 6; $i++) {
            $this->post($this->site('complaints'), $this->form())->assertRedirect();
        }
        $this->post($this->site('complaints'), $this->form())->assertStatus(429);
    }
}
