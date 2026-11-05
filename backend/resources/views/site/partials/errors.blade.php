@if ($errors->any())
    <div class="note note-danger" role="alert">
        <ul class="ticks">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
