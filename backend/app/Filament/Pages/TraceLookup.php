<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Resources\IndexEvents\IndexEventResource;
use App\Filament\Support\Staff;
use App\Models\AiRequest;
use App\Models\IndexEvent;
use App\Support\Permissions\Permission;
use App\Support\Trace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * «تتبّع طلب»: paste the trace id a user reports (the app shows `error.trace_id`; every response
 * carries it in X-Request-Id — App\Support\Trace) and see what that one action left behind in the
 * three parties: AI calls, search-index signals and audit entries. Each count opens its screen
 * filtered by the id; the server logs carry the same id (docs/operations.md «تتبّع طلب»).
 *
 * @property-read Schema $form
 */
class TraceLookup extends Page
{
    protected static ?string $title = 'تتبّع طلب';

    protected static ?string $slug = 'trace';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 7;

    /** The id being looked up; kept in the URL (?id=…) so a lookup can be shared. */
    #[Url(as: 'id')]
    public ?string $trace = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Staff::can(Permission::ViewHealth);
    }

    public function mount(): void
    {
        $this->trace = Trace::isSafe($this->trace) ? $this->trace : null;
        $this->form->fill(['trace' => $this->trace]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('trace')->label('رقم التتبع')->required()->regex(Trace::PATTERN)
                ->helperText('يظهر للمستخدم مع رسالة الخطأ، ويصل في ترويسة X-Request-Id لكل رد.'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('lookup')
                ->footer([Actions::make([Action::make('lookup')->label('تتبّع')->submit('lookup')])]),
            Section::make('ما تركه هذا الطلب')
                ->visible(fn () => $this->trace !== null)
                ->schema(fn () => $this->trace === null ? [] : $this->findings($this->trace)),
        ]);
    }

    public function lookup(): void
    {
        $this->trace = trim((string) $this->form->getState()['trace']);
    }

    /** @return list<Actions|Text> */
    private function findings(string $id): array
    {
        $places = [
            'ai' => [AiRequestResource::class, 'نداءات الذكاء', AiRequest::query()->where('trace_id', $id)->count()],
            'index' => [IndexEventResource::class, 'إشارات الفهرسة', IndexEvent::query()->where('trace_id', $id)->count()],
            'audit' => [ActivityResource::class, 'سجل التدقيق', Activity::query()->where('properties->trace_id', $id)->count()],
        ];

        $links = [];
        foreach ($places as $name => [$resource, $label, $count]) {
            $links[] = Action::make("open_{$name}")
                ->label("{$label}: {$count}")
                ->color($count > 0 ? 'primary' : 'gray')
                ->disabled($count === 0 || ! $resource::canViewAny())
                ->url($resource::getUrl(parameters: ['filters' => ['trace' => ['trace' => $id]]]));
        }

        return [
            Actions::make($links),
            Text::make("سجلات الخادم بنفس الرقم: grep -r \"{$id}\" storage/logs/ — وخدمة الوكلاء: grep \"{$id}\" /var/log/zon/agents.log"),
        ];
    }
}
