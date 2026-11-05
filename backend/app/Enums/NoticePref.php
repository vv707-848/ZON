<?php

namespace App\Enums;

/**
 * The notification switches of the app's settings (settings_view.dart _notes): the six of the app, on by default,
 * and «العروض والتسويق», off until the person turns it on — a promotion's push needs that consent (App Store Review
 * Guideline 4.5.4; PDPL Art. 25), its bell notice does not.
 */
enum NoticePref: string
{
    use Concerns;

    case Messages = 'messages';
    case FollowedPosts = 'followed_posts';
    case AreaRequests = 'area_requests';
    case ListingStatus = 'listing_status';
    case Ratings = 'ratings';
    case Matching = 'matching';
    case Marketing = 'marketing';

    public function byDefault(): bool
    {
        return $this !== self::Marketing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Messages => 'رسائل',
            self::FollowedPosts => 'نشر المتابَعين',
            self::AreaRequests => 'طلبات المنطقة',
            self::ListingStatus => 'حالة الإعلان',
            self::Ratings => 'تقييمات',
            self::Matching => 'مطابقة',
            self::Marketing => 'العروض والتسويق',
        };
    }
}
