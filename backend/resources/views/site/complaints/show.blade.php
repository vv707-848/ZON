@extends('site.layout')
@php
    use App\Enums\TicketStatus;
    use App\Support\Site\Format;
    use App\Support\Site\Site;
    $closed = $ticket->status === TicketStatus::Closed;
    $took = $closed && $ticket->closed_at ? $ticket->created_at->diff($ticket->closed_at) : null;
    $tookText = $took === null ? null : ($took->days >= 1 ? Format::counted($took->days, 'يوم واحد', 'يومين', 'أيام', 'يوماً') : Format::counted(max(1, $took->h), 'ساعة واحدة', 'ساعتين', 'ساعات', 'ساعة'));
@endphp
@section('title', $ticket->type->label().' '.$ticket->reference)
@section('robots', 'noindex, nofollow')
{{-- The page's address is private (signed): it is never given as canonical or shared in a preview --}}
@section('canonical', Site::url('complaints/track'))

@section('content')
<div class="narrow">
    @if (session('created'))
        <div class="note note-green" role="status">
            <strong>{{ ['complaint' => 'سُجّلت شكواك', 'suggestion' => 'سُجّل اقتراحك', 'question' => 'سُجّل استفسارك'][$ticket->type->value] ?? 'سُجّلت رسالتك' }} برقم <span class="num">{{ $ticket->reference }}</span>.</strong>
            احفظ رابط هذه الصفحة لمتابعتها، أو تابعها لاحقاً برقمها وجوالك.
        </div>
    @elseif (session('replied'))
        <div class="note note-green" role="status">وصلت رسالتك وأُضيفت إلى الشكوى.</div>
    @endif

    <div class="panel section">
        <div class="chips"><span @class(['badge', 'badge-green' => ! $closed, 'badge-ink' => $closed])>{{ $ticket->status->label() }}</span><span class="badge badge-ink">{{ $ticket->type->label() }}</span></div>
        <h1 class="title-top">رقم القيد <span class="num">{{ $ticket->reference }}</span></h1>
        <dl class="kv section">
            <dt>تاريخ التقديم</dt><dd>{{ Format::dateTime($ticket->created_at) }}</dd>
            <dt>مدة المعالجة المعلنة</dt><dd>الرد خلال {{ Format::counted($responseHours, 'ساعة', 'ساعتين', 'ساعات', 'ساعة') }}</dd>
            @if ($ticket->first_response_at)<dt>أول رد</dt><dd>{{ Format::dateTime($ticket->first_response_at) }}</dd>@endif
            @if ($closed && $ticket->closed_at)<dt>أُغلقت في</dt><dd>{{ Format::dateTime($ticket->closed_at) }} (بعد {{ $tookText }})</dd>@endif
            @if ($ticket->subject)<dt>الموضوع</dt><dd>{{ $ticket->subject }}</dd>@endif
            @if ($ticket->about instanceof \App\Models\Listing)<dt>العرض</dt><dd><a href="{{ Site::url('listings/'.$ticket->about->id) }}">{{ $ticket->about->title }}</a></dd>@endif
        </dl>
        @if ($closed && $ticket->resolution)
            <div class="note note-green section"><strong>الإجراء المتخذ:</strong> {{ $ticket->resolution }}</div>
        @endif
    </div>

    <div class="panel">
        <h2>الإجراءات والمراسلات</h2>
        <ol class="thread">
            @foreach ($ticket->messages as $message)
                <li @class(['msg', 'msg-staff' => $message->author === 'staff'])>
                    <div class="msg-head"><strong>{{ $message->author === 'staff' ? 'فريق زون' : 'أنت' }}</strong><span class="muted small">{{ Format::dateTime($message->created_at) }}</span></div>
                    <p class="prose">{{ $message->body }}</p>
                </li>
            @endforeach
            @if ($closed && $ticket->closed_at)
                <li class="msg msg-staff"><div class="msg-head"><strong>فريق زون</strong><span class="muted small">{{ Format::dateTime($ticket->closed_at) }}</span></div><p>أُغلقت الشكوى.</p></li>
            @endif
        </ol>
    </div>

    <form class="panel stack" method="post" action="{{ $link }}">
        @csrf
        <input type="hidden" name="started_at" value="{{ $startedAt }}">
        <div class="visually-hidden" aria-hidden="true"><label>لا تملأ هذا الحقل <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        @include('site.partials.errors')
        <div class="field">
            <label for="r-body">{{ $closed ? 'أضف رسالة (تُعيد فتح الشكوى)' : 'أضف رسالة' }}</label>
            <textarea class="input" id="r-body" name="body" rows="4" maxlength="2000" required>{{ old('body') }}</textarea>
        </div>
        <button class="btn btn-secondary btn-block" type="submit">إرسال</button>
    </form>
</div>
@endsection
