<?php

namespace App\Enums;

/** The brokerage contract at REGA (approved by the owner through Nafath). */
enum ContractStatus: string
{
    use Concerns;

    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'أُرسل — بانتظار موافقة المالك',
            self::Approved => 'موافق عليه',
            self::Rejected => 'مرفوض',
            self::Expired => 'منتهٍ',
        };
    }
}
