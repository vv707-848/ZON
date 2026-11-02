<?php

namespace App\Enums;

/**
 * Stable error codes of the API (backend doc §14). The app branches on `error.code`, never on the message.
 * `UserBlocked` and `QuotaExceeded` say nothing about which rule stopped the request (§14.3).
 */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case OtpInvalid = 'OTP_INVALID';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case AccountPending = 'ACCOUNT_PENDING';
    case RoleForbidden = 'ROLE_FORBIDDEN';
    case FalRequired = 'FAL_REQUIRED';
    case NafathRequired = 'NAFATH_REQUIRED';
    case NotFound = 'NOT_FOUND';
    case ValidationFailed = 'VALIDATION_FAILED';
    case PreferenceRequired = 'PREFERENCE_REQUIRED';
    case PaymentRequired = 'PAYMENT_REQUIRED';
    case PlanLimitReached = 'PLAN_LIMIT_REACHED';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case InvalidTransition = 'INVALID_TRANSITION';
    case AlreadyActive = 'ALREADY_ACTIVE';
    case ProjectNoUnits = 'PROJECT_NO_UNITS';
    case LicenceRequired = 'LICENCE_REQUIRED';
    case UserBlocked = 'USER_BLOCKED';
    case UpdateRequired = 'UPDATE_REQUIRED';
    case MaintenanceMode = 'MAINTENANCE_MODE';
    case IntegrationFailed = 'INTEGRATION_FAILED';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::PaymentRequired => 402,
            self::AccountSuspended, self::AccountPending, self::RoleForbidden, self::FalRequired, self::NafathRequired, self::UserBlocked => 403,
            self::NotFound => 404,
            self::InvalidTransition, self::AlreadyActive, self::ProjectNoUnits, self::PlanLimitReached, self::LicenceRequired => 409,
            self::ValidationFailed, self::OtpInvalid => 422,
            self::UpdateRequired => 426,
            self::PreferenceRequired => 428,
            self::QuotaExceeded, self::TooManyRequests => 429,
            self::IntegrationFailed => 502,
            self::MaintenanceMode => 503,
            self::ServerError => 500,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'سجّل الدخول للمتابعة.',
            self::InvalidCredentials => 'اسم المستخدم أو كلمة السر غير صحيحة.',
            self::OtpInvalid => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
            self::AccountSuspended => 'الحساب موقوف. تواصل مع الدعم.',
            self::AccountPending => 'حسابك بانتظار التفعيل بعد توقيع عقد التسويق.',
            self::RoleForbidden => 'هذه الخدمة غير متاحة لدورك.',
            self::FalRequired => 'رخصة فال سارية مطلوبة للنشر.',
            self::NafathRequired => 'التحقق عبر نفاذ مطلوب قبل النشر.',
            self::NotFound => 'العنصر غير موجود.',
            self::ValidationFailed => 'تحقق من الحقول المطلوبة.',
            self::PreferenceRequired => 'أكمل رغبتك أولاً لتبدأ.',
            self::PaymentRequired => 'هذه الخدمة ضمن الباقات.',
            self::PlanLimitReached => 'بلغت حد باقتك.',
            self::QuotaExceeded => 'تجاوزت الحد المسموح، حاول بعد التجديد.',
            self::TooManyRequests => 'تجاوزت الحد المسموح، حاول بعد قليل.',
            self::InvalidTransition => 'لا يمكن تنفيذ هذا الإجراء على حالته الحالية.',
            self::AlreadyActive => 'مفعّل بالفعل.',
            self::ProjectNoUnits => 'أضف نموذج وحدة واحداً على الأقل قبل النشر.',
            self::LicenceRequired => 'الترخيص الإعلاني مطلوب قبل النشر.',
            self::UserBlocked => 'تعذّر إرسال الرسالة.',
            self::UpdateRequired => 'حدّث التطبيق للمتابعة.',
            self::MaintenanceMode => 'المنصة تحت الصيانة، نعود قريباً.',
            self::IntegrationFailed => 'تعذّر الاتصال بالجهة المختصة، حاول لاحقاً.',
            self::ServerError => 'حدث خطأ غير متوقع.',
        };
    }
}
