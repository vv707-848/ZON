<?php

namespace App\SuperAgent;

use App\Enums\AnnouncementKind;
use App\Enums\CustomerRole;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Filament\Pages\AiMonitor;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\SearchRequests\SearchRequestResource;

/**
 * What the SuperAgent may propose under a recommendation: a screen of the panel, opened on the right filter or
 * form. It proposes and never acts — the person decides and does it there. report_write picks a proposal by name
 * and its parameters are checked like a template's (Param): one outside its list loses its button, not the text.
 */
enum Proposal: string
{
    case Announce = 'announce';
    case ReviewListings = 'review_listings';
    case ReviewRequests = 'review_requests';
    case ReviewAccounts = 'review_accounts';
    case ReviewPackages = 'review_packages';
    case AiMonitor = 'ai_monitor';

    /** The button. */
    public function title(): string
    {
        return match ($this) {
            self::Announce => 'كتابة إشعار عام',
            self::ReviewListings => 'مراجعة العروض',
            self::ReviewRequests => 'مراجعة الطلبات',
            self::ReviewAccounts => 'مراجعة الحسابات',
            self::ReviewPackages => 'مراجعة الباقات',
            self::AiMonitor => 'مراقبة الذكاء',
        };
    }

    /** What it is for, in the catalog report_write receives. */
    public function description(): string
    {
        return match ($this) {
            self::Announce => 'إشعار عام لدور في مدينة (مثلاً: دعوة الوسطاء أو الملاك لإضافة عروض حيث الطلب أعلى من العرض). يُفتح النموذج ويكتب المدير نصه ويرسله',
            self::ReviewListings => 'قائمة العروض مصفّاة بالمدينة والنوع والحالة (مثلاً: عروض تنتظر مراجعة، أو عروض نوع قليل المعروض)',
            self::ReviewRequests => 'قائمة طلبات البحث مصفّاة بالمدينة والحالة (طلب بلا عرض يقابله)',
            self::ReviewAccounts => 'قائمة الحسابات مصفّاة بالدور والمدينة (مثلاً: وسطاء سجّلوا ولم ينشروا)',
            self::ReviewPackages => 'الباقات وأسعارها ومزاياها، حين تضعف الاشتراكات أو الإيراد. قرار السعر لصاحب المنصة',
            self::AiMonitor => 'مراقبة الذكاء: نسب الرد الكامل والبديل والزمن وأسباب «غير مطابق»، حين تتراجع المطابقة أو البحث',
        };
    }

    /** @return array<string, Param> */
    public function params(): array
    {
        return match ($this) {
            self::Announce => ['audience' => Param::oneOf('إلى', CustomerRole::values(), required: true), 'city' => Param::city(required: false),
                'kind' => Param::oneOf('نوع الرسالة', AnnouncementKind::values())],
            self::ReviewListings => ['city' => Param::city(required: false), 'property_type' => Param::propertyType(), 'status' => Param::oneOf('الحالة', ListingStatus::values())],
            self::ReviewRequests => ['city' => Param::city(required: false), 'status' => Param::oneOf('الحالة', RequestStatus::values())],
            self::ReviewAccounts => ['role' => Param::oneOf('الدور', CustomerRole::values()), 'city' => Param::city(required: false)],
            self::ReviewPackages, self::AiMonitor => [],
        };
    }

    /**
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    public function checkParams(array $given): array
    {
        return Param::checkAll($this->params(), $given);
    }

    /** @return list<array<string, mixed>> every proposal as report_write sees it */
    public static function catalog(): array
    {
        return array_map(fn (self $p) => [
            'action' => $p->value,
            'description' => $p->description(),
            'params' => (object) array_map(fn (Param $param) => $param->schema(), $p->params()),   // {} when none, never []
        ], self::cases());
    }

    /**
     * The screen, opened on the parameters (already checked) — or null when the viewer may not open it.
     *
     * @param  array<string, mixed>  $params
     */
    public function url(array $params): ?string
    {
        $set = array_filter($params, fn ($value) => $value !== null && $value !== '');
        $filters = fn (array $names) => ['filters' => array_map(fn ($value) => ['value' => $value], array_intersect_key($set, array_flip($names)))];

        return match ($this) {
            self::Announce => AnnouncementResource::canCreate() ? AnnouncementResource::getUrl('create', $set) : null,
            self::ReviewListings => ListingResource::canViewAny() ? ListingResource::getUrl('index', $filters(['city', 'property_type', 'status'])) : null,
            self::ReviewRequests => SearchRequestResource::canViewAny() ? SearchRequestResource::getUrl('index', $filters(['city', 'status'])) : null,
            self::ReviewAccounts => CustomerResource::canViewAny() ? CustomerResource::getUrl('index', $filters(['role', 'city'])) : null,
            self::ReviewPackages => PackageResource::canViewAny() ? PackageResource::getUrl('index') : null,
            self::AiMonitor => AiMonitor::canAccess() ? AiMonitor::getUrl() : null,
        };
    }
}
