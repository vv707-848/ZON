<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Support\Permissions\Permission;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/** The account's sessions, one per phone: a lost or stolen phone is signed out here, and stops getting pushes. */
class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'الأجهزة والجلسات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Staff::can(Permission::ViewCustomers);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('last_used_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الجهاز'),
                TextColumn::make('created_at')->label('الدخول')->dateTime(),
                TextColumn::make('last_used_at')->label('آخر استخدام')->dateTime()->placeholder('لم يُستخدم')->since(),
            ])
            ->recordActions([
                Action::make('end')->label('إنهاء الجلسة')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')
                    ->visible(fn () => Staff::can(Permission::ManageCustomers))
                    ->requiresConfirmation()->modalDescription('يخرج هذا الجهاز من الحساب وتتوقف إشعاراته. يدخل صاحبه من جديد إن أراد.')
                    ->action(function (PersonalAccessToken $record) {
                        Audit::log('session_ended', 'إنهاء جلسة جهاز من اللوحة', $this->getOwnerRecord(), ['device' => $record->name]);
                        $record->delete();
                    }),
            ]);
    }
}
