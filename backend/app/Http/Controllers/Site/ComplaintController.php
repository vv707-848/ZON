<?php

namespace App\Http\Controllers\Site;

use App\Actions\Support\SupportDesk;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\SupportTicket;
use App\Support\ArabicText;
use App\Support\Phone;
use App\Support\PublicView;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «الشكاوى» on the website — REGA's marketing and advertising regulation (1 May 2026), Art. 8(8): «إتاحة صفحة في
 * المنصة لاستقبال الشكاوى، على أن تتضمن نموذج تقديم الشكوى ورقم قيد الشكوى وتاريخها، والإجراءات التي تمت بشأنها
 * ومدة معالجتها».
 *
 * - **The form** opens a ticket in the same desk as the app's (App\Actions\Support\SupportDesk): staff answer it in
 *   «الشكاوى والمراسلات», a listing named in it is linked.
 * - **The ticket's page** is a private link (a signed URL, no expiry) given at once: its number and date, every
 *   action taken («فريق زون»'s messages and the closing action), the announced processing time and, once closed,
 *   the time it took. A lost link is found again with the number and the phone.
 * - **No captcha service** (another provider holding visitors' data): a hidden field bots fill, a minimum time on the
 *   form, and ten forms an hour per address.
 */
class ComplaintController extends Controller
{
    /** A person takes longer than this to write a complaint; a script does not. */
    private const MIN_SECONDS = 3;

    public function create(Request $request): View
    {
        $listing = $this->listing($request->query('listing'));

        return view('site.complaints.create', [
            'listing' => $listing,
            'type' => in_array($request->query('type'), TicketType::forCustomers(), true) ? (string) $request->query('type') : TicketType::Complaint->value,
            'responseHours' => Settings::int(Setting::SupportResponseHours),
            'startedAt' => encrypt(now()->getTimestamp()),
        ]);
    }

    public function store(Request $request, SupportDesk $desk): RedirectResponse
    {
        $this->human($request);
        $request->merge(['phone' => is_string($request->input('phone')) ? (Phone::normalize($request->input('phone')) ?? $request->input('phone')) : null]);
        $data = $request->validate([
            'type' => ['required', Rule::in(TicketType::forCustomers())],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+9665\d{8}$/'],
            'subject' => ['nullable', 'string', 'max:160'],
            'listing' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ], ['phone.regex' => 'رقم جوال سعودي يبدأ بـ 05.']);

        $listing = null;
        if (filled($data['listing'] ?? null)) {
            $listing = $this->listing($data['listing']) ?? throw ValidationException::withMessages(['listing' => 'لم نجد عرضاً منشوراً بهذا الرقم أو الرابط.']);
        }
        $ticket = $desk->open(null, TicketType::from($data['type']), $data['body'], $data['subject'] ?? null, $listing, $data['name'], $data['phone']);

        return redirect()->to($this->link($ticket))->with('created', true);
    }

    public function show(SupportTicket $ticket): View
    {
        $this->visitorsOwn($ticket);

        return view('site.complaints.show', [
            'ticket' => $ticket->load(['messages', 'about']),
            'responseHours' => Settings::int(Setting::SupportResponseHours),
            'link' => $this->link($ticket),
            'startedAt' => encrypt(now()->getTimestamp()),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $this->visitorsOwn($ticket);
        $this->human($request);
        $body = (string) $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']])['body'];
        $desk->replyAsCustomer($ticket, $body);

        return redirect()->to($this->link($ticket))->with('replied', true);
    }

    public function lookup(): View
    {
        return view('site.complaints.track');
    }

    /** A lost link: the number and the phone it was sent from. The same answer whether the number exists or not. */
    public function find(Request $request): RedirectResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'phone' => ['required', 'string', 'max:20']]);
        $ticket = SupportTicket::query()->where('reference', strtoupper(trim(ArabicText::asciiDigits($data['reference']))))->whereNull('customer_id')
            ->where('phone', Phone::normalize($data['phone']) ?? '-')->first();
        if ($ticket === null) {
            throw ValidationException::withMessages(['reference' => 'لا نجد شكوى بهذا الرقم وهذا الجوال.']);
        }

        return redirect()->to($this->link($ticket));
    }

    /** The private address of a visitor's ticket (no expiry: it is his receipt). */
    private function link(SupportTicket $ticket): string
    {
        return URL::signedRoute('site.complaint', ['ticket' => $ticket->reference]);
    }

    /** Tickets of app accounts are read in the app; a staff notice is not a complaint. */
    private function visitorsOwn(SupportTicket $ticket): void
    {
        abort_if($ticket->customer_id !== null || $ticket->opened_by !== 'customer', 404);
    }

    /** A listing named by its number or its link (…/listings/123), when a guest may see it. */
    private function listing(mixed $value): ?Listing
    {
        if (! is_string($value) || preg_match('#(?:^|/listings/)(\d{1,10})(?:[/?\#]|$)#', trim(ArabicText::asciiDigits($value)), $m) !== 1) {
            return null;
        }
        $listing = Listing::query()->find((int) $m[1]);

        return $listing !== null && PublicView::listing($listing, null) ? $listing : null;
    }

    /** The hidden field is empty and the form was open long enough. */
    private function human(Request $request): void
    {
        try {
            $started = (int) decrypt((string) $request->input('started_at'));
        } catch (\Throwable) {
            $started = 0;
        }
        if (filled($request->input('website')) || now()->getTimestamp() - $started < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['body' => 'لم نستطع استلام النموذج. انتظر لحظة ثم أعد الإرسال.']);
        }
    }
}
