<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** Staff initials drawn here, as an SVG data URI: Filament's default provider sends every staff name to ui-avatars.com. */
final class LocalAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initial = mb_substr(trim(Filament::getNameForDefaultAvatar($record)), 0, 1) ?: '•';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#1B4D3E"/>'
            .'<text x="32" y="42" font-size="30" text-anchor="middle" fill="#fff" font-family="sans-serif">'.e($initial).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
