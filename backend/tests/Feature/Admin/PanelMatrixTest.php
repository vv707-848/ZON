<?php

namespace Tests\Feature\Admin;

use App\Support\Permissions\Permission;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnitEnum;

/**
 * docs/panel-matrix.md — «تشوف وتعدّل»: who sees each screen of the panel and who may add or change in it,
 * measured, not written: each screen asked as a member of staff holding one permission at a time, as «مشرف», and
 * as the superadmin. The test fails when the document no longer says what the code does; to write it again:
 *
 *     ZON_WRITE_DOCS=1 php artisan test --filter=PanelMatrixTest
 */
class PanelMatrixTest extends AdminTestCase
{
    private const DOC = __DIR__.'/../../../../docs/panel-matrix.md';

    public function test_the_matrix_says_what_the_code_does(): void
    {
        $matrix = $this->matrix();
        if (getenv('ZON_WRITE_DOCS') === '1') {
            file_put_contents(self::DOC, $matrix);
        }

        $this->assertSame($matrix, (string) @file_get_contents(self::DOC), 'docs/panel-matrix.md is out of date: ZON_WRITE_DOCS=1 php artisan test --filter=PanelMatrixTest');
    }

    private function matrix(): string
    {
        $screens = $this->screens();
        $permissions = array_values(array_filter(Permission::cases(), fn (Permission $p) => ! $p->isSuperadminOnly()));
        $alone = [];                                                // permission => screen => [see, change], holding it alone
        foreach ($permissions as $permission) {
            $this->signInStaff($permission);
            $alone[$permission->value] = array_map(fn (string $screen) => $this->ask($screen), $screens);
        }
        // A permission that changes a screen needs one that opens it too: each is asked again beside every opener.
        $openers = array_values(array_filter($permissions, fn (Permission $p) => in_array(true, array_column($alone[$p->value], 0), true)));
        $this->signInStaff(...$openers);
        $base = array_map(fn (string $screen) => $this->ask($screen)[1], $screens);
        $grants = [];                                               // screen => [see => [labels], change => [labels]]
        foreach ($permissions as $permission) {
            $this->signInStaff($permission, ...$openers);
            foreach ($screens as $key => $screen) {
                [$see, $change] = $alone[$permission->value][$key];
                $see && $grants[$key]['see'][] = $permission->label();
                ($change || (! $base[$key] && $this->ask($screen)[1])) && $grants[$key]['change'][] = $permission->label();
            }
        }
        $this->signInStaff(...Permission::supervisor());
        $supervisor = array_map(fn (string $screen) => $this->ask($screen), $screens);
        $this->signInSuperadmin();
        $superadmin = array_map(fn (string $screen) => $this->ask($screen), $screens);

        $rows = [];
        foreach ($screens as $key => $screen) {
            [$label, $group] = $this->describe($screen);
            $who = fn (string $kind, bool $superadminCan) => match (true) {
                isset($grants[$key][$kind]) => implode('<br>', $grants[$key][$kind]),
                $superadminCan => '**المدير العام وحده**',
                default => '—',
            };
            $page = is_subclass_of($screen, Page::class);
            $rows[] = sprintf('| %s | %s | %s | %s | %s |', $group, $label, $who('see', $superadmin[$key][0]), $page ? 'كمن يشوفها' : $who('change', $superadmin[$key][1]),
                match (true) {
                    $supervisor[$key][1] => 'يشوف ويعدّل', $supervisor[$key][0] => 'يشوف', default => '—'
                });
        }

        return implode("\n", [
            '# «تشوف وتعدّل»: شاشات لوحة الإدارة وصلاحياتها',
            '',
            'مولَّد من الكود، لا مكتوب باليد (`backend/tests/Feature/Admin/PanelMatrixTest.php`): كل شاشة سُئلت بموظف يحمل صلاحية واحدة في كل مرة، ثم بدور «مشرف»، ثم بالمدير العام. والاختبار يفشل إن صار الكود يقول غير ما هنا.',
            '',
            '- **يشوفها:** الصلاحية التي تفتح الشاشة. أيٌّ منها يكفي.',
            '- **يعدّل فيها:** الصلاحية التي تضيف أو تعدّل أو تحذف في الشاشة. «—» شاشة للقراءة فقط. والصفحات الخاصة (الإعدادات، الوكيل الأعلى، ساحة الاختبار…) من يفتحها يعمل فيها: «كمن يشوفها».',
            '- الأفعال داخل الشاشة (إيقاف حساب، رد على شكوى، استرداد…) تفحص صلاحيتها هي، وأسماؤها في «الأدوار» باللوحة.',
            '- المدير العام يرى كل شيء ويعدّله. وصلاحيات المحتوى الخاص والمال والإعدادات والسجل له وحده ولو أُعطيت لدور آخر.',
            '',
            '| المجموعة | الشاشة | يشوفها | يعدّل فيها | مشرف |',
            '|---|---|---|---|---|',
            ...$rows,
            '',
        ]);
    }

    /** @return array<string, class-string> every resource and page of the panel, in the menu's order */
    private function screens(): array
    {
        $panel = Filament::getPanel('admin');
        $screens = [];
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $group = $class::getNavigationGroup();
            $order = $group instanceof UnitEnum ? array_search($group, $group::cases(), true) : 99;
            $screens[sprintf('%02d-%03d-%s', $order, $class::getNavigationSort() ?? 999, $class)] = $class;
        }
        ksort($screens);

        return $screens;
    }

    /** @return array{0: bool, 1: bool} may see it · may add, change or delete in it */
    private function ask(string $screen): array
    {
        if (is_subclass_of($screen, Page::class)) {
            return [$screen::canAccess(), false];
        }
        assert(is_subclass_of($screen, Resource::class));
        $see = $screen::canViewAny();
        $model = $screen::getModel();
        $pages = array_keys($screen::getPages());
        try {
            $record = new $model;
            assert($record instanceof Model);
            $change = (in_array('create', $pages, true) && $screen::canCreate()) || (in_array('edit', $pages, true) && $screen::canEdit($record));
        } catch (Throwable) {
            $change = false;
        }

        return [$see, $see && $change];
    }

    /** @return array{0: string, 1: string} */
    private function describe(string $screen): array
    {
        $group = $screen::getNavigationGroup();
        $group = $group instanceof UnitEnum && method_exists($group, 'getLabel') ? (string) $group->getLabel() : (string) ($group ?? 'الرئيسية');

        return [(string) $screen::getNavigationLabel(), $group];
    }
}
