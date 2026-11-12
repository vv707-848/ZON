<?php

namespace App\Filament\Resources\KbDocuments;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\KbDocuments\Pages\CreateKbDocument;
use App\Filament\Resources\KbDocuments\Pages\EditKbDocument;
use App\Filament\Resources\KbDocuments\Pages\ListKbDocuments;
use App\Models\KbDocument;
use App\Support\Knowledge\DocumentText;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use UnitEnum;

/**
 * «مكتبة المعرفة»: what the chat «زون» may answer from. Each document has a source, an owner and a review date;
 * past that date it leaves retrieval until reviewed. Saving sends it to the index (chunked by the agents service).
 */
class KbDocumentResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = KbDocument::class;

    protected static ?string $slug = 'knowledge';

    protected static ?string $modelLabel = 'وثيقة';

    protected static ?string $pluralModelLabel = 'مكتبة المعرفة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 1;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageKnowledge;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('title')->label('العنوان')->required()->maxLength(200)->columnSpan(2),
                TextInput::make('category')->label('التصنيف')->required()->maxLength(40)->datalist(['packages', 'owners', 'requests', 'boosts', 'licences', 'listings', 'regulation']),
                Select::make('lang')->label('اللغة')->options(['ar' => 'العربية', 'en' => 'English'])->default('ar')->required(),
                TextInput::make('version')->label('النسخة')->required()->maxLength(20)->default('1'),
                DatePicker::make('effective_date')->label('سارية من')->required()->default(today()),
                TextInput::make('source')->label('المصدر (نظام، قرار، صفحة رسمية)')->required()->maxLength(200)->columnSpan(2),
                TextInput::make('review_owner')->label('مسؤول المراجعة')->required()->maxLength(120),
                DatePicker::make('next_review')->label('المراجعة القادمة')->required()->after('effective_date')->default(today()->addMonths(3)),
                Toggle::make('is_active')->label('فعّالة')->default(true),
                FileUpload::make('upload')->label('أو ارفع ملفاً (Word أو نص)')->columnSpanFull()
                    ->helperText('يُستخرج نصه في الخانة أدناه لتراجعه قبل الحفظ؛ الملف نفسه لا يُحفظ. PDF لا يُقبل: نصه العربي لا يُقرأ بدقة، فافتحه في Word واحفظه .docx.')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/markdown'])
                    ->maxSize(5120)->storeFiles(false)->dehydrated(false)->live()
                    ->afterStateUpdated(fn (mixed $state, Set $set, Get $get) => self::read($state, $set, $get)),
                Textarea::make('body')->label('النص')->required()->rows(14)->columnSpanFull(),
            ]),
        ]);
    }

    /** An uploaded file's text into «النص» (and its name into an empty «العنوان»); nothing of it is kept. */
    private static function read(mixed $file, Set $set, Get $get): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }
        try {
            $text = DocumentText::extract($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        if ($text === '') {
            Notification::make()->title('لا نص في الملف.')->warning()->send();

            return;
        }
        $set('body', $text);
        if (blank($get('title'))) {
            $set('title', mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 200));
        }
        Notification::make()->title('استُخرج النص: راجعه ثم احفظ.')->success()->send();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('next_review')
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->limit(40),
                TextColumn::make('category')->label('التصنيف')->badge(),
                TextColumn::make('version')->label('النسخة'),
                TextColumn::make('review_owner')->label('المسؤول'),
                TextColumn::make('next_review')->label('المراجعة')->date()
                    ->color(fn (KbDocument $d) => $d->next_review->isPast() ? 'danger' : null)
                    ->description(fn (KbDocument $d) => $d->next_review->isPast() ? 'متأخرة — خارج الاسترجاع' : null),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعّالة'),
                TernaryFilter::make('overdue')->label('متأخرة المراجعة')->queries(true: fn ($q) => $q->whereDate('next_review', '<', today()), false: fn ($q) => $q->whereDate('next_review', '>=', today())),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListKbDocuments::route('/'), 'create' => CreateKbDocument::route('/create'), 'edit' => EditKbDocument::route('/{record}/edit')];
    }
}
