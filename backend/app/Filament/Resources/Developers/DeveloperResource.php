<?php

namespace App\Filament\Resources\Developers;

use App\Actions\Admin\ActivateDeveloper;
use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Developers\Pages\CreateDeveloper;
use App\Filament\Resources\Developers\Pages\ListDevelopers;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\Customer;
use App\Support\Permissions\Permission;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * «المطوّرون»: developer accounts are created here by staff (or sign up in the app and wait), and become active
 * with their marketing contract — the commission per unit sold comes from it.
 */
class DeveloperResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'developers';

    protected static ?string $modelLabel = 'مطوّر';

    protected static ?string $pluralModelLabel = 'المطوّرون';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 2;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewCustomers;
    }

    protected static function managePermission(): Permission
    {
        return Permission::CreateDevelopers;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', CustomerRole::Developer)->whereNull('anonymized_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('حساب المطوّر')->description('يدخل المطوّر التطبيق باسم المستخدم وكلمة السر هذه؛ أبلغه بها بقناة آمنة.')->columns(2)->schema([
                TextInput::make('name')->label('اسم الشركة')->required()->maxLength(120),
                TextInput::make('username')->label('اسم المستخدم')->required()->regex('/^[A-Za-z0-9._-]{3,32}$/')->unique('customers', 'username'),
                TextInput::make('phone')->label('الجوال')->required()->tel()
                    ->dehydrateStateUsing(fn (string $state) => Phone::normalize($state) ?? $state)
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        $phone = Phone::normalize((string) $value);
                        if ($phone === null) {
                            $fail(__('validation.phone'));
                        } elseif (Customer::query()->where('phone', $phone)->exists()) {
                            $fail(__('validation.unique', ['attribute' => 'الجوال']));
                        }
                    }),
                Select::make('city')->label('المدينة')->required()->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                TextInput::make('password')->label('كلمة السر الأولى')->password()->revealable()->required()->rule(Password::min(10)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['projects', 'marketingContracts']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('الشركة')->searchable(),
                TextColumn::make('username')->label('اسم المستخدم')->searchable(),
                Columns::phone(),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('marketing_contracts_count')->label('العقود'),
                TextColumn::make('projects_count')->label('المشاريع'),
                TextColumn::make('created_at')->label('الإنشاء')->date(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(AccountStatus::options())])
            ->recordUrl(fn (Customer $record) => CustomerResource::getUrl('view', ['record' => $record]))
            ->recordActions([self::activateAction()]);
    }

    /** Records the marketing contract and activates the account (a pending developer is told). */
    public static function activateAction(): Action
    {
        return Action::make('contract')->label('تسجيل عقد تسويق')->icon('heroicon-o-document-check')->color('success')
            ->visible(fn () => Staff::can(Permission::ActivateDevelopers))
            ->schema(fn (Customer $record) => [
                TextInput::make('number')->label('رقم العقد')->required()->maxLength(40)->rule(Rule::unique('marketing_contracts', 'number')),
                DatePicker::make('signed_at')->label('تاريخ التوقيع')->required()->default(today()),
                DatePicker::make('ends_at')->label('ينتهي')->after('signed_at'),
                TextInput::make('commission_percent')->label('العمولة لكل وحدة مباعة (٪)')->numeric()->minValue(0)->maxValue(100)->required(),
                Select::make('project_id')->label('مشروع محدد (اختياري)')->options(fn () => $record->projects()->pluck('title', 'id')),
                FileUpload::make('document_path')->label('نسخة العقد (PDF)')->disk((string) config('zon.media_disk'))->directory('contracts')->acceptedFileTypes(['application/pdf'])->maxSize(10240),
                Textarea::make('notes')->label('ملاحظات')->maxLength(1000),
            ])
            ->action(function (Customer $record, array $data) {
                app(ActivateDeveloper::class)($record, $data);
                Notification::make()->title('سُجّل العقد وفُعّل الحساب')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListDevelopers::route('/'), 'create' => CreateDeveloper::route('/create')];
    }
}
