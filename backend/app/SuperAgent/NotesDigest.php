<?php

namespace App\SuperAgent;

use App\Ai\AiGateway;
use App\Ai\Masking\AiMasking;
use App\Enums\AiAgent;
use App\Enums\NoteCategory;
use App\Filament\Support\Audit;
use App\Models\ExecReport;
use App\Models\User;
use App\Support\Trace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * «قراءة ملاحظات العملاء» — the one place the brokers' notes are read as words, for the superadmin alone:
 *
 * - **Filters:** city, neighbourhood, kind, note category, period. Nothing of a review or test account.
 * - **Never one person:** fewer than MINIMUM notes are not read (a summary of three notes describes three
 *   clients). Each note is masked first (the client's name, phones, links), and only the summary comes back.
 * - **Map/reduce** (summarize_batch): batches of 500 notes, then one reduce of their summaries.
 * - **Audited** (`crm_notes_read`: the filters and how many notes — never a word of them) and archived like a
 *   report (`exec_reports`), within the SuperAgent's daily cap.
 */
final class NotesDigest
{
    public const MINIMUM = 20;

    private const BATCH = 500;

    private const MAX_NOTES = 5000;

    public function __construct(private readonly AiGateway $ai) {}

    /** @param array{city: string, neighborhood?: ?string, property_type?: ?string, category?: ?string, days?: ?int} $filters */
    public function read(User $staff, array $filters): ExecReport
    {
        if (! $staff->isSuperadmin()) {
            throw new RuntimeException('The notes are read by the superadmin alone.');
        }
        if (DailyCap::left($staff) < 1) {
            throw new RuntimeException('بلغت سقف التقارير اليومي.');
        }
        $filters = $this->checked($filters);
        $notes = $this->notes($filters);
        Audit::log('crm_notes_read', 'قراءة ملاحظات العملاء', null, ['filters' => $filters, 'notes' => count($notes)], $staff);
        if (count($notes) < self::MINIMUM) {
            return $this->archive($staff, $filters, count($notes), 'no_analysis', null, []);
        }

        $purpose = 'ملاحظات الوسطاء على عملائهم الباحثين عن عقار في '.$filters['city'].($filters['neighborhood'] ? ' — '.$filters['neighborhood'] : '')
            .': ماذا يريدون، وما يرفضون، وما يعيق الصفقة';
        $summaries = [];
        $themes = [];
        foreach (array_chunk($notes, self::BATCH) as $batch) {
            $out = $this->batch(['mode' => 'map', 'purpose' => $purpose, 'texts' => $batch], $staff);
            if ($out === null) {
                return $this->archive($staff, $filters, count($notes), 'failed', null, []);
            }
            $summaries[] = $out['summary'];
            $themes = $out['themes'];
        }
        if (count($summaries) > 1) {
            $out = $this->batch(['mode' => 'reduce', 'purpose' => $purpose, 'summaries' => $summaries], $staff);
            if ($out === null) {
                return $this->archive($staff, $filters, count($notes), 'failed', null, []);
            }
            [$summaries, $themes] = [[$out['summary']], $out['themes']];
        }

        return $this->archive($staff, $filters, count($notes), 'ok', $summaries[0], $themes);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{city: string, neighborhood: ?string, property_type: ?string, category: ?string, days: int}
     */
    private function checked(array $filters): array
    {
        $params = ['city' => Param::city(), 'neighborhood' => Param::neighborhood(), 'property_type' => Param::propertyType(), 'days' => Param::days(180)];
        $checked = [];
        foreach ($params as $name => $param) {
            $checked[$name] = $param->check($name, $filters[$name] ?? null);
        }
        $category = $filters['category'] ?? null;
        if ($category !== null && $category !== '' && NoteCategory::tryFrom((string) $category) === null) {
            throw new InvalidArgumentException('category: not in the list');
        }
        $checked['category'] = $category ?: null;

        /** @var array{city: string, neighborhood: ?string, property_type: ?string, category: ?string, days: int} */
        return $checked;
    }

    /**
     * The masked notes in the filters, newest first.
     *
     * @param  array{city: string, neighborhood: ?string, property_type: ?string, category: ?string, days: int}  $filters
     * @return list<string>
     */
    private function notes(array $filters): array
    {
        return DB::table('client_notes as n')->join('crm_clients as k', 'k.id', '=', 'n.client_id')->join('customers as b', 'b.id', '=', 'k.broker_id')
            ->where('b.is_tester', false)->whereNull('b.anonymized_at')
            ->where('k.city', $filters['city'])->where('n.created_at', '>=', now()->subDays($filters['days']))
            ->when($filters['neighborhood'], fn ($q, $v) => $q->whereJsonContains('k.neighborhoods', $v))
            ->when($filters['property_type'], fn ($q, $v) => $q->whereJsonContains('k.property_types', $v))
            ->when($filters['category'], fn ($q, $v) => $q->where('n.category', $v))
            ->orderByDesc('n.id')->limit(self::MAX_NOTES)->get(['n.body', 'k.name'])
            ->map(fn (object $row) => mb_substr(trim(AiMasking::text((string) $row->body, [(string) $row->name])), 0, 1000))
            ->filter()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return ?array{summary: string, themes: list<array{theme: string, share: string}>}
     */
    private function batch(array $input, User $staff): ?array
    {
        $result = $this->ai->call(AiAgent::SummarizeBatch, $input, check: function (array $out): ?array {
            $summary = trim((string) ($out['summary'] ?? ''));
            $themes = array_values(array_filter((array) ($out['themes'] ?? []), fn ($t) => is_array($t) && is_string($t['theme'] ?? null) && is_string($t['share'] ?? null)));

            return $summary === '' ? null : ['summary' => $summary, 'themes' => array_map(fn (array $t) => ['theme' => $t['theme'], 'share' => $t['share']], $themes)];
        }, staff: $staff, cache: false);

        /** @var ?array{summary: string, themes: list<array{theme: string, share: string}>} */
        return $result->usable() ? $result->output : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array{theme: string, share: string}>  $themes
     */
    private function archive(User $staff, array $filters, int $notes, string $status, ?string $summary, array $themes): ExecReport
    {
        return ExecReport::query()->create([
            'staff_id' => $staff->id,
            'question' => 'قراءة ملاحظات العملاء — '.$filters['city'],
            'report' => 'crm_notes_digest',
            'params' => $filters + ['notes' => $notes],
            'rows' => $themes,
            'row_count' => count($themes),
            'analysis' => $summary ?? ($status === 'no_analysis' ? 'أقل من '.self::MINIMUM.' ملاحظة في هذا النطاق: لا تُقرأ كي لا تصف عملاء بأعيانهم.' : null),
            'recommendations' => null,
            'status' => $status,
            'trace_id' => Trace::id(),
            'created_at' => now(),
        ]);
    }
}
