<?php

namespace App\Models\Concerns;

use App\Enums\TextReview;

/**
 * The three texts of a description (§10.2): desc_raw (the advertiser's words, kept), desc_suggested (the AI's
 * proposal), desc_clean (what is shown and indexed once decided). Until a decision the raw text is shown.
 *
 * @property string $desc_raw
 * @property ?string $desc_suggested
 * @property ?string $desc_clean
 * @property ?TextReview $text_review
 */
trait HasDescriptionReview
{
    /** The text the public sees and the index reads. */
    public function publicDescription(): string
    {
        return $this->desc_clean ?? $this->desc_raw;
    }

    public function textDecided(): bool
    {
        return $this->text_review?->isDecided() ?? false;
    }
}
