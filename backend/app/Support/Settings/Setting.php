<?php

namespace App\Support\Settings;

/**
 * Every business value the code reads and the admin panel edits («إعدادات المنصة») without a release.
 * The default lives here; a row in `settings` overrides it. Amounts are halalas, durations are whole units.
 * Prices of plans are not here: they are rows of `packages`.
 */
enum Setting: string
{
    // ── Listings
    case ReviewReminderHours = 'listings.review_reminder_hours';
    case ReviewAutoPublishHours = 'listings.review_auto_publish_hours';
    case ListingInactiveDays = 'listings.inactive_days';
    case ListingPhotosMax = 'listings.photos_max';
    case RequireNafath = 'listings.require_nafath';
    case ReportsToAutoHide = 'moderation.reports_to_auto_hide';

    // ── Boosts and one-off prices
    case BoostDays = 'boosts.days';
    case FeaturedPrice = 'boosts.featured_price';
    case UrgentListingPrice = 'boosts.urgent_listing_price';
    case UrgentRequestPrice = 'requests.urgent_price';
    case UrgentRequestDays = 'requests.urgent_days';

    // ── The owner's path and commissions
    case AdLicenceFee = 'owners.ad_licence_fee';
    case OwnerCommissionPercent = 'owners.commission_percent';
    case BrokerFreeSharePercent = 'brokers.free_share_percent';

    // ── Tax and invoices
    case VatRegistered = 'tax.vat_registered';
    case VatRate = 'tax.vat_rate';
    case VatNumber = 'tax.vat_number';
    case SellerName = 'tax.seller_name';

    // ── Wishes and requests
    case PreferenceActiveDays = 'preferences.active_days';
    case MatchNotifyMax = 'preferences.match_notify_max';

    // ── Accounts
    case DeletionGraceDays = 'accounts.deletion_grace_days';

    // ── The chat «زون»
    case ChatMaxChars = 'chat.max_chars';
    case ChatBlockedTerms = 'chat.blocked_terms';

    // ── Reports and the SuperAgent
    case MarketReportMinRequests = 'reports.market_min_requests';
    case SuperAgentDailyCap = 'reports.super_agent_daily_cap';

    public function default(): mixed
    {
        return match ($this) {
            self::ReviewReminderHours => 24,
            self::ReviewAutoPublishHours => 72,
            self::ListingInactiveDays => 14,
            self::ListingPhotosMax => 12,
            self::RequireNafath => true,
            self::ReportsToAutoHide => 3,
            self::BoostDays => 7,
            self::FeaturedPrice, self::UrgentListingPrice => 10_000,
            self::UrgentRequestPrice, self::AdLicenceFee => 5_000,
            self::UrgentRequestDays => 7,
            self::OwnerCommissionPercent => '0.5',
            self::BrokerFreeSharePercent => '10',
            self::VatRegistered => false,
            self::VatRate => '0.15',
            self::VatNumber => '',
            self::SellerName => 'منصة زون العقارية',
            self::PreferenceActiveDays => 90,
            self::MatchNotifyMax => 5,
            self::DeletionGraceDays => 30,
            self::ChatMaxChars => 500,
            self::ChatBlockedTerms => [],
            self::MarketReportMinRequests => 5,
            self::SuperAgentDailyCap => 30,
        };
    }

    /** int · bool · decimal (a string read by brick/math) · money (halalas) · text · list */
    public function type(): string
    {
        return match ($this) {
            self::RequireNafath, self::VatRegistered => 'bool',
            self::FeaturedPrice, self::UrgentListingPrice, self::UrgentRequestPrice, self::AdLicenceFee => 'money',
            self::OwnerCommissionPercent, self::BrokerFreeSharePercent, self::VatRate => 'decimal',
            self::VatNumber, self::SellerName => 'text',
            self::ChatBlockedTerms => 'list',
            default => 'int',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReviewReminderHours => 'تذكير المعلن بمراجعة الصياغة بعد (ساعة)',
            self::ReviewAutoPublishHours => 'النشر بالنص الأصلي إن لم يقرر المعلن بعد (ساعة)',
            self::ListingInactiveDays => 'يصير العرض غير نشط بلا تحديث بعد (يوم)',
            self::ListingPhotosMax => 'أقصى عدد للصور في العرض',
            self::RequireNafath => 'التحقق عبر نفاذ شرط للنشر',
            self::ReportsToAutoHide => 'عدد المبلّغين المختلفين للإخفاء الآلي',
            self::BoostDays => 'مدة التمييز والعاجل للعرض (يوم)',
            self::FeaturedPrice => 'سعر التمييز بعد نفاد الرصيد (ريال)',
            self::UrgentListingPrice => 'سعر «عاجل» للعرض بعد نفاد الرصيد (ريال)',
            self::UrgentRequestPrice => 'سعر الطلب العاجل (ريال)',
            self::UrgentRequestDays => 'مدة بقاء الطلب عاجلاً (يوم)',
            self::AdLicenceFee => 'رسوم الترخيص الإعلاني للمالك (ريال، تمرير للهيئة بلا ضريبة)',
            self::OwnerCommissionPercent => 'سعي المالك بعد البيع (٪ من سعر البيع)',
            self::BrokerFreeSharePercent => 'حصة زون من سعي الوسيط بلا اشتراك (٪ من السعي)',
            self::VatRegistered => 'المنصة مسجّلة في ضريبة القيمة المضافة',
            self::VatRate => 'نسبة الضريبة (0.15 = 15٪)',
            self::VatNumber => 'الرقم الضريبي',
            self::SellerName => 'اسم البائع في الفاتورة',
            self::PreferenceActiveDays => 'تتوقف إشعارات الرغبة بلا تحديث بعد (يوم)',
            self::MatchNotifyMax => 'أقصى عدد عروض في إشعار المطابقة الواحد',
            self::DeletionGraceDays => 'مهلة حذف الحساب (يوم)',
            self::ChatMaxChars => 'أقصى طول لرسالة الشات (حرف)',
            self::ChatBlockedTerms => 'كلمات المنع في الشات (سطر لكل كلمة)',
            self::MarketReportMinRequests => 'الحد الأدنى للطلبات في تقرير السوق (لا يُخفض تحت 5)',
            self::SuperAgentDailyCap => 'سقف تقارير الوكيل الأعلى اليومي',
        };
    }

    /** The section of the settings page. */
    public function group(): string
    {
        return match (strstr($this->value, '.', true)) {
            'listings', 'moderation' => 'العروض والإشراف',
            'boosts', 'requests' => 'التمييز والطلبات',
            'owners', 'brokers' => 'المالك والعمولات',
            'tax' => 'الضريبة والفواتير',
            'preferences', 'accounts' => 'الرغبات والحسابات',
            default => 'الذكاء والتقارير',
        };
    }

    /** The lowest value the platform accepts (market reports never go under 5 requests — k-anonymity). */
    public function minimum(): ?int
    {
        return match ($this) {
            self::MarketReportMinRequests => 5,
            self::ReportsToAutoHide, self::ListingPhotosMax, self::BoostDays, self::DeletionGraceDays => 1,
            self::ChatMaxChars => 50,
            default => $this->type() === 'int' || $this->type() === 'money' ? 0 : null,
        };
    }
}
