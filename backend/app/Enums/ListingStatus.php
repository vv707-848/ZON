<?php

namespace App\Enums;

/**
 * The state machine of a listing or a project (backend doc §7). A transition not listed in ALLOWED is refused
 * in the service layer (INVALID_TRANSITION), never only hidden in the app.
 */
enum ListingStatus: string
{
    use Concerns;

    case Draft = 'draft';
    case Indexing = 'indexing';               // saved; the AI wording is being prepared (§10.2 step 2)
    case PendingReview = 'pending_review';    // waiting for the advertiser's decision on the wording
    case Published = 'published';
    case Paused = 'paused';
    case Inactive = 'inactive';               // two weeks without an update (architecture's decisions table)
    case Sold = 'sold';                       // final — the row stays: what sold through Zon is the revenue model
    case Hidden = 'hidden';                   // by moderation (three reports, staff, a deleted account)
    case Rejected = 'rejected';               // by staff
    case LicenceExpired = 'licence_expired';  // its advertising licence passed its last day; a renewal brings it back

    /** from => the states it may go to */
    private const ALLOWED = [
        'draft' => ['indexing', 'pending_review', 'published'],
        'indexing' => ['pending_review', 'published', 'draft', 'licence_expired'],
        'pending_review' => ['published', 'draft', 'indexing', 'licence_expired'],
        'published' => ['indexing', 'paused', 'inactive', 'sold', 'hidden', 'rejected', 'licence_expired'],
        'paused' => ['published', 'sold', 'hidden', 'rejected', 'licence_expired'],
        'inactive' => ['published', 'indexing', 'sold', 'hidden', 'rejected', 'licence_expired'],
        'hidden' => ['published', 'paused', 'rejected', 'licence_expired'],
        'rejected' => ['published'],
        'licence_expired' => ['published', 'sold', 'hidden', 'rejected'],
        'sold' => [],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Indexing => 'قيد الصياغة',
            self::PendingReview => 'بانتظار مراجعتك',
            self::Published => 'منشور',
            self::Paused => 'متوقف مؤقتاً',
            self::Inactive => 'غير نشط',
            self::Sold => 'تم البيع',
            self::Hidden => 'مخفي',
            self::Rejected => 'مرفوض',
            self::LicenceExpired => 'منتهي الترخيص',
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to->value, self::ALLOWED[$this->value], true);
    }

    /*
     * The advertiser's own buttons. The machine above also serves staff and the system, so these are narrower:
     * hidden and rejected belong to moderation — only staff (ModerateContent) or a rejected report on an
     * automatically hidden item (HandleReport) bring an item back from them.
     */

    /** «إيقاف مؤقت»: only a public item. */
    public function canBePausedByAdvertiser(): bool
    {
        return $this === self::Published;
    }

    /** «استئناف»: what he paused himself, or what went inactive. */
    public function canBeResumedByAdvertiser(): bool
    {
        return $this === self::Paused || $this === self::Inactive;
    }

    /** A first publication: only an item that was never public. */
    public function isBeforePublication(): bool
    {
        return $this === self::Draft || $this === self::Indexing || $this === self::PendingReview;
    }

    /** Seen by everyone and in the search index. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
