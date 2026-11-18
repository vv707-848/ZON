<?php

namespace App\SuperAgent;

/**
 * Demand and supply by band (budget, rooms): each person counted once in a band, however many neighbourhoods and types
 * his wish names; the published listings of the same city, buy or rent, neighbourhood and type in the same band beside
 * them. A band under the minimum group is left out like any market row.
 */
abstract class BandedDemand extends MarketTemplate
{
    /**
     * @param  array<string, mixed>  $params
     * @return list<array{0: string, 1: int|float, 2: int|float}> label, from (included), to (excluded)
     */
    abstract protected function bands(array $params): array;

    /** The column of the demand rows (MarketTemplate::demand) the bands read. */
    abstract protected function demandColumn(): string;

    /** The column of ai_v_listings read against the same bands. */
    abstract protected function listingColumn(): string;

    public function params(): array
    {
        return ['city' => Param::city(), 'purpose' => Param::purpose(), 'neighborhood' => Param::neighborhood(), 'property_type' => Param::propertyType(), 'days' => Param::days(90)];
    }

    public function query(array $params): array
    {
        $bands = [];
        $bandBindings = [];
        foreach ($this->bands($params) as $i => [$label, $from, $to]) {
            $bands[] = 'SELECT ? AS band_no, ? AS band, ? AS lo, ? AS hi';
            array_push($bandBindings, $i + 1, $label, $from, $to);
        }
        [$demand, $demandBindings] = self::demand($params['city'], $params['purpose'], self::since($params['days']));
        $hood = [$params['neighborhood'], $params['neighborhood']];
        $type = [$params['property_type'], $params['property_type']];

        // the share is of all the demand, counted before the bands under the minimum are left out
        return ['SELECT band, demand, demand_share, listings, ROUND(demand / GREATEST(listings, 1), 2) AS demand_per_listing
            FROM (SELECT b.band_no, b.band, COUNT(DISTINCT x.src, x.who) AS demand,
                ROUND(COUNT(DISTINCT x.src, x.who) / NULLIF(SUM(COUNT(DISTINCT x.src, x.who)) OVER (), 0), 2) AS demand_share,
                (SELECT COUNT(*) FROM ai_v_listings l WHERE l.status = \'published\' AND l.city = ? AND l.purpose = ?
                    AND (? IS NULL OR l.neighborhood = ?) AND (? IS NULL OR l.property_type = ?)
                    AND l.'.$this->listingColumn().' >= b.lo AND l.'.$this->listingColumn().' < b.hi) AS listings
            FROM ('.implode(' UNION ALL ', $bands).') b
            LEFT JOIN (SELECT * FROM ('.$demand.') d WHERE (? IS NULL OR d.neighborhood = ?) AND (? IS NULL OR d.property_type = ?)) x
                ON x.'.$this->demandColumn().' >= b.lo AND x.'.$this->demandColumn().' < b.hi
            GROUP BY b.band_no, b.band, b.lo, b.hi) g
            WHERE demand >= ? ORDER BY band_no',
            [$params['city'], $params['purpose'], ...$hood, ...$type, ...$bandBindings, ...$demandBindings, ...$hood, ...$type, self::minimumGroup()]];
    }
}
