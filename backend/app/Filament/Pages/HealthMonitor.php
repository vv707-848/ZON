<?php

namespace App\Filament\Pages;

use App\Enums\HealthStatus;
use App\Filament\NavigationGroup;
use App\Filament\Support\Staff;
use App\Support\Health\CheckResult;
use App\Support\Health\SystemHealth;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «صحة النظام»: the checks of `php artisan zon:doctor` (App\Support\Health\SystemHealth), each
 * with its outcome and, when it is not ok, where to look first. Checked again every minute.
 */
class HealthMonitor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'صحة النظام';

    protected static ?string $slug = 'health';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 6;

    /** @var array<string, array{key: string, label: string, status: string, detail: string, hint: ?string}>|null this request's results */
    private ?array $results = null;

    public static function canAccess(): bool
    {
        return Staff::can(Permission::ViewHealth);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->results())
            ->paginated(false)
            ->poll('60s')
            ->columns([
                TextColumn::make('label')->label('الفحص')->weight(FontWeight::Bold),
                TextColumn::make('status')->label('النتيجة')->badge()
                    ->formatStateUsing(fn (string $state) => HealthStatus::from($state)->label())
                    ->color(fn (string $state) => HealthStatus::from($state)->color()),
                TextColumn::make('detail')->label('التفاصيل')->wrap(),
                TextColumn::make('hint')->label('أين تبحث')->wrap()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('recheck')->label('إعادة الفحص')->icon(Heroicon::OutlinedArrowPath)->action(fn () => $this->results = null),
            ]);
    }

    /** @return array<string, array{key: string, label: string, status: string, detail: string, hint: ?string}> */
    private function results(): array
    {
        return $this->results ??= collect(app(SystemHealth::class)->run())
            ->mapWithKeys(fn (CheckResult $result) => [$result->key => $result->toArray()])
            ->all();
    }
}
