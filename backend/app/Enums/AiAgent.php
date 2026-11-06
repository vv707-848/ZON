<?php

namespace App\Enums;

/** The agents of the agents service, by the name of POST /agents/{agent} (docs/contracts/agents-api.md). */
enum AiAgent: string
{
    use Concerns;

    case SearchListings = 'search_listings';
    case SearchRequests = 'search_requests';
    case Matching = 'matching';
    case MatchSearchers = 'match_searchers';
    case Chat = 'chat';
    case Summarize = 'summarize';
    case CleanListing = 'clean_listing';
    case CleanProject = 'clean_project';
    case CleanUnits = 'clean_units';
    case ReportSelect = 'report_select';
    case ReportExplore = 'report_explore';
    case ReportWrite = 'report_write';
    case SummarizeBatch = 'summarize_batch';

    public function label(): string
    {
        return match ($this) {
            self::SearchListings => 'البحث الدلالي في العروض',
            self::SearchRequests => 'البحث في الطلبات',
            self::Matching => 'مساعد المطابقة',
            self::MatchSearchers => 'الباحثون المطابقون للمشروع',
            self::Chat => 'الشات العام',
            self::Summarize => 'وكيل التلخيص',
            self::CleanListing => 'تنقية وصف العرض',
            self::CleanProject => 'تنقية وصف المشروع',
            self::CleanUnits => 'تنقية أوصاف النماذج',
            self::ReportSelect => 'اختيار تقارير الوكيل الأعلى',
            self::ReportExplore => 'استكشاف الوكيل الأعلى',
            self::ReportWrite => 'كتابة تقرير الوكيل الأعلى',
            self::SummarizeBatch => 'تلخيص الدفعات',
        };
    }
}
