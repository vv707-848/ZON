<?php

namespace App\Filament\Support;

use App\Support\Accounting\Exports;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «تصدير لدفترة»: the sales or the payments of a period, as a CSV file for the accounting software (Exports). */
final class AccountingExport
{
    public static function sales(): Action
    {
        return self::action('export_sales', 'تصدير المبيعات (CSV لدفترة)', 'الفواتير بتاريخ إصدارها', fn (?string $from, ?string $until) => Exports::sales($from, $until));
    }

    public static function payments(): Action
    {
        return self::action('export_payments', 'تصدير العمليات (CSV لدفترة)', 'المدفوعات والمستردّة بتاريخ دفعها', fn (?string $from, ?string $until) => Exports::payments($from, $until));
    }

    /** @param  callable(?string, ?string): StreamedResponse  $export */
    private static function action(string $name, string $label, string $what, callable $export): Action
    {
        return Action::make($name)->label($label)->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->modalDescription("{$what}، بتوقيت الرياض. لا تدخل المحاكاة ولا حسابات الاختبار ولا مشتريات تجربة المتجر. رسوم الترخيص الإعلاني معلّمة «تمرير للهيئة».")
            ->schema([DatePicker::make('from')->label('من')->required(), DatePicker::make('until')->label('إلى')->required()->afterOrEqual('from')])
            ->modalSubmitActionLabel('تنزيل')
            ->action(function (array $data) use ($name, $export) {
                Audit::log($name, 'تصدير محاسبي', properties: ['from' => $data['from'], 'until' => $data['until']]);

                return $export($data['from'], $data['until']);
            });
    }
}
