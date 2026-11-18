<?php

namespace App\SuperAgent;

use App\Enums\PropertyType;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/**
 * A market report: built from the daily snapshots and opted-in wishes only, and every row stands for at least
 * Setting::MarketReportMinRequests requests (never fewer than 5) so no person can be singled out.
 */
abstract class MarketTemplate extends Template
{
    public static function minimumGroup(): int
    {
        return max(5, Settings::int(Setting::MarketReportMinRequests));
    }

    /**
     * Every sign of demand of one city for buy or rent, one row per neighbourhood × type it names (a list with no type
     * gives one row with the type NULL: any type):
     *   search  — a semantic search since $since; `who` is the month's searcher (people, not clicks)
     *   wish    — a wish of someone who shares it in reports: the active ones, or with $wishesSince those made since
     *   request — a request posted since $since
     * Columns: src · who (unique within its source) · neighborhood · property_type · budget_max · bedrooms · created_at.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    protected static function demand(string $city, string $purpose, string $since, bool $wishesSince = false): array
    {
        $spread = fn (string $alias) => 'JOIN '.self::items("{$alias}.neighborhoods", 'neighborhood', 80).' n
            LEFT JOIN '.self::items("{$alias}.property_types", 'property_type', 12).' t ON TRUE';
        $wishes = $wishesSince ? 'w.created_at >= ?' : 'w.is_active = 1';

        return ["SELECT 'search' AS src, d.searcher AS who, n.neighborhood, t.property_type, d.budget_max, d.bedrooms, d.created_at
                FROM ai_v_search_demand d {$spread('d')} WHERE d.city = ? AND d.purpose = ? AND d.created_at >= ?
            UNION ALL
            SELECT 'wish', CONCAT('w', w.id), n.neighborhood, t.property_type, w.budget_max, w.bedrooms, w.created_at
                FROM ai_v_preferences w {$spread('w')} WHERE w.city = ? AND w.purpose = ? AND {$wishes}
            UNION ALL
            SELECT 'request', CONCAT('r', r.id), n.neighborhood, t.property_type, r.budget_max, r.rooms_min, r.created_at
                FROM ai_v_requests r {$spread('r')} WHERE r.city = ? AND r.purpose = ? AND r.created_at >= ?",
            [$city, $purpose, $since, $city, $purpose, ...($wishesSince ? [$since] : []), $city, $purpose, $since]];
    }

    /** The type's Arabic name in a result (`any type` for NULL), so a report shown outside the panel needs no lookup. */
    protected static function typeName(string $column): string
    {
        $cases = implode(' ', array_map(fn (PropertyType $t) => "WHEN '{$t->value}' THEN '".str_replace("'", "''", $t->label())."'", PropertyType::cases()));

        return "CASE WHEN {$column} IS NULL THEN 'أي نوع' ELSE (CASE {$column} {$cases} ELSE {$column} END) END";
    }
}
