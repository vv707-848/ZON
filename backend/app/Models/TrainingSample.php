<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The capture layer (§10.9): de-identified text with tags, never linked to an account. */
class TrainingSample extends Model
{
    public $timestamps = false;

    protected $table = 'training_corpus';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }
}
