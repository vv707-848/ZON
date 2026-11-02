<?php

namespace App\SuperAgent;

/** Every approved template: 14 operational reports and 4 market reports. */
final class Catalog
{
    /** @var list<class-string<Template>> */
    public const OPERATIONAL = [
        Templates\TopDemandByNeighborhood::class, Templates\TopPropertyTypes::class, Templates\SupplyVsDemand::class,
        Templates\ListingFunnel::class, Templates\UserGrowth::class, Templates\BrokerActivity::class,
        Templates\MatchingPerformance::class, Templates\SubscriptionsSummary::class, Templates\PriceTrend::class,
        Templates\TimeToSell::class, Templates\SearchNoResults::class, Templates\UserFunnel::class,
        Templates\RevenueSummary::class, Templates\DeveloperProjectPerf::class,
    ];

    /** @var list<class-string<MarketTemplate>> */
    public const MARKET = [
        Templates\DemandSupplyGap::class, Templates\RealBudgetIndex::class, Templates\UnmetFeatures::class, Templates\RejectionReasons::class,
    ];

    /** @return array<string, Template> name => template */
    public static function all(): array
    {
        $all = [];
        foreach ([...self::OPERATIONAL, ...self::MARKET] as $class) {
            $template = new $class;
            $all[$template->name()] = $template;
        }

        return $all;
    }

    public static function find(string $name): ?Template
    {
        return self::all()[$name] ?? null;
    }
}
