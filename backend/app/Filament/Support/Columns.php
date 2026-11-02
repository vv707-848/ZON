<?php

namespace App\Filament\Support;

use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/** Columns and entries shared by the panel: enums with their Arabic labels, halalas as riyals, phones as the app shows them. */
final class Columns
{
    /** @param array<string, string> $colors value => color */
    public static function enum(string $name, string $label, array $colors = []): TextColumn
    {
        return TextColumn::make($name)->label($label)->badge()
            ->formatStateUsing(fn (?BackedEnum $state) => $state !== null && method_exists($state, 'label') ? $state->label() : '—')
            ->color(fn (?BackedEnum $state) => $state === null ? 'gray' : ($colors[$state->value] ?? 'gray'));
    }

    public static function money(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) (new Money((int) $state))->riyals(), 2).' ر.س');
    }

    public static function phone(string $name = 'phone', string $label = 'الجوال'): TextColumn
    {
        return TextColumn::make($name)->label($label)->formatStateUsing(fn (?string $state) => $state === null ? '—' : Phone::local($state))->copyable();
    }

    public static function enumEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)->label($label)->badge()
            ->formatStateUsing(fn (?BackedEnum $state) => $state !== null && method_exists($state, 'label') ? $state->label() : '—');
    }

    public static function moneyEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)->label($label)
            ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) (new Money((int) $state))->riyals(), 2).' ر.س');
    }

    /** The common status colours of listings, projects, requests. */
    public const STATUS_COLORS = [
        'published' => 'success', 'pending_review' => 'warning', 'indexing' => 'info', 'paused' => 'gray', 'inactive' => 'gray',
        'hidden' => 'danger', 'rejected' => 'danger', 'sold' => 'primary', 'draft' => 'gray', 'closed' => 'gray',
        'active' => 'success', 'pending' => 'warning', 'suspended' => 'danger', 'valid' => 'success', 'verified' => 'success',
        'paid' => 'success', 'failed' => 'danger', 'unapplied' => 'warning', 'refunded' => 'gray', 'due' => 'warning', 'waived' => 'gray',
        'new' => 'danger', 'reviewing' => 'warning', 'accepted' => 'success', 'open' => 'warning', 'answered' => 'success',
    ];
}
