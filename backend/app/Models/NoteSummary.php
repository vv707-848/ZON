<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The stored summary of a client's, an owner's or the tasks' notes; reused free while the notes are unchanged. */
class NoteSummary extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signals' => 'array'];
    }
}
