<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/** The sections of the panel's menu, in their order (AdminPanelProvider). */
enum NavigationGroup: string implements HasLabel
{
    case Accounts = 'accounts';
    case Content = 'content';
    case Moderation = 'moderation';
    case Finance = 'finance';
    case Knowledge = 'knowledge';
    case Operations = 'operations';
    case Administration = 'administration';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accounts => 'الحسابات',
            self::Content => 'المحتوى',
            self::Moderation => 'الإشراف',
            self::Finance => 'المالية',
            self::Knowledge => 'المعرفة والمرجع',
            self::Operations => 'التشغيل',
            self::Administration => 'الإدارة',
        };
    }
}
