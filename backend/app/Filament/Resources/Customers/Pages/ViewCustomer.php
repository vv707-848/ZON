<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Accounts\CancelDeletion;
use App\Actions\Admin\GrantSubscription;
use App\Actions\Admin\ModerateAccount;
use App\Actions\Support\SupportDesk;
use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\NafathStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Filament\Support\Staff;
use App\Jobs\CheckBrokerLicence;
use App\Models\Customer;
use App\Models\Package;
use App\Support\Permissions\Permission;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

/** One account, with what staff may do to it. */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        $customer = fn (): Customer => Record::as($this->getRecord(), Customer::class);
        $manage = fn () => Staff::can(Permission::ManageCustomers);
        $suspend = fn () => Staff::can(Permission::SuspendCustomers);

        return [
            EditAction::make(),
            Action::make('message')->label('مراسلة')->icon('heroicon-o-envelope')
                ->visible(fn () => Staff::can(Permission::MessageCustomers) && $customer()->anonymized_at === null)
                ->schema([
                    TextInput::make('subject')->label('الموضوع')->required()->maxLength(160),
                    Textarea::make('body')->label('الرسالة (تصل باسم «فريق زون»، ويرد عليها من التطبيق)')->required()->maxLength(2000)->rows(5),
                ])
                ->action(function (array $data) use ($customer) {
                    $ticket = app(SupportDesk::class)->message($customer(), Staff::user(), $data['subject'], $data['body']);
                    Notification::make()->title("وصلت الرسالة · {$ticket->reference}")->success()
                        ->actions([Action::make('open')->label('فتح المراسلة')->url(SupportTicketResource::getUrl('view', ['record' => $ticket]))])->send();
                }),
            Action::make('suspend')->label('إيقاف الحساب')->color('danger')->icon('heroicon-o-no-symbol')
                ->visible(fn () => $suspend() && $customer()->status !== AccountStatus::Suspended)
                ->schema([Textarea::make('reason')->label('السبب (يُسجَّل في التدقيق)')->required()->maxLength(255)])
                ->requiresConfirmation()
                ->action(function (array $data) use ($customer) {
                    app(ModerateAccount::class)->suspend($customer(), $data['reason']);
                    Notification::make()->title('أُوقف الحساب وخرج من كل الأجهزة')->success()->send();
                }),
            Action::make('activate')->label('فك الإيقاف')->color('success')->icon('heroicon-o-check-circle')
                ->visible(fn () => $suspend() && $customer()->status === AccountStatus::Suspended)
                ->requiresConfirmation()
                ->action(fn () => app(ModerateAccount::class)->activate($customer())),
            ActionGroup::make([
                Action::make('recheck_fal')->label('إعادة فحص رخصة فال')->icon('heroicon-o-arrow-path')
                    ->visible(fn () => $manage() && $customer()->role === CustomerRole::Broker && $customer()->fal_number !== null)
                    ->action(function () use ($customer) {
                        CheckBrokerLicence::dispatch($customer()->id);
                        Audit::log('fal_recheck', 'إعادة فحص رخصة فال', $customer());
                        Notification::make()->title('أُرسل الفحص إلى الهيئة')->success()->send();
                    }),
                Action::make('reset_nafath')->label('إعادة التحقق عبر نفاذ')->icon('heroicon-o-identification')
                    ->visible(fn () => $manage() && $customer()->nafath_status !== NafathStatus::None)
                    ->requiresConfirmation()
                    ->action(function () use ($customer) {
                        $customer()->forceFill(['nafath_status' => NafathStatus::None, 'nafath_verified_at' => null])->save();
                        Audit::log('nafath_reset', 'طلب إعادة التحقق عبر نفاذ', $customer());
                    }),
                Action::make('grant')->label('منح باقة')->icon('heroicon-o-gift')
                    ->visible(fn () => Staff::can(Permission::ManagePayments))
                    ->schema([
                        Select::make('package_id')->label('الباقة')->required()
                            ->options(fn () => Package::query()->where('audience', $customer()->role->value)->where('price_halalas', '>', 0)->pluck('name', 'id')),
                        TextInput::make('days')->label('عدد الأيام')->integer()->minValue(1)->maxValue(400)->required()->default(30),
                        Textarea::make('reason')->label('السبب')->required()->maxLength(255),
                    ])
                    ->action(function (array $data) use ($customer) {
                        app(GrantSubscription::class)($customer(), Package::query()->findOrFail($data['package_id']), (int) $data['days'], $data['reason']);
                        Notification::make()->title('مُنحت الباقة')->success()->send();
                    }),
                Action::make('cancel_deletion')->label('إلغاء طلب الحذف')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn () => $manage() && $customer()->pendingDeletion()->exists())
                    ->requiresConfirmation()
                    ->action(function () use ($customer) {
                        app(CancelDeletion::class)($customer()->pendingDeletion()->firstOrFail(), 'staff:'.Staff::user()->id);
                        Audit::log('deletion_cancelled', 'إلغاء حذف حساب', $customer());
                    }),
                Action::make('crm_content')->label('محتوى إدارة العملاء (حساس)')->icon('heroicon-o-lock-open')->color('warning')
                    ->visible(fn () => Staff::can(Permission::ViewCrmContent) && $customer()->role->canPublish())
                    ->modalSubmitAction(false)
                    ->mountUsing(fn () => Audit::log('crm_viewed', 'اطلاع على محتوى إدارة العملاء', $customer()))
                    ->modalContent(fn () => new HtmlString($this->crmContent($customer()))),
            ])->label('إجراءات')->button(),
        ];
    }

    /** Clients, owners and their last notes — shown only after the audited look. */
    private function crmContent(Customer $customer): string
    {
        $rows = [];
        foreach ($customer->clients()->with(['notes' => fn ($q) => $q->limit(5)])->limit(50)->get() as $client) {
            $notes = $client->notes->map(fn ($n) => e($n->category->label().': '.$n->body))->implode('<br>');
            $rows[] = '<tr><td>عميل</td><td>'.e($client->name).'</td><td>'.e($client->phone ? Phone::local($client->phone) : '—').'</td><td>'.$notes.'</td></tr>';
        }
        foreach ($customer->propertyOwners()->with(['notes' => fn ($q) => $q->limit(5)])->limit(50)->get() as $owner) {
            $notes = $owner->notes->map(fn ($n) => e($n->category->label().': '.$n->body))->implode('<br>');
            $rows[] = '<tr><td>مالك</td><td>'.e($owner->name).'</td><td>'.e(Phone::local($owner->phone)).'</td><td>'.$notes.'</td></tr>';
        }

        return $rows === [] ? '<p>لا يوجد محتوى.</p>'
            : '<table class="w-full text-sm"><thead><tr><th>النوع</th><th>الاسم</th><th>الجوال</th><th>آخر الملاحظات</th></tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
    }
}
