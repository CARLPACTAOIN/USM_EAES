<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('type');
            $table->string('status')->default('active')->after('logo_path');
        });

        $this->dropProgramCodeUniqueConstraint();

        Schema::table('programs', function (Blueprint $table) {
            $table->unique(['college_id', 'code']);
        });

        Schema::create('admin_applications', function (Blueprint $table) {
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
            $table->index(['status', 'role_name']);
        });

        Schema::create('admin_application_programs', function (Blueprint $table) {
            $table->uuid('admin_application_id');
            $table->uuid('program_id');
            $table->timestamps();

            $table->primary(['admin_application_id', 'program_id'], 'admin_application_programs_primary');
            $table->foreign('admin_application_id')->references('id')->on('admin_applications')->onDelete('cascade');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('cascade');
        });

        Schema::create('admin_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('role_name');
            $table->uuid('organization_id')->nullable();
            $table->uuid('college_id')->nullable();
            $table->string('academic_year');
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->string('position_title')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_primary_admin')->default(true);
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('revoked_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['user_id', 'status']);
            $table->index(['role_name', 'academic_year', 'status']);
        });

        $this->createExpressionIndexes();
    }

    public function down(): void
    {
        $this->dropExpressionIndexes();

        Schema::dropIfExists('admin_assignments');
        Schema::dropIfExists('admin_application_programs');
        Schema::dropIfExists('admin_applications');

        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['college_id', 'code']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->unique('code');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'status']);
        });
    }

    private function createExpressionIndexes(): void
    {
        $driver = DB::getDriverName();
        $uuidZero = '00000000-0000-0000-0000-000000000000';

        if ($driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX organizations_type_college_acronym_unique ON organizations (type, COALESCE(college_id::text, '{$uuidZero}'), acronym)");
            DB::statement("CREATE UNIQUE INDEX admin_applications_pending_target_unique ON admin_applications (applicant_id, role_name, COALESCE(organization_id::text, '{$uuidZero}'), COALESCE(college_id::text, '{$uuidZero}'), COALESCE(organization_acronym, '')) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_primary_unique ON admin_assignments (role_name, COALESCE(organization_id::text, '{$uuidZero}'), COALESCE(college_id::text, '{$uuidZero}'), academic_year) WHERE status = 'active' AND is_primary_admin = true");
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX organizations_type_college_acronym_unique ON organizations (type, COALESCE(college_id, '{$uuidZero}'), acronym)");
            DB::statement("CREATE UNIQUE INDEX admin_applications_pending_target_unique ON admin_applications (applicant_id, role_name, COALESCE(organization_id, '{$uuidZero}'), COALESCE(college_id, '{$uuidZero}'), COALESCE(organization_acronym, '')) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_primary_unique ON admin_assignments (role_name, COALESCE(organization_id, '{$uuidZero}'), COALESCE(college_id, '{$uuidZero}'), academic_year) WHERE status = 'active' AND is_primary_admin = 1");
        }
    }

    private function dropProgramCodeUniqueConstraint(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_code_unique');
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS programs_code_unique');
            return;
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });
    }

    private function dropExpressionIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS admin_assignments_active_primary_unique');
        DB::statement('DROP INDEX IF EXISTS admin_applications_pending_target_unique');
        DB::statement('DROP INDEX IF EXISTS organizations_type_college_acronym_unique');
    }
};
