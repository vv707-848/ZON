<?php

use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A SuperAgent report is archived «running» first and finished by a queued job (App\Jobs\RunExecReport): the panel
// never waits on the model, since nginx ends a panel request at 30 s and one question makes two AI calls of up to
// 20 s each. A report keeps its parts (one per approved template it ran) instead of one template's columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exec_reports', function (Blueprint $table) {
            $table->string('mode', 10)->default('question')->after('staff_id');
            $table->json('parts')->nullable()->after('question');
        });
        DB::table('exec_reports')->orderBy('id')->each(function (object $row) {
            DB::table('exec_reports')->where('id', $row->id)->update([
                'mode' => $row->report === 'crm_notes_digest' ? 'notes' : 'question',
                'parts' => json_encode([[
                    'report' => $row->report,
                    'params' => json_decode($row->params, true),
                    'rows' => json_decode($row->rows, true),
                    'row_count' => $row->row_count,
                    'status' => 'ok',
                ]], JSON_UNESCAPED_UNICODE),
            ]);
        });
        Schema::table('exec_reports', function (Blueprint $table) {
            $table->dropColumn(['report', 'params', 'rows', 'row_count']);
        });
        Ddl::in('exec_reports', 'mode', ['question', 'explore', 'manual', 'notes']);
        Ddl::in('exec_reports', 'status', ['running', 'ok', 'no_analysis', 'failed']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `exec_reports` DROP CHECK `exec_reports_mode_check`');
        DB::statement('ALTER TABLE `exec_reports` DROP CHECK `exec_reports_status_check`');
        Schema::table('exec_reports', function (Blueprint $table) {
            $table->string('report', 60)->default('')->after('question');
            $table->json('params')->nullable()->after('report');
            $table->json('rows')->nullable()->after('params');
            $table->unsignedInteger('row_count')->default(0)->after('rows');
        });
        DB::table('exec_reports')->orderBy('id')->each(function (object $row) {
            $first = json_decode((string) $row->parts, true)[0] ?? ['report' => '—', 'params' => [], 'rows' => [], 'row_count' => 0];
            DB::table('exec_reports')->where('id', $row->id)->update([
                'report' => $first['report'],
                'params' => json_encode($first['params'], JSON_UNESCAPED_UNICODE),
                'rows' => json_encode($first['rows'], JSON_UNESCAPED_UNICODE),
                'row_count' => $first['row_count'],
                'status' => $row->status === 'running' ? 'failed' : $row->status,
            ]);
        });
        Schema::table('exec_reports', function (Blueprint $table) {
            $table->dropColumn(['mode', 'parts']);
        });
    }
};
