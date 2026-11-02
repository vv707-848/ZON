<?php

namespace App\Filament\Pages;

use App\Actions\Admin\UpdateSettings;
use App\Filament\NavigationGroup;
use App\Filament\Support\Staff;
use App\Support\Money;
use App\Support\Permissions\Permission;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «إعدادات المنصة»: every business value of App\Support\Settings\Setting, by section — live at once, no release.
 * Plan prices are in «الباقات». Each saved change is in the audit log with its old value.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected static ?string $title = 'إعدادات المنصة';

    protected static ?string $slug = 'settings';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Staff::can(Permission::ManageSettings);
    }

    public function mount(): void
    {
        $values = [];
        foreach (Setting::cases() as $setting) {
            $value = Settings::get($setting);
            $values[$this->field($setting)] = match ($setting->type()) {
                'money' => (new Money((int) $value))->riyals(),
                'list' => implode("\n", (array) $value),
                default => $value,
            };
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (collect(Setting::cases())->groupBy(fn (Setting $s) => $s->group()) as $group => $settings) {
            $sections[] = Section::make($group)->columns(2)->schema($settings->map(fn (Setting $s) => $this->input($s))->all());
        }

        return $schema->statePath('data')->components($sections);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('حفظ')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = [];
        foreach (Setting::cases() as $setting) {
            $values[$setting->value] = $state[$this->field($setting)] ?? null;
        }
        $changed = app(UpdateSettings::class)($values);
        Notification::make()->title($changed === 0 ? 'لا تغيير' : "حُفظت {$changed} قيمة")->success()->send();
    }

    private function input(Setting $setting): TextInput|Toggle|Textarea
    {
        $name = $this->field($setting);

        return match ($setting->type()) {
            'bool' => Toggle::make($name)->label($setting->label()),
            'list' => Textarea::make($name)->label($setting->label())->rows(4),
            'text' => TextInput::make($name)->label($setting->label())->maxLength(200),
            'decimal' => TextInput::make($name)->label($setting->label())->numeric()->minValue(0)->required(),
            'money' => TextInput::make($name)->label($setting->label())->numeric()->minValue(0)->required(),
            default => TextInput::make($name)->label($setting->label())->integer()->minValue($setting->minimum())->required(),
        };
    }

    /** Dots would nest the form state: settings.days → settings__days. */
    private function field(Setting $setting): string
    {
        return str_replace('.', '__', $setting->value);
    }
}
