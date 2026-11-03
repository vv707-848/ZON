<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Admin\MakeReviewAccount;
use App\Enums\CustomerRole;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\Customer;
use App\Support\Phone;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review_account')->label('حساب مراجعة')->icon('heroicon-o-beaker')->color('gray')
                ->visible(fn () => Staff::isSuperadmin())
                ->modalHeading('حساب لمراجعة المتاجر أو للاختبار')
                ->modalDescription('يدخل باسم المستخدم وكلمة سر تظهر لك مرة واحدة، لتضعهما في App Store Connect وGoogle Play Console. الحساب معزول: يرى المنصة كما يراها الجميع، ولا يصل شيء مما يفعله لمستخدم حقيقي ولا للتقارير ولا لتدريب الذكاء. لا يُستثنى من أي شرط: الإعلان يحتاج ترخيصه، والوسيط يحتاج رخصة فال.')
                ->schema([
                    Select::make('role')->label('الدور')->required()->live()
                        ->options(collect(MakeReviewAccount::ROLES)->mapWithKeys(fn (CustomerRole $r) => [$r->value => $r->label()])->all())
                        ->default(CustomerRole::Seeker->value),
                    TextInput::make('name')->label('الاسم الظاهر')->required()->maxLength(120)->default('مراجعة المتجر'),
                    TextInput::make('username')->label('اسم المستخدم')->required()->regex('/^[A-Za-z0-9._-]{3,32}$/')
                        ->unique(Customer::class, 'username')->default('zon.review'),
                    TextInput::make('phone')->label('الجوال')->required()->extraInputAttributes(['inputmode' => 'tel', 'dir' => 'ltr'])
                        ->helperText('رقم سعودي تملكه، لا يستخدمه حساب آخر؛ يصلك عليه أي رمز تحقق يطلبه المراجع.')
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                            $phone = Phone::normalize(is_string($value) ? $value : null);
                            if ($phone === null) {
                                $fail('رقم جوال سعودي يبدأ بـ 05.');
                            } elseif (Customer::query()->where('phone', $phone)->exists()) {
                                $fail('هذا الرقم لحساب آخر.');
                            }
                        }),
                    Select::make('city')->label('المدينة')->required()
                        ->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                    TextInput::make('fal_number')->label('رخصة فال (تُفحص لدى الهيئة كأي وسيط)')->maxLength(20)
                        ->visible(fn (Get $get) => $get('role') === CustomerRole::Broker->value),
                ])
                ->action(function (array $data) {
                    abort_unless(Staff::isSuperadmin(), 403);
                    [$account, $password] = app(MakeReviewAccount::class)([...$data, 'phone' => Phone::normalize($data['phone'])], Staff::user());
                    Notification::make()->title('حساب المراجعة جاهز — انسخ كلمة السر الآن، لن تظهر مرة أخرى')->success()->persistent()
                        ->body("اسم المستخدم: {$account->username}\nكلمة السر: {$password}")
                        ->actions([Action::make('open')->label('فتح الحساب')->url(CustomerResource::getUrl('view', ['record' => $account]))])
                        ->send();
                }),
        ];
    }
}
