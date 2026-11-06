<?php

namespace App\Support\Permissions;

/**
 * Every permission of the admin panel (guard `web`). Roles are made of these in «الأدوار والصلاحيات»; the
 * superadmin («المدير العام») has them all, and alone reaches the SuperAgent, market reports, staff and roles.
 *
 * Some are the superadmin's alone and no role can carry them (superadminOnly()): users' private content,
 * money and prices, the platform's rules, and the audit trail. Staff::can() refuses them to anyone else.
 */
enum Permission: string
{
    case ViewDashboard = 'dashboard.view';
    case ViewCustomers = 'customers.view';
    case ManageCustomers = 'customers.manage';
    case SuspendCustomers = 'customers.suspend';
    case MessageCustomers = 'customers.message';
    case CreateDevelopers = 'developers.create';
    case ActivateDevelopers = 'developers.activate';
    case ViewListings = 'listings.view';
    case ModerateListings = 'listings.moderate';
    case ActivateListings = 'listings.activate';
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
            self::ManageCustomers => 'تعديل الحسابات (البيانات · حساب اختبار · فال · نفاذ · إلغاء الحذف)',
            self::SuspendCustomers => 'إيقاف الحسابات وفك إيقافها',
            self::MessageCustomers => 'مراسلة المستخدمين باسم «فريق زون»',
            self::CreateDevelopers => 'إنشاء حسابات المطوّرين',
            self::ActivateDevelopers => 'تفعيل المطوّرين وعقود التسويق',
            self::ViewListings => 'عرض العروض والمشاريع',
            self::ModerateListings => 'إشراف العروض والمشاريع (إخفاء · رفض · إرجاع)',
            self::ActivateListings => 'تحويل العروض بين «نشط» و«غير نشط» بسبب يصل للمعلن',
            self::ViewRequests => 'عرض الطلبات والرغبات',
            self::ModerateRequests => 'إشراف الطلبات',
            self::HandleReports => 'معالجة البلاغات',
            self::ModerateRatings => 'إشراف التقييمات',
            self::HandleSupport => 'الشكاوى والاقتراحات والمراسلات',
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
     * The superadmin's alone: no role carries them (Staff::can).
     *
     * @return list<self>
     */
    public static function superadminOnly(): array
    {
        return [
            self::ViewConversationContent, self::ViewCrmContent,       // users' private content
            self::ManagePackages, self::ManagePayments,                 // prices and money
            self::ManageSettings,                                       // the platform's rules
            self::ViewAudit,                                            // the trail of what staff did
        ];
    }

    public function isSuperadminOnly(): bool
    {
        return in_array($this, self::superadminOnly(), true);
    }

    /**
     * «مشرف»: reported listings, complaints and suggestions, messages to users as «فريق زون», a listing between
     * «نشط» and «غير نشط» with a reason, and an account suspended or let back in.
     *
     * @return list<self>
     */
    public static function supervisor(): array
    {
        return [
            self::ViewDashboard, self::ViewCustomers, self::SuspendCustomers, self::MessageCustomers,
            self::ViewListings, self::ActivateListings, self::HandleReports, self::HandleSupport,
        ];
    }
}
