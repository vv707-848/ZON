<?php

namespace App\Filament\Concerns;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel's global search (⌘K / Ctrl+K) finds a record by what staff hold in hand — a phone in any Saudi format,
 * a reference, a licence or invoice number, an id — as well as by its words. Each resource names its identifiers
 * in identifierMatches(); what a member of staff may not open is never searched (Filament asks canAccess first).
 */
trait SearchesByIdentifier
{
    /**
     * Adds orWhere(…) clauses for the identifiers in $search (Arabic digits already ASCII).
     *
     * @param  Builder<Model>  $query
     */
    abstract protected static function identifierMatches(Builder $query, string $search): void;

    /**
     * @param  Builder<Model>  $query
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->where(function (Builder $either) use ($search) {
            $either->where(fn (Builder $words) => parent::applyGlobalSearchAttributeConstraints($words, $search));
            static::identifierMatches($either, trim(ArabicText::asciiDigits($search)));
        });
    }
}
