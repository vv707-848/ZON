<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Support\Permissions\Permission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'المدفوعات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Staff::can(Permission::ViewPayments);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('public_id')->label('رقم الدفعة')->copyable(),
                Columns::enum('purpose', 'الغرض'),
                Columns::money('amount_halalas', 'المبلغ'),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('created_at')->label('التاريخ')->dateTime(),
            ]);
    }
}
