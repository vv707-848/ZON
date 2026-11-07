<?php

namespace App\Ai\Capture;

use App\Ai\Masking\AiMasking;
use App\Models\Customer;
use App\Models\TrainingSample;

/**
 * The capture layer (§10.9): what will one day teach the local model — de-identified at the source (AiMasking,
 * with the author's own name) and never linked to an account (no customer id is stored), so a sample cannot be
 * found again to delete. Who wrote it also leaves a tester's words out (Sandbox): a store reviewer's «تجربة»
 * teaches nothing.
 */
final class TrainingCapture
{
    public static function textEdit(string $suggested, string $edited, ?Customer $from): void
    {
        self::store($from, 'text_edit', "المقترح: {$suggested}\nالمعتمد: {$edited}", 'wording', 'edited');
    }

    public static function note(string $source, string $text, ?string $category, ?Customer $from): void
    {
        self::store($from, $source, $text, $category, null);
    }

    public static function searchMiss(string $text, ?Customer $from): void
    {
        self::store($from, 'search_miss', $text, 'search', 'no_results');
    }

    public static function feedback(string $reason, bool $isMatch, ?Customer $from): void
    {
        self::store($from, 'feedback', $reason, 'matching', $isMatch ? 'match' : 'mismatch');
    }

    private static function store(?Customer $from, string $source, string $text, ?string $intent, ?string $outcome): void
    {
        if ($from !== null && $from->is_tester) {
            return;
        }
        $text = trim(AiMasking::text($text, $from === null ? [] : [$from->name]));
        if ($text === '') {
            return;
        }
        TrainingSample::query()->create(['source' => $source, 'text_masked' => mb_substr($text, 0, 4000), 'intent_tag' => $intent, 'outcome_tag' => $outcome, 'captured_at' => now()]);
    }
}
