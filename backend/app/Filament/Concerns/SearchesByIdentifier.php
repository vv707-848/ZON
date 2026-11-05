<?php

namespace App\Filament\Concerns;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;

/**
 * The panel's global search (⌘K / Ctrl+K) finds a record by what staff hold in hand — a phone in any Saudi format,
 * a reference, a licence or invoice number, an id — as well as by its words. Each resource names its identifiers
 * in identifierMatches(); what a member of staff may not open is never searched (Filament asks canAccess first).
 */
trait SearchesByIdentifier
{
    /** Adds orWhere(…) clauses for the identifiers in $search (Arabic digits already ASCII). */
    abstract protected static function identifierMatches(Builder $query, string $search): void;

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->where(function (Builder $either) use ($search) {
            $either->where(fn (Builder $words) => parent::applyGlobalSearchAttributeConstraints($words, $search));
            static::identifierMatches($either, trim(ArabicText::asciiDigits($search)));
        });
    }
}
