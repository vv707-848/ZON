{{-- A Lucide icon of the app (App\Support\Site\Icons), in the text's colour. --}}
@props(['name', 'size' => 20, 'stroke' => 1.8, 'class' => ''])
{!! \App\Support\Site\Icons::svg($name, (int) $size, (float) $stroke, $class) !!}
