<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** نشاط الوسطاء: توزيع الوسطاء حسب عدد العروض التي نشروها في الفترة (بلا أسماء). */
final class BrokerActivity extends Template
{
    public function name(): string
    {
        return 'broker_activity';
    }

    public function title(): string
    {
        return 'نشاط الوسطاء';
    }

    public function description(): string
    {
        return 'توزيع الوسطاء حسب عدد العروض التي نشروها في الفترة (بلا أسماء).';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['bucket' => 'الفئة', 'brokers' => 'الوسطاء'];
    }

    public function query(array $params): array
    {
        return ["SELECT CASE WHEN n = 0 THEN '0' WHEN n <= 5 THEN '1-5' WHEN n <= 20 THEN '6-20' ELSE '21+' END AS bucket, COUNT(*) AS brokers
            FROM (SELECT c.id, COUNT(l.id) AS n FROM ai_v_customers c
                  LEFT JOIN ai_v_listings l ON l.advertiser_id = c.id AND l.published_at >= ?
                  WHERE c.role = 'broker' AND c.status = 'active' GROUP BY c.id) b
            GROUP BY bucket ORDER BY MIN(n)", [self::since($params['days'])]];
    }
}
