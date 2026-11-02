<?php

namespace App\Ai\Capture;

use App\Ai\Masking\AiMasking;
use App\Models\TrainingSample;

/**
 * The capture layer (§10.9): what will one day teach the local model — de-identified at the source (AiMasking)
 * and never linked to an account (no customer id is stored).
 */
final class TrainingCapture
{
    public static function textEdit(string $suggested, string $edited): void
    {
        self::store('text_edit', "المقترح: {$suggested}\nالمعتمد: {$edited}", 'wording', 'edited');
    }

    public static function note(string $source, string $text, ?string $category): void
    {
        self::store($source, $text, $category, null);
    }

    public static function searchMiss(string $text): void
    {
        self::store('search_miss', $text, 'search', 'no_results');
    }

    public static function feedback(string $reason, bool $isMatch): void
    {
        self::store('feedback', $reason, 'matching', $isMatch ? 'match' : 'mismatch');
    }

    private static function store(string $source, string $text, ?string $intent, ?string $outcome): void
    {
        $text = trim(AiMasking::text($text));
        if ($text === '') {
            return;
        }
        TrainingSample::query()->create(['source' => $source, 'text_masked' => mb_substr($text, 0, 4000), 'intent_tag' => $intent, 'outcome_tag' => $outcome, 'captured_at' => now()]);
    }
}
