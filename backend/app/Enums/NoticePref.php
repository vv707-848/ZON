<?php

namespace App\Enums;

/** The six notification switches of the app's settings (settings_view.dart _notes); every switch is on by default. */
enum NoticePref: string
{
    use Concerns;

    case Messages = 'messages';
    case FollowedPosts = 'followed_posts';
    case AreaRequests = 'area_requests';
    case ListingStatus = 'listing_status';
    case Ratings = 'ratings';
    case Matching = 'matching';

    public function label(): string
    {
        return match ($this) {
            self::Messages => 'رسائل',
            self::FollowedPosts => 'نشر المتابَعين',
            self::AreaRequests => 'طلبات المنطقة',
            self::ListingStatus => 'حالة الإعلان',
            self::Ratings => 'تقييمات',
            self::Matching => 'مطابقة',
        };
    }
}
