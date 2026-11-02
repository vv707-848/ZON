<?php

namespace App\Enums;

/** Outcome of one AI request (ai_requests.status). Only `ok` is charged to the daily quota (§10.1 step 11). */
enum AiCallStatus: string
{
    use Concerns;

    case Ok = 'ok';
    case Cached = 'cached';
    case Degraded = 'degraded';
    case Fallback = 'fallback';
    case Timeout = 'timeout';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'نجح',
            self::Cached => 'من الذاكرة المؤقتة',
            self::Degraded => 'ترتيب بلا تعليل',
            self::Fallback => 'المسار البديل',
            self::Timeout => 'تجاوز المهلة',
            self::Rejected => 'مخرج مرفوض',
        };
    }
}
