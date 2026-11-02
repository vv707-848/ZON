<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Support\Staff;
use App\Filament\Widgets\AiUsageByDay;
use App\Filament\Widgets\MismatchReasons;
use App\Filament\Widgets\TopSearchMisses;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «مراقبة الذكاء»: calls per day and outcome, the searches that found nothing (unmet demand), and why users said
 * «غير مطابق» — the three signals that say where the matching must improve.
 */
class AiMonitor extends Page
{
    protected static ?string $title = 'مراقبة الذكاء';

    protected static ?string $slug = 'ai-monitor';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Staff::can(Permission::ViewAi);
    }

    protected function getHeaderWidgets(): array
    {
        return [AiUsageByDay::class, TopSearchMisses::class, MismatchReasons::class];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
