<?php

namespace App\Models;

use App\Ai\Lab\LabLimits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model lab run (`zon-agents lab run`, docs/model-lab.md): a model profile measured on the golden cases.
 *
 * @property array<string, array<string, mixed>> $agents
 * @property array<string, mixed> $report
 */
class ModelEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['agents' => 'array', 'throughput' => 'array', 'report' => 'array', 'started_at' => 'datetime', 'overall' => 'float',
            'duration_s' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function score(string $agent): ?float
    {
        $score = $this->agents[$agent]['score'] ?? null;

        return is_numeric($score) ? (float) $score : null;
    }

    /** @return list<string> the limits this run does not meet (LabLimits), in Arabic — empty when it meets them all */
    public function unmetLimits(): array
    {
        return LabLimits::unmet($this->agents);
    }
}
