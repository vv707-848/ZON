<?php

namespace App\Filament\Pages;

use App\Arena\TestArena;
use App\Filament\NavigationGroup;
use App\Filament\Support\Staff;
use App\Models\ArenaBatch;
use App\Models\City;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * «ساحة الاختبار» (superadmin): sample listings, requests and wishes for a city, made and removed in batches
 * (App\Arena\TestArena). They live in the sandbox: a review account sees them, no real user does.
 *
 * @property-read Schema $form
 */
class TestArenaPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'ساحة الاختبار';

    protected static ?string $slug = 'test-arena';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Staff::isSuperadmin();
    }

    public function mount(): void
    {
        $this->form->fill(['listings' => 60, 'requests' => 20, 'wishes' => 20, 'rent' => 20]);
    }

    public function form(Schema $schema): Schema
    {
        $cities = array_keys(City::catalog());

        return $schema->statePath('data')->columns(3)->components([
            Select::make('city')->label('المدينة')->options(array_combine($cities, $cities))->required()->live(),
            Select::make('neighborhoods')->label('الأحياء')->multiple()->placeholder('كل أحياء المدينة')
                ->options(fn (Get $get) => array_combine($names = City::catalog()[(string) $get('city')] ?? [], $names)),
            TextInput::make('rent')->label('نسبة الإيجار %')->integer()->minValue(0)->maxValue(100)->required(),
            TextInput::make('listings')->label('عروض')->integer()->minValue(0)->maxValue(TestArena::MAX_LISTINGS)->required(),
            TextInput::make('requests')->label('طلبات')->integer()->minValue(0)->maxValue(TestArena::MAX_REQUESTS)->required(),
            TextInput::make('wishes')->label('رغبات باحثين')->integer()->minValue(0)->maxValue(TestArena::MAX_WISHES)->required(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('توليد دفعة')
                ->description('عروض وطلبات ورغبات بنصوص كما يكتبها الناس، لحسابات الدفعة نفسها. تعيش في بيئة المراجعة: يراها حساب المراجعة كما يراها المستخدم، ولا يراها مستخدم حقيقي ولا تدخل التقارير ولا الأرقام اليومية. تُفهرس كأي عرض، وتنتهي رخصها بعد 30 يوماً.')
                ->schema([
                    EmbeddedSchema::make('form'),
                    Actions::make([Action::make('generate')->label('توليد')->icon(Heroicon::OutlinedSparkles)->action(fn () => $this->generate())]),
                ]),
            Text::make('لتجربة البحث والمطابقة: ادخل التطبيق بحساب مراجعة (العملاء ← حساب مراجعة) في المدينة نفسها.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ArenaBatch::query()->with('creator')->latest())
            ->heading('الدفعات')
            ->emptyStateHeading('لا دفعات')
            ->columns([
                TextColumn::make('created_at')->label('أُنشئت')->dateTime('Y-m-d H:i'),
                TextColumn::make('city')->label('المدينة'),
                TextColumn::make('neighborhoods')->label('الأحياء')->formatStateUsing(fn ($state, ArenaBatch $record) => count($record->neighborhoods).' حي'),
                TextColumn::make('listings')->label('عروض'),
                TextColumn::make('requests')->label('طلبات'),
                TextColumn::make('wishes')->label('رغبات'),
                TextColumn::make('creator.name')->label('بواسطة')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('remove')->label('حذف الدفعة')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->modalDescription('تُحذف عروضها وطلباتها ورغباتها وحساباتها، وتخرج من الفهرس. لا تراجع.')
                    ->action(function (ArenaBatch $record) {
                        $removed = app(TestArena::class)->remove($record, Staff::user());
                        Notification::make()->title("حُذفت: {$removed['listings']} عرض · {$removed['requests']} طلب · {$removed['wishes']} رغبة")->success()->send();
                    }),
            ]);
    }

    private function generate(): void
    {
        $data = $this->form->getState();
        try {
            $batch = app(TestArena::class)->generate((string) $data['city'], array_values((array) ($data['neighborhoods'] ?? [])),
                (int) $data['listings'], (int) $data['requests'], (int) $data['wishes'], (int) $data['rent'], Staff::user());
            Notification::make()->title("وُلّدت: {$batch->listings} عرض · {$batch->requests} طلب · {$batch->wishes} رغبة في {$batch->city}")->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
