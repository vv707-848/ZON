<?php

namespace App\Support\Permissions;

/**
 * Every permission of the admin panel (guard `web`). Roles are made of these in «الأدوار والصلاحيات»;
 * the superadmin has them all, and alone reaches the SuperAgent, market reports, staff accounts and roles.
 * The two *_content permissions open private data of users and every use of them is written to the audit log.
 */
enum Permission: string
{
    case ViewDashboard = 'dashboard.view';
    case ViewCustomers = 'customers.view';
    case ManageCustomers = 'customers.manage';
    case CreateDevelopers = 'developers.create';
    case ActivateDevelopers = 'developers.activate';
    case ViewListings = 'listings.view';
    case ModerateListings = 'listings.moderate';
    case ViewRequests = 'requests.view';
    case ModerateRequests = 'requests.moderate';
    case HandleReports = 'reports.handle';
    case ModerateRatings = 'ratings.moderate';
    case HandleSupport = 'support.handle';
    case HandleLegacyClaims = 'legacy_claims.handle';
    case ViewConversationContent = 'conversations.content';
    case ViewCrmContent = 'crm.content';
    case ManagePackages = 'packages.manage';
    case ViewPayments = 'payments.view';
    case ManagePayments = 'payments.manage';
    case ManageKnowledge = 'knowledge.manage';
    case ManageSearchTerms = 'search_terms.manage';
    case ViewAi = 'ai.view';
    case ManageIndex = 'index.manage';
    case ViewHealth = 'health.view';
    case ManageAppConfig = 'app_config.manage';
    case ManageSettings = 'settings.manage';
    case ManageReference = 'reference.manage';
    case SendAnnouncements = 'announcements.send';
    case ViewAudit = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::ViewDashboard => 'عرض الرئيسية والإحصاءات',
            self::ViewCustomers => 'عرض الحسابات',
            self::ManageCustomers => 'إدارة الحسابات (إيقاف · تفعيل · تعديل)',
            self::CreateDevelopers => 'إنشاء حسابات المطوّرين',
            self::ActivateDevelopers => 'تفعيل المطوّرين وعقود التسويق',
            self::ViewListings => 'عرض العروض والمشاريع',
            self::ModerateListings => 'إشراف العروض والمشاريع (إخفاء · رفض · إرجاع)',
            self::ViewRequests => 'عرض الطلبات والرغبات',
            self::ModerateRequests => 'إشراف الطلبات',
            self::HandleReports => 'معالجة البلاغات',
            self::ModerateRatings => 'إشراف التقييمات',
            self::HandleSupport => 'الرد على تذاكر الدعم',
            self::HandleLegacyClaims => 'مراجعة طلبات استعادة البيانات',
            self::ViewConversationContent => 'الاطلاع على نص المحادثات (حساس · مسجَّل)',
            self::ViewCrmContent => 'الاطلاع على محتوى إدارة العملاء (حساس · مسجَّل)',
            self::ManagePackages => 'إدارة الباقات وحدودها',
            self::ViewPayments => 'عرض المدفوعات والفواتير',
            self::ManagePayments => 'الاسترداد والمنح والعمولات',
            self::ManageKnowledge => 'إدارة مكتبة المعرفة',
            self::ManageSearchTerms => 'إدارة المصطلحات والمرادفات',
            self::ViewAi => 'مراقبة الذكاء',
            self::ManageIndex => 'مراقبة الفهرسة وإعادة الإرسال',
            self::ViewHealth => 'صحة النظام وتتبّع الطلبات',
            self::ManageAppConfig => 'إصدار التطبيق ووضع الصيانة',
            self::ManageSettings => 'إعدادات المنصة',
            self::ManageReference => 'المدن والأحياء وصفحات السياسات',
            self::SendAnnouncements => 'إرسال الإشعارات العامة',
            self::ViewAudit => 'سجل التدقيق',
        };
    }

    /**
     * The content moderator role: content, reports, support — no money, no settings, no private data.
     *
     * @return list<self>
     */
    public static function contentModerator(): array
    {
        return [
            self::ViewDashboard, self::ViewCustomers, self::ViewListings, self::ModerateListings, self::ViewRequests,
            self::ModerateRequests, self::HandleReports, self::ModerateRatings, self::HandleSupport, self::ManageSearchTerms,
            self::ManageKnowledge,
        ];
    }
}
