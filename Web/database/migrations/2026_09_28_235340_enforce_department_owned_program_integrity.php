<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoOrphanPrograms();

        match (DB::getDriverName()) {
            'pgsql' => $this->enforcePostgresIntegrity(),
            'sqlite' => $this->enforceSqliteIntegrity(),
            default => $this->enforceGenericIntegrity(),
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->relaxPostgresIntegrity(),
            'sqlite' => $this->relaxSqliteIntegrity(),
            default => $this->relaxGenericIntegrity(),
        };
    }

    private function assertNoOrphanPrograms(): void
    {
        $orphanCount = DB::table('programs')->whereNull('department_id')->count();

        if ($orphanCount > 0) {
            throw new RuntimeException("Cannot require programs.department_id while {$orphanCount} Program record(s) are orphaned.");
        }
    }

    private function enforcePostgresIntegrity(): void
    {
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_code_unique');
        DB::statement('DROP INDEX IF EXISTS programs_code_unique');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_department_id_foreign');
        DB::statement('ALTER TABLE programs ALTER COLUMN department_id SET NOT NULL');

        Schema::table('programs', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->unique(['department_id', 'code'], 'programs_department_id_code_unique');
        });
    }

    private function enforceSqliteIntegrity(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::statement('DROP INDEX IF EXISTS programs_code_unique');
        DB::statement('DROP INDEX IF EXISTS programs_department_id_code_unique');
        DB::statement('CREATE TABLE programs_integrity_temp (id text primary key, department_id text not null, name varchar not null, code varchar not null, created_at datetime, updated_at datetime, foreign key (department_id) references departments(id) on delete restrict)');
        DB::statement('INSERT INTO programs_integrity_temp (id, department_id, name, code, created_at, updated_at) SELECT id, department_id, name, code, created_at, updated_at FROM programs');
        DB::statement('DROP TABLE programs');
        DB::statement('ALTER TABLE programs_integrity_temp RENAME TO programs');
        DB::statement('CREATE UNIQUE INDEX programs_department_id_code_unique ON programs (department_id, code)');
        Schema::enableForeignKeyConstraints();
    }

    private function enforceGenericIntegrity(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropForeign(['department_id']);
        });

        DB::statement('ALTER TABLE programs MODIFY department_id CHAR(36) NOT NULL');

        Schema::table('programs', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->unique(['department_id', 'code'], 'programs_department_id_code_unique');
        });
    }

    private function relaxPostgresIntegrity(): void
    {
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_department_id_code_unique');
        DB::statement('DROP INDEX IF EXISTS programs_department_id_code_unique');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_department_id_foreign');
        DB::statement('ALTER TABLE programs ALTER COLUMN department_id DROP NOT NULL');

        Schema::table('programs', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->unique('code');
        });
    }

    private function relaxSqliteIntegrity(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::statement('DROP INDEX IF EXISTS programs_department_id_code_unique');
        DB::statement('CREATE TABLE programs_integrity_temp (id text primary key, department_id text, name varchar not null, code varchar not null, created_at datetime, updated_at datetime, foreign key (department_id) references departments(id) on delete set null)');
        DB::statement('INSERT INTO programs_integrity_temp (id, department_id, name, code, created_at, updated_at) SELECT id, department_id, name, code, created_at, updated_at FROM programs');
        DB::statement('DROP TABLE programs');
        DB::statement('ALTER TABLE programs_integrity_temp RENAME TO programs');
        DB::statement('CREATE UNIQUE INDEX programs_code_unique ON programs (code)');
        Schema::enableForeignKeyConstraints();
    }

    private function relaxGenericIntegrity(): void
    {
        Schema::table('programs', function (Blueprint $table): void {
            $table->dropUnique('programs_department_id_code_unique');
            $table->dropForeign(['department_id']);
        });

        DB::statement('ALTER TABLE programs MODIFY department_id CHAR(36) NULL');

        Schema::table('programs', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->unique('code');
        });
    }
};
