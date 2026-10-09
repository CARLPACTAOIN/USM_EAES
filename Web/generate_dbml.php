<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "Fetching database schema metadata...\n";

// 1. Fetch all tables
$tables = DB::select("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
      AND table_type = 'BASE TABLE'
    ORDER BY table_name
");

// 2. Fetch all columns
$columnsData = DB::select("
    SELECT 
        table_name,
        column_name,
        data_type,
        udt_name,
        character_maximum_length,
        numeric_precision,
        numeric_scale,
        is_nullable,
        column_default,
        ordinal_position
    FROM information_schema.columns
    WHERE table_schema = 'public'
    ORDER BY table_name, ordinal_position
");

$tableColumns = [];
foreach ($columnsData as $col) {
    $tableColumns[$col->table_name][] = $col;
}

// 3. Fetch Primary Keys
$pkData = DB::select("
    SELECT 
        tc.table_name,
        kcu.column_name,
        kcu.ordinal_position
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu 
        ON tc.constraint_name = kcu.constraint_name 
        AND tc.table_schema = kcu.table_schema
    WHERE tc.table_schema = 'public' 
      AND tc.constraint_type = 'PRIMARY KEY'
    ORDER BY tc.table_name, kcu.ordinal_position
");

$tablePKs = [];
foreach ($pkData as $pk) {
    $tablePKs[$pk->table_name][] = $pk->column_name;
}

// 4. Fetch Indexes
$indexData = DB::select("
    SELECT 
        t.relname AS table_name,
        i.relname AS index_name,
        ix.indisunique AS is_unique,
        ix.indisprimary AS is_primary,
        string_agg(a.attname, ',' ORDER BY k.ord) AS column_names
    FROM pg_index ix
    JOIN pg_class t ON t.oid = ix.indrelid
    JOIN pg_class i ON i.oid = ix.indexrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
    JOIN unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) ON true
    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
    WHERE n.nspname = 'public'
    GROUP BY t.relname, i.relname, ix.indisunique, ix.indisprimary
    ORDER BY t.relname, i.relname
");

$tableIndexes = [];
foreach ($indexData as $idx) {
    if ($idx->is_primary) {
        continue; // Handled as PK
    }
    $tableIndexes[$idx->table_name][] = $idx;
}

// 5. Fetch Foreign Keys
$fkData = DB::select("
    SELECT 
        c.conname AS constraint_name,
        src_tbl.relname AS source_table,
        dst_tbl.relname AS target_table,
        c.confdeltype AS delete_rule,
        c.confupdtype AS update_rule,
        (
            SELECT string_agg(attname, ',' ORDER BY k.ord)
            FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
        ) AS source_columns,
        (
            SELECT string_agg(attname, ',' ORDER BY k.ord)
            FROM unnest(c.confkey) WITH ORDINALITY AS k(attnum, ord)
            JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum
        ) AS target_columns
    FROM pg_constraint c
    JOIN pg_class src_tbl ON src_tbl.oid = c.conrelid
    JOIN pg_class dst_tbl ON dst_tbl.oid = c.confrelid
    JOIN pg_namespace n ON n.oid = src_tbl.relnamespace
    WHERE c.contype = 'f' AND n.nspname = 'public'
    ORDER BY src_tbl.relname, c.conname
");

// Table notes mapping
$tableNotes = [
    'users' => 'Unified institutional user accounts supporting Google OAuth (@usm.edu.ph), student profiles, and administrative staff credentials.',
    'roles' => 'Spatie RBAC roles representing administrative and institutional privileges.',
    'permissions' => 'Spatie RBAC granular permissions for fine-grained authorization.',
    'model_has_roles' => 'Spatie RBAC mapping assigning roles to user models.',
    'model_has_permissions' => 'Spatie RBAC mapping direct permissions to models.',
    'role_has_permissions' => 'Spatie RBAC mapping permissions to roles.',
    'personal_access_tokens' => 'Laravel Sanctum API tokens used for mobile scanner authentication and web API clients.',
    'password_reset_tokens' => 'Standard Laravel password reset authentication tokens.',
    'admin_assignments' => 'Permission-facing Authority Projections mapping users to roles within exact School Year, Department, College, or Organization scopes.',
    'pending_student_links' => 'Staging records for student identity verification and Google profile linkage.',
    'osa_authority_overrides' => 'OSA institutional audit authority overrides enabling cross-scope governance interventions.',
    'universities' => 'Top-level educational institution entity (University of Southern Mindanao).',
    'colleges' => 'Academic colleges under the University (e.g., College of Science and Data Science).',
    'departments' => 'Academic departments within colleges providing organizational and curriculum scoping.',
    'programs' => 'Degree academic programs (e.g., BS Information Systems) linked to departments.',
    'school_years' => 'Academic school years and semestral terms establishing temporal governance boundaries.',
    'organizations' => 'Student organizations, societies, and councils recognized by the university.',
    'organization_programs' => 'Association between organizations and academic programs.',
    'organization_memberships' => 'Student memberships within recognized student organizations.',
    'organization_officer_rosters' => 'Verified annual officer rosters serving as source of truth for student officer authority.',
    'organization_officers' => 'Individual officer positions and assigned students within an approved roster.',
    'officer_change_requests' => 'Formal requests and approval trails for mid-term officer appointments or resignations.',
    'employee_profiles' => 'Faculty and staff institutional profiles.',
    'employee_roster_entries' => 'Assignments of faculty/employees to administrative and advising roles.',
    'venues' => 'University campus venues and facilities bookable for campus events.',
    'canonical_ppa_packets' => 'Digitized Program, Project, and Activity (PPA) proposal packets with full metadata and governance payloads.',
    'canonical_ppa_attachments' => 'Attached budgetary, administrative, and supporting documents for PPA proposals.',
    'proposal_process_templates' => 'Configurable workflow templates governing approval steps for event proposals.',
    'proposal_process_template_versions' => 'Immutable version snapshots of proposal approval workflow templates.',
    'proposal_process_steps' => 'Sequential and parallel approval stages (OSA, Dean, Adviser, etc.) within a workflow template.',
    'proposal_process_step_dependencies' => 'Prerequisite step dependencies controlling workflow stage transitions.',
    'proposal_template_applicability_rules' => 'Scoping rules determining which template applies based on organization type, venue, or budget.',
    'proposal_workflow_runs' => 'Active workflow execution instances tracking an event proposal through its approval pipeline.',
    'proposal_workflow_tasks' => 'Individual actionable approval tasks assigned to specific administrative authorities.',
    'workflow_task_assignment_history' => 'Audit history of task assignments, reassignments, and delegations.',
    'approval_trails' => 'Append-only immutable audit trail recording every approval, rejection, comment, and endorsement action.',
    'event_permit_versions' => 'Generated immutable event permit version documents and evidence snapshots upon proposal approval.',
    'permit_copy_owners' => 'Designated stakeholders holding official copies of approved event permits.',
    'events' => 'Master event records detailing dates, venues, organizers, participation scopes, and lifecycle status.',
    'event_days' => 'Multi-day breakdown of event schedules and operational dates.',
    'event_day_sessions' => 'Morning, afternoon, or evening attendance scanning windows within an event day.',
    'event_participation_scopes' => 'Audience eligibility rules defining allowed colleges, departments, programs, and year levels.',
    'event_scanner_assignments' => 'Delegated scanner permissions authorizing designated student officers to scan attendance for specific sessions.',
    'scanner_sessions' => 'Active mobile scanning sessions tracking device state, battery, and sync status.',
    'raw_scans' => 'High-throughput append-only raw scan ingestion queue supporting offline sync and conflict resolution.',
    'attendance_records' => 'Resolved student attendance records with check-in/out timestamps, validation states, and evaluation gate status.',
    'evaluation_form_versions' => 'Customizable Likert-scale and qualitative question templates versioned per event.',
    'evaluations' => 'Student event evaluation responses unlocking official attendance validation and sentiment analytics.',
    'audit_logs' => 'System-wide administrative security and activity audit logs.',
    'notifications' => 'User notifications for proposal status changes, scanning assignments, and event announcements.',
    'sessions' => 'Laravel database session storage.',
    'cache' => 'Laravel persistent key-value cache store.',
    'cache_locks' => 'Atomic lock store for distributed concurrency control.',
    'jobs' => 'Database queue for asynchronous background jobs and attendance sync resolution.',
    'job_batches' => 'Batched queue job execution tracking.',
    'failed_jobs' => 'Logging of failed background queue jobs for diagnostics and replay.',
    'queue_outbox_entries' => 'Transactional outbox pattern entries ensuring reliable event publishing.',
    'migrations' => 'Laravel database schema migration registry.',
];

function formatDataType($col)
{
    $dt = strtolower($col->data_type);
    $udt = strtolower($col->udt_name);

    if ($dt === 'double precision') {
        return 'double';
    }
    if ($dt === 'character varying') {
        return $col->character_maximum_length ? "varchar({$col->character_maximum_length})" : 'varchar';
    }
    if ($dt === 'character') {
        return $col->character_maximum_length ? "char({$col->character_maximum_length})" : 'char';
    }
    if ($dt === 'numeric') {
        if ($col->numeric_precision) {
            return $col->numeric_scale ? "numeric({$col->numeric_precision},{$col->numeric_scale})" : "numeric({$col->numeric_precision})";
        }

        return 'numeric';
    }
    if (str_starts_with($dt, 'timestamp')) {
        return str_contains($dt, 'with time zone') ? 'timestamptz' : 'timestamp';
    }
    if (str_starts_with($dt, 'time')) {
        return str_contains($dt, 'with time zone') ? 'timetz' : 'time';
    }
    if ($dt === 'user-defined') {
        return $udt;
    }
    if ($dt === 'array') {
        return ltrim($udt, '_').'[]';
    }

    return $dt;
}

function formatDefaultValue($default, $dataType)
{
    if ($default === null) {
        return null;
    }
    $d = trim($default);

    // Check nextval (serial)
    if (preg_match('/nextval\(/i', $d)) {
        return null; // Handled as increment or sequence
    }

    // CURRENT_TIMESTAMP / now()
    if (preg_match('/^(current_timestamp|now\(\))/i', $d)) {
        return '`now()`';
    }
    if (preg_match('/gen_random_uuid\(\)/i', $d)) {
        return '`gen_random_uuid()`';
    }
    if ($d === 'true' || $d === 'false') {
        return $d;
    }
    if (is_numeric($d)) {
        return $d;
    }
    // String cast: 'value'::character varying or 'value'::text
    if (preg_match("/^'((?:[^']|'')*)'::/s", $d, $matches)) {
        $val = str_replace("''", "'", $matches[1]);

        return "'".addcslashes($val, "'\\")."'";
    }
    // Raw string literal: 'value'
    if (preg_match("/^'((?:[^']|'')*)'$/s", $d, $matches)) {
        $val = str_replace("''", "'", $matches[1]);

        return "'".addcslashes($val, "'\\")."'";
    }

    // Expression
    return '`'.str_replace('`', '', $d).'`';
}

$output = [];
$output[] = '// =============================================================================';
$output[] = '// USM Event Attendance & Evaluation System (EAES) — Database Schema';
$output[] = '// Authoritative specification matching PRD_V1.md §7 & PostgreSQL 16 schema';
$output[] = '// Generated: '.date('Y-m-d H:i:s T');
$output[] = '// =============================================================================';
$output[] = '';
$output[] = 'Project usm_eaes {';
$output[] = "  database_type: 'PostgreSQL'";
$output[] = "  Note: '''";
$output[] = '    # USM Event Attendance & Evaluation System (EAES)';
$output[] = '    University of Southern Mindanao — Capstone Database Specification';
$output[] = '    - Multi-tenant hierarchy: University -> College -> Department -> Program';
$output[] = '    - Proposal workflow & digital permit evidence';
$output[] = '    - High-throughput QR code scan capture & offline attendance sync';
$output[] = '    - Mandatory evaluation gate unlocking attendance validation';
$output[] = "  '''";
$output[] = '}';
$output[] = '';

$existingTables = array_column($tables, 'table_name');

foreach ($tables as $tRow) {
    $tbl = $tRow->table_name;
    $cols = $tableColumns[$tbl] ?? [];
    $pks = $tablePKs[$tbl] ?? [];
    $indexes = $tableIndexes[$tbl] ?? [];
    $isSinglePK = count($pks) === 1;

    $output[] = "Table \"{$tbl}\" {";

    foreach ($cols as $col) {
        $cName = $col->column_name;
        $type = formatDataType($col);
        $settings = [];

        $isPK = in_array($cName, $pks);
        if ($isPK && $isSinglePK) {
            $settings[] = 'pk';
        }

        // Auto increment detection
        if ($col->column_default && preg_match('/nextval\(/i', $col->column_default)) {
            $settings[] = 'increment';
        }

        if ($col->is_nullable === 'NO' && ! ($isPK && $isSinglePK)) {
            $settings[] = 'not null';
        }

        $def = formatDefaultValue($col->column_default, $type);
        if ($def !== null && ! in_array('increment', $settings)) {
            $settings[] = "default: {$def}";
        }

        $settingsStr = count($settings) > 0 ? ' ['.implode(', ', $settings).']' : '';
        $output[] = "  \"{$cName}\" {$type}{$settingsStr}";
    }

    // Table Note
    if (isset($tableNotes[$tbl])) {
        $noteEscaped = addcslashes($tableNotes[$tbl], "'\\");
        $output[] = '';
        $output[] = "  Note: '{$noteEscaped}'";
    }

    // Indexes block if composite PK or unique / secondary indexes exist
    $hasCompositePK = count($pks) > 1;
    $hasIndexes = count($indexes) > 0;

    if ($hasCompositePK || $hasIndexes) {
        $output[] = '';
        $output[] = '  indexes {';
        if ($hasCompositePK) {
            $pkCols = implode(', ', array_map(fn ($c) => "\"{$c}\"", $pks));
            $output[] = "    ({$pkCols}) [pk]";
        }
        foreach ($indexes as $idx) {
            $colsList = explode(',', $idx->column_names);
            $colsFormatted = count($colsList) > 1
                ? '('.implode(', ', array_map(fn ($c) => "\"{$c}\"", $colsList)).')'
                : "\"{$colsList[0]}\"";

            $idxSettings = [];
            if ($idx->is_unique) {
                $idxSettings[] = 'unique';
            }
            if ($idx->index_name) {
                $idxSettings[] = "name: \"{$idx->index_name}\"";
            }
            $idxSettingsStr = count($idxSettings) > 0 ? ' ['.implode(', ', $idxSettings).']' : '';
            $output[] = "    {$colsFormatted}{$idxSettingsStr}";
        }
        $output[] = '  }';
    }

    $output[] = '}';
    $output[] = '';
}

// 6. Foreign Key References
$output[] = '// =============================================================================';
$output[] = '// FOREIGN KEY RELATIONSHIPS';
$output[] = '// =============================================================================';
$output[] = '';

$delActionMap = [
    'c' => 'cascade',
    'r' => 'restrict',
    'n' => 'set null',
    'd' => 'set default',
    'a' => 'no action',
];

$seenRefs = [];
foreach ($fkData as $fk) {
    if (! in_array($fk->source_table, $existingTables) || ! in_array($fk->target_table, $existingTables)) {
        continue;
    }

    $srcCols = explode(',', $fk->source_columns);
    $dstCols = explode(',', $fk->target_columns);

    if (count($srcCols) !== count($dstCols)) {
        continue;
    }

    $refKey = "{$fk->source_table}.{$fk->source_columns}->{$fk->target_table}.{$fk->target_columns}";
    if (isset($seenRefs[$refKey])) {
        continue;
    }
    $seenRefs[$refKey] = true;

    $settings = [];
    if (isset($delActionMap[$fk->delete_rule]) && $delActionMap[$fk->delete_rule] !== 'no action') {
        $settings[] = 'delete: '.$delActionMap[$fk->delete_rule];
    }
    if (isset($delActionMap[$fk->update_rule]) && $delActionMap[$fk->update_rule] !== 'no action') {
        $settings[] = 'update: '.$delActionMap[$fk->update_rule];
    }
    $settingsStr = count($settings) > 0 ? ' ['.implode(', ', $settings).']' : '';

    if (count($srcCols) === 1) {
        $output[] = "Ref: \"{$fk->source_table}\".\"{$srcCols[0]}\" > \"{$fk->target_table}\".\"{$dstCols[0]}\"{$settingsStr}";
    } else {
        $srcColsFormatted = '('.implode(', ', array_map(fn ($c) => "\"{$fk->source_table}\".\"{$c}\"", $srcCols)).')';
        $dstColsFormatted = '('.implode(', ', array_map(fn ($c) => "\"{$fk->target_table}\".\"{$c}\"", $dstCols)).')';
        $output[] = "Ref: {$srcColsFormatted} > {$dstColsFormatted}{$settingsStr}";
    }
}
$output[] = '';

// 7. Table Groups
$output[] = '// =============================================================================';
$output[] = '// TABLE GROUPS (Logical Module Clustering)';
$output[] = '// =============================================================================';
$output[] = '';

$groups = [
    'Identity_And_Access' => [
        'users', 'password_reset_tokens', 'personal_access_tokens',
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
        'role_has_permissions', 'admin_assignments', 'pending_student_links',
        'osa_authority_overrides',
    ],
    'Academic_Hierarchy_And_Organizations' => [
        'universities', 'colleges', 'departments', 'programs', 'school_years',
        'organizations', 'organization_programs', 'organization_memberships',
        'organization_officer_rosters', 'organization_officers',
        'officer_change_requests', 'employee_profiles', 'employee_roster_entries',
        'venues',
    ],
    'Proposal_Workflow_And_Permits' => [
        'canonical_ppa_packets', 'canonical_ppa_attachments',
        'proposal_process_templates', 'proposal_process_template_versions',
        'proposal_process_steps', 'proposal_process_step_dependencies',
        'proposal_template_applicability_rules', 'proposal_workflow_runs',
        'proposal_workflow_tasks', 'workflow_task_assignment_history',
        'approval_trails', 'event_permit_versions', 'permit_copy_owners',
    ],
    'Events_And_Attendance' => [
        'events', 'event_days', 'event_day_sessions', 'event_participation_scopes',
        'event_scanner_assignments', 'scanner_sessions', 'raw_scans',
        'attendance_records',
    ],
    'Evaluations' => [
        'evaluation_form_versions', 'evaluations',
    ],
    'System_And_Queues' => [
        'audit_logs', 'notifications', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'queue_outbox_entries', 'migrations',
    ],
];

foreach ($groups as $groupName => $groupTables) {
    $matched = array_intersect($groupTables, $existingTables);
    if (empty($matched)) {
        continue;
    }
    $output[] = "TableGroup {$groupName} {";
    foreach ($matched as $gt) {
        $output[] = "  \"{$gt}\"";
    }
    $output[] = '}';
    $output[] = '';
}

$dbmlContent = implode("\n", $output);
$targetFile = dirname(__DIR__).'/docs/eaes.dbml';
file_put_contents($targetFile, $dbmlContent);

echo "Successfully generated DBML file at: {$targetFile}\n";
echo 'Total tables: '.count($tables)."\n";
echo 'Total foreign key references: '.count($seenRefs)."\n";
echo 'File size: '.strlen($dbmlContent)." bytes\n";
