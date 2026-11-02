<?php

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\PropertyOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * Whose a record is. Someone else's record answers 404, as if it did not exist (backend doc §8 isolation rules:
 * a broker never learns that another broker's client exists).
 */
final class Ownership
{
    /** The column holding the owner, per model (customer_id unless the model says otherwise). */
    private const COLUMNS = [
        Project::class => 'developer_id',
        CrmClient::class => 'broker_id',
        PropertyOwner::class => 'broker_id',
        InventoryItem::class => 'broker_id',
    ];

    public static function owns(Model $record, Customer $customer): bool
    {
        return (int) $record->getAttribute(self::COLUMNS[$record::class] ?? 'customer_id') === $customer->id;
    }

    public static function assert(Model $record, Customer $customer): void
    {
        if (! self::owns($record, $customer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }
    }
}
