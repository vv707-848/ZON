<?php

namespace App\Filament\Resources\LegacyClaims;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\LegacyClaims\Pages\ListLegacyClaims;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\LegacyClaim;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «طلبات استعادة البيانات» (QA F01): what app 1.1 kept on a phone without an owner. A person decides, with a note;
 * nothing is attributed automatically and never because a name looks alike.
 */
class LegacyClaimResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = LegacyClaim::class;

    protected static ?string $modelLabel = 'طلب استعادة';

    protected static ?string $pluralModelLabel = 'طلبات الاستعادة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static ?int $navigationSort = 5;

    protected static function viewPermission(): Permission
    {
        return Permission::HandleLegacyClaims;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $decide = fn (string $status, string $label, string $color) => Action::make($status)->label($label)->color($color)
            ->visible(fn (LegacyClaim $r) => $r->status === 'pending' && Staff::can(Permission::HandleLegacyClaims))
            ->schema([Textarea::make('staff_note')->label('ملاحظة القرار')->required()->maxLength(1000)])
            ->action(function (LegacyClaim $record, array $data) use ($status) {
                $record->forceFill(['status' => $status, 'staff_note' => $data['staff_note'], 'handled_by' => Staff::user()->id, 'handled_at' => now()])->save();
                Audit::log("legacy_claim_{$status}", 'قرار طلب استعادة', $record->customer, ['claim' => $record->id]);
            });

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('customer.name')->label('الحساب'),
                TextColumn::make('items')->label('العناصر')->state(fn (LegacyClaim $r) => count($r->items['listings'] ?? []).' عرض · '.count($r->items['requests'] ?? []).' طلب'),
                TextColumn::make('status')->label('الحالة')->badge()->color(fn (string $state) => Columns::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => ['pending' => 'بانتظار المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض'][$state] ?? $state),
                TextColumn::make('staff_note')->label('الملاحظة')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(['pending' => 'بانتظار المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض'])])
            ->recordActions([$decide('accepted', 'قبول', 'success'), $decide('rejected', 'رفض', 'danger')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLegacyClaims::route('/')];
    }
}
