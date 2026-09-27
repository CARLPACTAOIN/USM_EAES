<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type')->default('student')->after('program_code');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->string('event_domain')->default('student_affairs')->after('lifecycle_phase');
        });

        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('organization_id');
            $table->uuid('college_id')->nullable();
            $table->uuid('program_id')->nullable();
            $table->string('membership_type')->default('primary_academic');
            $table->string('status')->default('active');
            $table->string('academic_year')->nullable();
            $table->string('source')->default('profile');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('set null');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['college_id', 'program_id']);
        });

        Schema::create('event_participation_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('parent_scope_id')->nullable();
            $table->uuid('linked_event_id')->nullable();
            $table->string('participation_role')->default('participant');
            $table->string('participant_type')->default('organization');
            $table->uuid('organization_id')->nullable();
            $table->uuid('college_id')->nullable();
            $table->uuid('program_id')->nullable();
            $table->string('participant_name_snapshot')->nullable();
            $table->boolean('ppa_required')->default(true);
            $table->string('ppa_status')->default('draft');
            $table->string('ppa_softcopy_path')->nullable();
            $table->string('ppa_softcopy_original_name')->nullable();
            $table->boolean('hardcopy_submitted')->default(false);
            $table->boolean('org_head_signed')->default(false);
            $table->boolean('adviser_signed')->default(false);
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('capture_mode')->default('self_scanning');
            $table->uuid('delegated_to_organization_id')->nullable();
            $table->boolean('analytics_rollup_enabled')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('linked_event_id')->references('id')->on('events')->onDelete('set null');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('set null');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('delegated_to_organization_id')->references('id')->on('organizations')->onDelete('set null');

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'participation_role']);
            $table->index(['event_id', 'ppa_status']);
            $table->index(['organization_id', 'status']);
            $table->index(['college_id', 'status']);
            $table->index(['linked_event_id']);
        });

        // Self-referencing FK must be added after the table (and its PK) is fully created
        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $table->foreign('parent_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
        });

        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('employee_id')->nullable()->unique();
            $table->string('office_department')->nullable();
            $table->uuid('college_id')->nullable();
            $table->string('position_title')->nullable();
            $table->string('verification_status')->default('pending');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('employee_roster_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('employee_id')->unique();
            $table->string('qr_code_value')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('name');
            $table->string('user_type')->default('faculty');
            $table->string('office_department')->nullable();
            $table->uuid('college_id')->nullable();
            $table->string('position_title')->nullable();
            $table->string('status')->default('active');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('set null');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['user_type', 'status']);
            $table->index(['college_id', 'status']);
        });

        Schema::table('event_scanner_assignments', function (Blueprint $table): void {
            $table->uuid('event_participation_scope_id')->nullable()->after('event_id');
            $table->string('audience_scope_type')->nullable()->after('event_participation_scope_id');
            $table->foreign('event_participation_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
            $table->index(['event_participation_scope_id', 'status']);
        });

        Schema::table('scanner_sessions', function (Blueprint $table): void {
            $table->uuid('event_participation_scope_id')->nullable()->after('assignment_id');
            $table->foreign('event_participation_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
            $table->index(['event_participation_scope_id', 'status']);
        });

        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->uuid('event_participation_scope_id')->nullable()->after('event_id');
            $table->uuid('captured_by_organization_id')->nullable()->after('scanner_user_id');
            $table->string('captured_by_admin_scope')->nullable()->after('captured_by_organization_id');
            $table->foreign('event_participation_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
            $table->foreign('captured_by_organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->index(['event_participation_scope_id', 'student_id']);
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->uuid('event_participation_scope_id')->nullable()->after('event_id');
            $table->foreign('event_participation_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
            $table->index(['event_participation_scope_id', 'student_id']);
        });

        Schema::table('pending_student_links', function (Blueprint $table): void {
            $table->uuid('event_participation_scope_id')->nullable()->after('event_id');
            $table->foreign('event_participation_scope_id')->references('id')->on('event_participation_scopes')->onDelete('set null');
            $table->index(['event_participation_scope_id', 'status']);
        });

        $this->backfillUserTypes();
        $this->backfillOrganizationMemberships();
        $this->backfillOrganizerScopes();
        $this->backfillParticipantScopesFromLinkedEvents();
    }

    public function down(): void
    {
        Schema::table('pending_student_links', function (Blueprint $table): void {
            $table->dropForeign(['event_participation_scope_id']);
            $table->dropIndex(['event_participation_scope_id', 'status']);
            $table->dropColumn('event_participation_scope_id');
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropForeign(['event_participation_scope_id']);
            $table->dropIndex(['event_participation_scope_id', 'student_id']);
            $table->dropColumn('event_participation_scope_id');
        });

        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->dropForeign(['event_participation_scope_id']);
            $table->dropForeign(['captured_by_organization_id']);
            $table->dropIndex(['event_participation_scope_id', 'student_id']);
            $table->dropColumn(['event_participation_scope_id', 'captured_by_organization_id', 'captured_by_admin_scope']);
        });

        Schema::table('scanner_sessions', function (Blueprint $table): void {
            $table->dropForeign(['event_participation_scope_id']);
            $table->dropIndex(['event_participation_scope_id', 'status']);
            $table->dropColumn('event_participation_scope_id');
        });

        Schema::table('event_scanner_assignments', function (Blueprint $table): void {
            $table->dropForeign(['event_participation_scope_id']);
            $table->dropIndex(['event_participation_scope_id', 'status']);
            $table->dropColumn(['event_participation_scope_id', 'audience_scope_type']);
        });

        Schema::dropIfExists('employee_roster_entries');
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('event_participation_scopes');
        Schema::dropIfExists('organization_memberships');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('event_domain');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('user_type');
        });
    }

    private function backfillUserTypes(): void
    {
        DB::table('users')
            ->where(function ($query): void {
                $query->whereNotNull('student_id_number')
                    ->orWhereNotNull('qr_code_value')
                    ->orWhereNotNull('organization_id')
                    ->orWhere('email', 'like', 's.%');
            })
            ->update(['user_type' => 'student']);

        DB::table('users')
            ->whereNull('student_id_number')
            ->whereNull('qr_code_value')
            ->whereNull('organization_id')
            ->where('email', 'not like', 's.%')
            ->update(['user_type' => 'faculty_or_staff_pending']);
    }

    private function backfillOrganizationMemberships(): void
    {
        $users = DB::table('users')
            ->whereNotNull('organization_id')
            ->get(['id', 'organization_id', 'college_id', 'program_id']);

        foreach ($users as $user) {
            DB::table('organization_memberships')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'college_id' => $user->college_id,
                'program_id' => $user->program_id,
                'membership_type' => 'primary_academic',
                'status' => 'active',
                'source' => 'profile',
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillOrganizerScopes(): void
    {
        $events = DB::table('events')
            ->leftJoin('organizations', 'events.organization_id', '=', 'organizations.id')
            ->select(
                'events.id',
                'events.organization_id',
                'events.status',
                'events.proposal_document_path',
                'events.proposal_document_original_name',
                'events.hardcopy_submitted',
                'events.head_organization_signed',
                'events.adviser_signed',
                'organizations.name as organization_name',
                'organizations.acronym as organization_acronym',
                'organizations.type as organization_type',
                'organizations.college_id'
            )
            ->get();

        foreach ($events as $event) {
            $scopeId = (string) Str::uuid();
            DB::table('event_participation_scopes')->insert([
                'id' => $scopeId,
                'event_id' => $event->id,
                'participation_role' => 'organizer',
                'participant_type' => $this->participantTypeForOrg($event->organization_type),
                'organization_id' => $event->organization_id,
                'college_id' => $event->college_id,
                'participant_name_snapshot' => trim(($event->organization_acronym ? "{$event->organization_acronym} - " : '').($event->organization_name ?? 'Organizer')),
                'ppa_required' => true,
                'ppa_status' => $this->scopeStatusFromEventStatus($event->status),
                'ppa_softcopy_path' => $event->proposal_document_path,
                'ppa_softcopy_original_name' => $event->proposal_document_original_name,
                'hardcopy_submitted' => (bool) $event->hardcopy_submitted,
                'org_head_signed' => (bool) $event->head_organization_signed,
                'adviser_signed' => (bool) $event->adviser_signed,
                'approved_at' => in_array($event->status, ['approved', 'completed'], true) ? now() : null,
                'capture_mode' => in_array($event->organization_type, ['usg', 'lsg'], true) ? 'central_gate' : 'self_scanning',
                'analytics_rollup_enabled' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('event_scanner_assignments')->where('event_id', $event->id)->update([
                'event_participation_scope_id' => $scopeId,
                'audience_scope_type' => $this->participantTypeForOrg($event->organization_type),
            ]);
            DB::table('scanner_sessions')->where('event_id', $event->id)->update(['event_participation_scope_id' => $scopeId]);
            DB::table('raw_scans')->where('event_id', $event->id)->update([
                'event_participation_scope_id' => $scopeId,
                'captured_by_organization_id' => $event->organization_id,
                'captured_by_admin_scope' => 'organizer',
            ]);
            DB::table('attendance_records')->where('event_id', $event->id)->update(['event_participation_scope_id' => $scopeId]);
            DB::table('pending_student_links')->where('event_id', $event->id)->update(['event_participation_scope_id' => $scopeId]);
        }
    }

    private function backfillParticipantScopesFromLinkedEvents(): void
    {
        $linkedEvents = DB::table('events')
            ->leftJoin('organizations', 'events.organization_id', '=', 'organizations.id')
            ->whereNotNull('events.parent_event_id')
            ->select(
                'events.id',
                'events.parent_event_id',
                'events.organization_id',
                'events.status',
                'events.proposal_document_path',
                'events.proposal_document_original_name',
                'events.hardcopy_submitted',
                'events.head_organization_signed',
                'events.adviser_signed',
                'organizations.name as organization_name',
                'organizations.acronym as organization_acronym',
                'organizations.type as organization_type',
                'organizations.college_id'
            )
            ->get();

        foreach ($linkedEvents as $linkedEvent) {
            $parentScopeId = DB::table('event_participation_scopes')
                ->where('event_id', $linkedEvent->parent_event_id)
                ->where('participation_role', 'organizer')
                ->value('id');

            DB::table('event_participation_scopes')->insert([
                'id' => (string) Str::uuid(),
                'event_id' => $linkedEvent->parent_event_id,
                'parent_scope_id' => $parentScopeId,
                'linked_event_id' => $linkedEvent->id,
                'participation_role' => 'participant',
                'participant_type' => $this->participantTypeForOrg($linkedEvent->organization_type),
                'organization_id' => $linkedEvent->organization_id,
                'college_id' => $linkedEvent->college_id,
                'participant_name_snapshot' => trim(($linkedEvent->organization_acronym ? "{$linkedEvent->organization_acronym} - " : '').($linkedEvent->organization_name ?? 'Participant')),
                'ppa_required' => true,
                'ppa_status' => $this->scopeStatusFromEventStatus($linkedEvent->status),
                'ppa_softcopy_path' => $linkedEvent->proposal_document_path,
                'ppa_softcopy_original_name' => $linkedEvent->proposal_document_original_name,
                'hardcopy_submitted' => (bool) $linkedEvent->hardcopy_submitted,
                'org_head_signed' => (bool) $linkedEvent->head_organization_signed,
                'adviser_signed' => (bool) $linkedEvent->adviser_signed,
                'approved_at' => in_array($linkedEvent->status, ['approved', 'completed'], true) ? now() : null,
                'capture_mode' => 'self_scanning',
                'analytics_rollup_enabled' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function participantTypeForOrg(?string $type): string
    {
        return match ($type) {
            'society' => 'society',
            'lsg' => 'college',
            'non_academic_org' => 'non_academic_org',
            'academic_unit' => 'academic_unit',
            'administrative_office' => 'administrative_office',
            'hr_unit' => 'hr_unit',
            default => 'organization',
        };
    }

    private function scopeStatusFromEventStatus(?string $status): string
    {
        return match ($status) {
            'approved', 'completed' => 'approved',
            'under_review' => 'under_review',
            'submitted' => 'submitted',
            'rejected' => 'rejected',
            default => 'draft',
        };
    }
};
