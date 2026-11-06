<?php

namespace App\Filament\Resources\Listings;

use App\Actions\Billing\ApplyBoost;
use App\Actions\Moderation\ModerateContent;
use App\Actions\Support\SupportDesk;
use App\Enums\BoostKind;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\Concerns\SearchesByIdentifier;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\AdLicense;
use App\Models\City;
use App\Models\Listing;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * «العروض»: every listing in every state, with its three texts (the advertiser's, the AI's suggestion, the indexed
 * one), its licence, photos and history. Staff hide, put back or reject — never edit the advertiser's content.
 */
class ListingResource extends Resource
{
    use AuthorizesByPermission, SearchesByIdentifier;

    protected static ?string $model = Listing::class;

    protected static ?string $modelLabel = 'عرض';

    protected static ?string $pluralModelLabel = 'العروض';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = 1;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewListings;
    }

    /**
     * Its number, or any advertising licence it carried.
     *
     * @param  Builder<Model>  $query
     */
    protected static function identifierMatches(Builder $query, string $search): void
    {
        if (preg_match('/^\d{1,12}$/', $search) === 1) {
            $query->orWhereKey((int) $search);
        }
        if (preg_match('/^[\dA-Za-z-]{6,40}$/', $search) === 1) {
            $query->orWhereIn('id', AdLicense::query()->select('listing_id')->whereNotNull('listing_id')->where('licence_number', $search));
        }
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Listing);

        return ['الرقم' => (string) $record->id, 'الحالة' => $record->status->label(), 'المكان' => "{$record->city} · {$record->neighborhood}", 'المعلن' => (string) $record->customer?->name];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العرض')->columns(4)->schema([
                TextEntry::make('title')->label('العنوان')->columnSpan(2),
                Columns::enumEntry('status', 'الحالة'),
                Columns::enumEntry('text_review', 'الصياغة'),
                Columns::enumEntry('purpose', 'الغرض'),
                Columns::enumEntry('property_type', 'النوع'),
                TextEntry::make('city')->label('المدينة'),
                TextEntry::make('neighborhood')->label('الحي'),
                TextEntry::make('price')->label('السعر')->numeric(),
                TextEntry::make('area_built')->label('مسطح البناء')->placeholder('—'),
                TextEntry::make('area_land')->label('مساحة الأرض')->placeholder('—'),
                TextEntry::make('bedrooms')->label('الغرف')->placeholder('—'),
                TextEntry::make('customer.name')->label('المعلن')->url(fn (Listing $r) => CustomerResource::getUrl('view', ['record' => $r->customer_id])),
                TextEntry::make('licence.licence_number')->label('الترخيص الإعلاني')->placeholder('—'),
                TextEntry::make('image_hash')->label('بصمة الغلاف')->placeholder('—')
                    ->helperText(fn (Listing $r) => $r->image_hash && ($n = Listing::query()->where('image_hash', $r->image_hash)->whereKeyNot($r->id)->count()) > 0 ? "الصورة نفسها في {$n} عرض آخر" : null),
                TextEntry::make('views_count')->label('المشاهدات'),
                TextEntry::make('featured_until')->label('مميز حتى')->dateTime()->placeholder('—'),
                TextEntry::make('urgent_until')->label('عاجل حتى')->dateTime()->placeholder('—'),
                TextEntry::make('published_at')->label('النشر')->dateTime()->placeholder('—'),
            ]),
            Section::make('النصوص')->schema([
                TextEntry::make('desc_raw')->label('نص المعلن (يُحفظ كما كُتب)'),
                TextEntry::make('desc_suggested')->label('مقترح الذكاء')->placeholder('—'),
                TextEntry::make('desc_clean')->label('النص المعتمد (المفهرس)')->placeholder('النص الأصلي'),
            ]),
            Section::make('الصور')->collapsed()->schema([
                ImageEntry::make('photos')->label('')->state(fn (Listing $r) => $r->media->map(fn ($m) => $m->url('card'))->all())->imageHeight(120),
            ]),
            Section::make('السجل')->collapsed()->schema([
                RepeatableEntry::make('history')->label('')->columns(4)->schema([
                    TextEntry::make('changed_at')->label('الوقت')->dateTime(),
                    TextEntry::make('field')->label('الحقل'),
                    TextEntry::make('change')->label('التغيير')->state(fn ($record) => "{$record->old_value} → {$record->new_value}"),
                    TextEntry::make('actor')->label('بواسطة'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->limit(35),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                Columns::enum('property_type', 'النوع'),
                TextColumn::make('neighborhood')->label('الحي')->description(fn (Listing $r) => $r->city),
                TextColumn::make('price')->label('السعر')->numeric()->sortable(),
                TextColumn::make('customer.name')->label('المعلن')->searchable()->limit(20),
                Columns::enum('text_review', 'الصياغة')->toggleable(),
                TextColumn::make('views_count')->label('المشاهدات')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')->label('النشر')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(ListingStatus::options()),
                SelectFilter::make('property_type')->label('النوع')->options(PropertyType::options()),
                SelectFilter::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                SelectFilter::make('text_review')->label('الصياغة')->options(TextReview::options()),
                SelectFilter::make('source')->label('المصدر')->options(['broker' => 'وسيط', 'owner' => 'مالك'])
                    ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($w, $role) => $w->whereHas('customer', fn ($c) => $c->where('role', $role)))),
                TernaryFilter::make('featured')->label('مميز الآن')->queries(true: fn ($q) => $q->where('featured_until', '>', now()), false: fn ($q) => $q->where(fn ($w) => $w->whereNull('featured_until')->orWhere('featured_until', '<=', now()))),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> «غير نشط» and back · message the advertiser · hide · put back · reject · grant a boost */
    public static function moderationActions(): array
    {
        $reason = [Textarea::make('reason')->label('السبب (يُسجَّل في التدقيق)')->required()->maxLength(500)];
        $can = fn () => Staff::can(Permission::ModerateListings);
        $move = fn (string $name, string $label, string $color, ListingStatus $to) => Action::make($name)->label($label)->color($color)
            ->visible(fn (Listing $record) => $can() && ! $record->trashed() && $record->status->canBecome($to))
            ->schema($reason)->requiresConfirmation()
            ->action(function (Listing $record, array $data) use ($to) {
                app(ModerateContent::class)->listing($record, $to, $data['reason']);
                Notification::make()->title('تم')->success()->send();
            });

        return [
            Action::make('deactivate')->label('تحويل إلى غير نشط')->color('warning')->icon('heroicon-o-pause-circle')
                ->visible(fn (Listing $record) => Staff::can(Permission::ActivateListings) && ! $record->trashed() && $record->status === ListingStatus::Published)
                ->schema([Textarea::make('reason')->label('السبب (يصل للمعلن رسالةً من «فريق زون»)')->required()->maxLength(1000)])
                ->requiresConfirmation()
                ->action(function (Listing $record, array $data) {
                    $ticket = app(ModerateContent::class)->deactivate($record, $data['reason']);
                    Notification::make()->title("صار غير نشط ووصلت الرسالة للمعلن · {$ticket->reference}")->success()->send();
                }),
            Action::make('activate')->label('تنشيط')->color('success')->icon('heroicon-o-play-circle')
                ->visible(fn (Listing $record) => Staff::can(Permission::ActivateListings) && ! $record->trashed() && $record->status === ListingStatus::Inactive)
                ->schema([Textarea::make('note')->label('رسالة للمعلن (اختيارية)')->maxLength(1000)])
                ->action(function (Listing $record, array $data) {
                    $status = app(ModerateContent::class)->activate($record, $data['note'] ?? null);
                    $status === ListingStatus::Published
                        ? Notification::make()->title('عاد العرض نشطاً')->success()->send()
                        : Notification::make()->title('رخصة العرض منتهية: صار «منتهي الترخيص» حتى يجددها المعلن')->warning()->send();
                }),
            Action::make('message_advertiser')->label('مراسلة المعلن')->icon('heroicon-o-envelope')
                ->visible(fn (Listing $record) => Staff::can(Permission::MessageCustomers) && $record->customer->anonymized_at === null)
                ->fillForm(fn (Listing $record) => ['subject' => "بخصوص عرضك «{$record->title}»"])
                ->schema([
                    TextInput::make('subject')->label('الموضوع')->required()->maxLength(160),
                    Textarea::make('body')->label('الرسالة (تصل باسم «فريق زون»)')->required()->maxLength(2000)->rows(5),
                ])
                ->action(function (Listing $record, array $data) {
                    $ticket = app(SupportDesk::class)->message($record->customer, Staff::user(), $data['subject'], $data['body'], $record);
                    Notification::make()->title("وصلت الرسالة · {$ticket->reference}")->success()->send();
                }),
            $move('hide', 'إخفاء', 'danger', ListingStatus::Hidden),
            $move('restore', 'إرجاع للنشر', 'success', ListingStatus::Published),
            $move('reject', 'رفض', 'danger', ListingStatus::Rejected),
            Action::make('grant_boost')->label('منح تمييز')->icon('heroicon-o-sparkles')
                ->visible(fn (Listing $record) => Staff::can(Permission::ManagePayments) && $record->status === ListingStatus::Published)
                ->schema([Select::make('kind')->label('النوع')->options([BoostKind::Featured->value => BoostKind::Featured->label(), BoostKind::UrgentListing->value => BoostKind::UrgentListing->label()])->required(),
                    ...$reason])
                ->action(function (Listing $record, array $data) {
                    app(ApplyBoost::class)($record->customer, $record, BoostKind::from($data['kind']), grantedBy: Staff::user());
                    Audit::log('boost_granted', 'منح تمييز', $record, ['kind' => $data['kind'], 'reason' => $data['reason']]);
                    Notification::make()->title('مُنح التمييز')->success()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListListings::route('/'), 'view' => ViewListing::route('/{record}')];
    }
}
