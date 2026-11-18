<?php

namespace App\Filament\Resources\MarketBriefs\Pages;

use App\Filament\Resources\MarketBriefs\MarketBriefResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\SuperAgent\BriefBuilder;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Runs the market reports of the brief now, freezes them, and opens the brief with its link. */
class CreateMarketBrief extends CreateRecord
{
    protected static string $resource = MarketBriefResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $brief = app(BriefBuilder::class)->make(
            ['city' => $data['city'], 'neighborhood' => $data['neighborhood'] ?? null, 'purpose' => $data['purpose'],
                'property_type' => $data['property_type'] ?? null, 'days' => (int) $data['days']],
            ['customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null, 'buyer_name' => trim((string) $data['buyer_name']),
                'analyst_note' => trim((string) ($data['analyst_note'] ?? '')),
                'price_halalas' => ($data['price'] ?? '') === '' || $data['price'] === null ? null : Money::fromRiyals((string) $data['price'])->halalas,
                'valid_days' => (int) $data['valid_days']],
            Staff::user(),
        );
        Audit::log('market_brief_created', 'إنشاء تقرير مطوّر', $brief, ['scope' => $brief->scope()]);

        return $brief;
    }

    protected function getRedirectUrl(): string
    {
        return MarketBriefResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
