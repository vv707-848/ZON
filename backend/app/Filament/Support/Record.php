<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Filament hands a page or an action a plain Model; this returns it as the page's own model class, or stops loudly. */
final class Record
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function as(Model $record, string $class): Model
    {
        if (! $record instanceof $class) {
            throw new LogicException($class.' expected, '.$record::class.' given');
        }

        return $record;
    }
}
