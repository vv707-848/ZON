<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** عملاء الوسطاء: توزيع الوسطاء حسب عدد عملائهم في إدارة العملاء، مع عدد العملاء والملاحظات (بلا أسماء). */
final class BrokerClients extends Template
{
    public function name(): string
    {
        return 'broker_clients';
    }

    public function title(): string
    {
        return 'عملاء الوسطاء وملاحظاتهم';
    }

    public function description(): string
    {
        return 'توزيع الوسطاء حسب عدد عملائهم في إدارة العملاء، ومجموع العملاء والملاحظات في كل فئة (بلا أسماء).';
    }

    public function params(): array
    {
        return ['city' => Param::city(required: false)];
    }

    public function columns(): array
    {
        return ['bucket' => 'عدد العملاء', 'brokers' => 'الوسطاء', 'clients' => 'العملاء', 'notes' => 'الملاحظات', 'notes_per_client' => 'ملاحظات لكل عميل'];
    }

    public function query(array $params): array
    {
        return ["SELECT CASE WHEN clients = 0 THEN '0' WHEN clients <= 5 THEN '1-5' WHEN clients <= 20 THEN '6-20' ELSE '21+' END AS bucket,
                COUNT(*) AS brokers, SUM(clients) AS clients, SUM(notes) AS notes, ROUND(SUM(notes) / NULLIF(SUM(clients), 0), 1) AS notes_per_client
            FROM (SELECT b.id, COUNT(k.id) AS clients, COALESCE(SUM(k.notes_count), 0) AS notes FROM ai_v_customers b
                  LEFT JOIN ai_v_crm_clients k ON k.broker_id = b.id AND (? IS NULL OR k.city = ?)
                  WHERE b.role = 'broker' AND b.status = 'active' AND (? IS NULL OR b.city = ?) GROUP BY b.id) x
            GROUP BY bucket ORDER BY MIN(clients)", [$params['city'], $params['city'], $params['city'], $params['city']]];
    }
}
