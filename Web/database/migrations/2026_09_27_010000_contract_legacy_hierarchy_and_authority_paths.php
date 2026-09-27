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
        // 1. Remove programs.college_id and direct College-to-Program foreign key / unique constraints
        if (Schema::hasColumn('programs', 'college_id')) {
            $driver = DB::getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_college_id_foreign');
                DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_college_id_code_unique');
                DB::statement('DROP INDEX IF EXISTS programs_college_id_code_unique');
                Schema::table('programs', function (Blueprint $table): void {
                    $table->dropColumn('college_id');
                    $table->unique('code');
                });
            } elseif ($driver === 'sqlite') {
                Schema::disableForeignKeyConstraints();
                DB::statement('CREATE TABLE programs_temp (id text primary key, department_id text, name varchar, code varchar, created_at datetime, updated_at datetime, foreign key (department_id) references departments(id) on delete set null)');
                DB::statement('INSERT INTO programs_temp (id, department_id, name, code, created_at, updated_at) SELECT id, department_id, name, code, created_at, updated_at FROM programs');
                DB::statement('DROP TABLE programs');
                DB::statement('ALTER TABLE programs_temp RENAME TO programs');
                DB::statement('CREATE UNIQUE INDEX programs_code_unique ON programs (code)');
                Schema::enableForeignKeyConstraints();
            } else {
                Schema::table('programs', function (Blueprint $table): void {
                    $table->dropForeign(['college_id']);
                    $table->dropUnique(['college_id', 'code']);
                    $table->dropColumn('college_id');
                    $table->unique('code');
                });
            }
        }

        // 2. Drop legacy admin_applications and its pivot / indexes
        DB::statement('DROP INDEX IF EXISTS admin_applications_pending_target_unique');
        Schema::dropIfExists('admin_application_programs');
        Schema::dropIfExists('admin_applications');

        // 3. Drop obsolete free-form academic-year column from organization_memberships
        if (Schema::hasColumn('organization_memberships', 'academic_year')) {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                Schema::disableForeignKeyConstraints();
                Schema::table('organization_memberships', function (Blueprint $table): void {
                    $table->dropColumn('academic_year');
                });
                Schema::enableForeignKeyConstraints();
            } else {
                Schema::table('organization_memberships', function (Blueprint $table): void {
                    $table->dropColumn('academic_year');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('organization_memberships', 'academic_year')) {
            Schema::table('organization_memberships', function (Blueprint $table): void {
                $table->string('academic_year')->nullable()->after('status');
            });
        }

        if (! Schema::hasTable('admin_applications')) {
            Schema::create('admin_applications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('applicant_id');
                $table->string('request_type');
                $table->string('role_name');
                $table->uuid('organization_id')->nullable();
                $table->uuid('college_id')->nullable();
                $table->string('organization_name')->nullable();
                $table->string('organization_acronym')->nullable();
                $table->string('adviser_name')->nullable();
                $table->string('academic_year');
                $table->date('term_start')->nullable();
                $table->date('term_end')->nullable();
                $table->string('position_title')->nullable();
                $table->string('proof_document_path');
                $table->string('proof_document_original_name')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('status')->default('pending');
                $table->text('review_remarks')->nullable();
                $table->uuid('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->foreign('applicant_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
                $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
                $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            });

            Schema::create('admin_application_programs', function (Blueprint $table): void {
                $table->uuid('admin_application_id');
                $table->uuid('program_id');
                $table->timestamps();

                $table->primary(['admin_application_id', 'program_id'], 'admin_application_programs_primary');
                $table->foreign('admin_application_id')->references('id')->on('admin_applications')->onDelete('cascade');
                $table->foreign('program_id')->references('id')->on('programs')->onDelete('cascade');
            });
        }

        if (! Schema::hasColumn('programs', 'college_id')) {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                Schema::disableForeignKeyConstraints();
                DB::statement('CREATE TABLE programs_temp (id text primary key, college_id text, department_id text, name varchar, code varchar, created_at datetime, updated_at datetime, foreign key (college_id) references colleges(id) on delete cascade, foreign key (department_id) references departments(id) on delete set null)');
                DB::statement('INSERT INTO programs_temp (id, department_id, name, code, created_at, updated_at) SELECT id, department_id, name, code, created_at, updated_at FROM programs');
                DB::statement('DROP TABLE programs');
                DB::statement('ALTER TABLE programs_temp RENAME TO programs');
                DB::statement('CREATE UNIQUE INDEX programs_college_id_code_unique ON programs (college_id, code)');
                Schema::enableForeignKeyConstraints();
            } else {
                Schema::table('programs', function (Blueprint $table): void {
                    $table->dropUnique(['code']);
                    $table->uuid('college_id')->nullable()->after('id');
                    $table->foreign('college_id')->references('id')->on('colleges')->onDelete('cascade');
                    $table->unique(['college_id', 'code']);
                });
            }
        }
    }
};
