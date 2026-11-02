<?php

namespace App\Enums;

/** The rule-based intent of a chat message (§10.5 step 2). */
enum ChatIntent: string
{
    use Concerns;

    case Knowledge = 'knowledge';
    case Packages = 'packages';
    case SemanticSearch = 'semantic_search';
    case SearchRequest = 'search_request';
    case Matching = 'matching';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Knowledge => 'سؤال معرفي',
            self::Packages => 'الباقات',
            self::SemanticSearch => 'البحث الدلالي',
            self::SearchRequest => 'طلب بحث',
            self::Matching => 'مساعد المطابقة',
            self::OutOfScope => 'خارج النطاق',
        };
    }
}
