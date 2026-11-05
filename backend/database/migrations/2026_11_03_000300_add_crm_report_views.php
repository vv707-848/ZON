<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The brokers' client book as the SuperAgent may see it: counts, places, kinds and budgets — never a client's name,
// phone, description or a note's words, and nothing of a review or test account (Sandbox). The notes' words are
// read only through «قراءة ملاحظات العملاء» (App\SuperAgent\NotesDigest): superadmin, masked, audited.
return new class extends Migration
{
    private const VIEWS = [
        'ai_v_crm_clients' => 'SELECT k.id, k.broker_id, k.status, k.purpose, k.city, k.neighborhoods, k.property_types, k.budget_min, k.budget_max, k.created_at,
                (SELECT COUNT(*) FROM client_notes n WHERE n.client_id = k.id) AS notes_count
            FROM crm_clients k JOIN customers b ON b.id = k.broker_id WHERE b.is_tester = 0 AND b.anonymized_at IS NULL',
        'ai_v_crm_notes' => 'SELECT n.client_id, n.category, n.created_at
            FROM client_notes n JOIN crm_clients k ON k.id = n.client_id JOIN customers b ON b.id = k.broker_id WHERE b.is_tester = 0 AND b.anonymized_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::VIEWS as $name => $select) {
            DB::statement("CREATE OR REPLACE VIEW {$name} AS {$select}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::VIEWS) as $name) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
        }
    }
};
