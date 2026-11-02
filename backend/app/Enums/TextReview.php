<?php

namespace App\Enums;

/** Where the AI wording of a description stands (§10.2 step 5). */
enum TextReview: string
{
    use Concerns;

    case Pending = 'pending';
    case Ready = 'ready';
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الصياغة',
            self::Ready => 'بانتظار قرار المعلن',
            self::Approved => 'اعتمد المقترح',
            self::Edited => 'عدّله المعلن',
            self::Rejected => 'أبقى نصه الأصلي',
            self::Auto => 'نُشر بنصه بعد 72 ساعة',
        };
    }

    /** The text to index is settled (approved, edited, kept or published as written). */
    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::Edited, self::Rejected, self::Auto], true);
    }
}
