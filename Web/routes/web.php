<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Web\AdminAccessController;
use App\Http\Controllers\Web\AiInsightsController;
use App\Http\Controllers\Web\AnalyticsOverviewController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\AuthorityContextController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DepartmentController;
use App\Http\Controllers\Web\EventAnalyticsController;
use App\Http\Controllers\Web\EventExportController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OfficerRosterController;
use App\Http\Controllers\Web\OrganizationProfileController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\ProposalProcessTemplateController;
use App\Http\Controllers\Web\SchoolYearController;
use App\Http\Controllers\Web\VenueController;
use App\Http\Controllers\Web\WorkflowTaskController;
use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ─── Public / Guest Routes ──────────────────────────────────
Route::get('/', [AuthController::class, 'login'])->name('login');

Route::redirect('/login', '/');

// Google OAuth
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// ─── Authenticated Routes ───────────────────────────────────
Route::middleware(['auth'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/authority-context', [AuthorityContextController::class, 'index'])->name('authority-context.index');
    Route::post('/authority-context', [AuthorityContextController::class, 'store'])->name('authority-context.store')->block();

    // ─── Student Portal ─────────────────────────────────────
    Route::middleware(['role:Student'])->prefix('portal')->group(function () {
        Route::get('/', [PortalController::class, 'index'])->name('portal');
        Route::get('/profile', [PortalController::class, 'profile'])->name('portal.profile');
        Route::post('/profile', [PortalController::class, 'updateProfile'])->name('portal.profile.update');
        Route::get('/evaluations', [PortalController::class, 'evaluations'])->name('portal.evaluations');
        Route::get('/evaluations/{event}', [PortalController::class, 'evaluation'])->name('portal.evaluation');
        Route::post('/evaluations', [PortalController::class, 'submitEvaluation'])->name('portal.evaluation.submit');
    });

    // ─── Admin Dashboard ────────────────────────────────────
    Route::middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin|Faculty|Chief Security', 'authority.context'])
        ->prefix('dashboard')
        ->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/ai', [AiInsightsController::class, 'index'])->name('dashboard.ai');
            Route::post('/ai/query', [AiInsightsController::class, 'query'])->name('dashboard.ai.query');
            Route::get('/organization-profile', [OrganizationProfileController::class, 'show'])->name('dashboard.organization-profile');
            Route::put('/organization-profile/logo', [OrganizationProfileController::class, 'updateLogo'])->name('dashboard.organization-profile.logo.update');
            Route::delete('/organization-profile/logo', [OrganizationProfileController::class, 'destroyLogo'])->name('dashboard.organization-profile.logo.destroy');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('dashboard.notifications');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('dashboard.notifications.read');
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('dashboard.notifications.read-all');
            Route::get('/workflow-tasks', [WorkflowTaskController::class, 'index'])->name('dashboard.workflow-tasks.index');
            Route::get('/workflow-tasks/{task}', [WorkflowTaskController::class, 'show'])->name('dashboard.workflow-tasks.show');
            Route::post('/workflow-tasks/{task}/decision', [WorkflowTaskController::class, 'decide'])->name('dashboard.workflow-tasks.decide');
            Route::post('/workflow-tasks/{task}/reassign', [WorkflowTaskController::class, 'reassign'])->name('dashboard.workflow-tasks.reassign');

            // Events / PPA
            Route::get('/events', [DashboardController::class, 'events'])->name('dashboard.events');
            Route::post('/events', [DashboardController::class, 'createEvent'])->name('dashboard.events.create');
            Route::post('/events/{event}/update', [DashboardController::class, 'updateEvent'])->name('dashboard.events.update');
            Route::post('/events/{event}/submit', [DashboardController::class, 'submitEvent'])->name('dashboard.events.submit');
            Route::get('/events/{event}/checklist', [DashboardController::class, 'organizerChecklist'])->name('dashboard.events.checklist');
            Route::post('/events/{event}/venue-amendment', [DashboardController::class, 'startVenueAmendment'])->name('dashboard.events.venue-amendment');
            Route::get('/events/{event}/proposal-preview', [DashboardController::class, 'proposalPreview'])->name('dashboard.events.proposal-preview');
            Route::get('/events/{event}/permit-preview', [DashboardController::class, 'permitPreview'])->name('dashboard.events.permit-preview');
            Route::post('/events/{event}/link', [DashboardController::class, 'linkEvent'])->name('dashboard.events.link');
            Route::post('/events/{event}/hardcopy', [DashboardController::class, 'markHardcopy'])->name('dashboard.events.hardcopy');
            Route::post('/events/{event}/attendance-policy', [DashboardController::class, 'updateEventAttendancePolicy'])->name('dashboard.events.attendance-policy');
            Route::post('/events/{event}/participant-scopes', [DashboardController::class, 'storeParticipantScope'])->name('dashboard.events.participant-scopes.store');
            Route::post('/events/{event}/participant-scopes/{participationScope}/draft', [DashboardController::class, 'saveParticipantScopeDraft'])->name('dashboard.events.participant-scopes.draft');
            Route::post('/events/{event}/participant-scopes/{participationScope}/submit', [DashboardController::class, 'submitParticipantScope'])->name('dashboard.events.participant-scopes.submit');
            Route::get('/events/{event}/participant-scopes/{participationScope}/preview', [DashboardController::class, 'participantProposalPreview'])->name('dashboard.events.participant-scopes.preview');
            Route::get('/events/{event}/participant-scopes/{participationScope}/evidence', [DashboardController::class, 'downloadParticipantEvidence'])->name('dashboard.events.participant-scopes.evidence');
            Route::post('/events/{event}/participant-scopes/{participationScope}/attendance-policy', [DashboardController::class, 'updateParticipantScopeAttendancePolicy'])->name('dashboard.events.participant-scopes.attendance-policy');
            Route::post('/events/{event}/participant-scopes/{participationScope}/review', [DashboardController::class, 'reviewParticipantScope'])->name('dashboard.events.participant-scopes.review');
            Route::post('/events/{event}/participant-scopes/{participationScope}/approve', [DashboardController::class, 'approveParticipantScope'])->name('dashboard.events.participant-scopes.approve');
            Route::post('/events/{event}/participant-scopes/{participationScope}/revision', [DashboardController::class, 'requestParticipantScopeRevision'])->name('dashboard.events.participant-scopes.revision');
            Route::post('/events/{event}/participant-scopes/{participationScope}/reject', [DashboardController::class, 'rejectParticipantScope'])->name('dashboard.events.participant-scopes.reject');
            Route::post('/events/{event}/participant-scopes/{participationScope}/acknowledge', [DashboardController::class, 'acknowledgeParticipantScope'])->name('dashboard.events.participant-scopes.acknowledge');
            Route::get('/events/{event}/proposal-document', [DashboardController::class, 'downloadProposal'])->name('dashboard.events.proposal-document');
            Route::get('/events/{event}/ppa-attachments/{attachment}', [DashboardController::class, 'downloadCanonicalAttachment'])->name('dashboard.events.ppa-attachments');
            Route::get('/events/{event}/venue-evidence', [VenueController::class, 'downloadEvidence'])->name('dashboard.events.venue-evidence');
            Route::get('/events/{event}/permit-certificate', [VenueController::class, 'downloadCurrentPermitCertificate'])->name('dashboard.events.permit-certificate');
            Route::get('/events/{event}/permit-history', [VenueController::class, 'permitHistory'])->name('dashboard.events.permit-history');
            Route::get('/events/{event}/permit-history/{permitVersion}/certificate', [VenueController::class, 'downloadVersionCertificate'])->name('dashboard.events.permit-history.certificate');
            Route::get('/events/{event}/permit-history/{permitVersion}/evidence', [VenueController::class, 'downloadVersionEvidence'])->name('dashboard.events.permit-history.evidence');
            Route::get('/events/{event}/permit-history/{permitVersion}/ppa', [VenueController::class, 'downloadVersionPpa'])->name('dashboard.events.permit-history.ppa');
            Route::get('/events/{event}/scanner-candidates', [DashboardController::class, 'scannerCandidates'])->name('dashboard.events.scanner-candidates');
            Route::post('/events/{event}/scanner-assignments', [DashboardController::class, 'assignEventScanner'])->name('dashboard.events.scanner-assignments.store');
            Route::post('/events/{event}/scanner-assignments/{assignment}/revoke', [DashboardController::class, 'revokeEventScannerAssignment'])->name('dashboard.events.scanner-assignments.revoke');
            Route::post('/events/{event}/scanner-sessions/{scannerSession}/revoke', [DashboardController::class, 'revokeScannerSession'])->name('dashboard.events.scanner-sessions.revoke');
            Route::post('/events/{event}/sentiments/analyze', [AiInsightsController::class, 'analyzeEventSentiments'])->name('dashboard.events.sentiments.analyze');
            Route::get('/events/{event}/exports/attendance.pdf', [EventExportController::class, 'attendancePdf'])->name('dashboard.events.exports.attendance.pdf');
            Route::get('/events/{event}/exports/attendance.xlsx', [EventExportController::class, 'attendanceExcel'])->name('dashboard.events.exports.attendance.excel');
            Route::get('/events/{event}/exports/evaluations.pdf', [EventExportController::class, 'evaluationsPdf'])->name('dashboard.events.exports.evaluations.pdf');
            Route::get('/events/{event}/exports/evaluations.xlsx', [EventExportController::class, 'evaluationsExcel'])->name('dashboard.events.exports.evaluations.excel');
            Route::get('/events/{event}/analytics/data', [EventAnalyticsController::class, 'data'])->name('dashboard.events.analytics.data');
            Route::get('/events/{event}/analytics', [EventAnalyticsController::class, 'show'])->name('dashboard.events.analytics');

            // Organization Performance Analytics Overview
            Route::get('/analytics', [AnalyticsOverviewController::class, 'index'])->name('dashboard.analytics');

            // Pending QR Links
            Route::get('/pending-student-links', [DashboardController::class, 'pendingLinks'])->name('dashboard.pending-links');
            Route::post('/pending-student-links/{id}/resolve', [DashboardController::class, 'resolveLink'])->name('dashboard.pending-links.resolve');
            Route::post('/pending-student-links/{id}/flag', [DashboardController::class, 'flagLink'])->name('dashboard.pending-links.flag');

            // Admin User Provisioning (Super Admin only)
            Route::get('/admin-users', [AdminAccessController::class, 'index'])->name('dashboard.admin-users');
            Route::get('/proposal-process-templates', [ProposalProcessTemplateController::class, 'index'])->name('dashboard.proposal-process-templates.index');
            Route::post('/proposal-process-templates', [ProposalProcessTemplateController::class, 'store'])->name('dashboard.proposal-process-templates.store');
            Route::put('/proposal-process-template-versions/{version}', [ProposalProcessTemplateController::class, 'update'])->name('dashboard.proposal-process-template-versions.update');
            Route::post('/proposal-process-template-versions/{version}/publish', [ProposalProcessTemplateController::class, 'publish'])->name('dashboard.proposal-process-template-versions.publish');
            Route::post('/proposal-process-template-versions/{version}/retire', [ProposalProcessTemplateController::class, 'retire'])->name('dashboard.proposal-process-template-versions.retire');
            Route::post('/proposal-process-template-versions/{version}/draft', [ProposalProcessTemplateController::class, 'createDraft'])->name('dashboard.proposal-process-template-versions.draft');
            Route::get('/venue-availability', [VenueController::class, 'availability'])->name('dashboard.venue-availability');
            Route::get('/venues', [VenueController::class, 'index'])->name('dashboard.venues');
            Route::get('/admin-access', [AdminAccessController::class, 'index'])->name('dashboard.admin-access');
            Route::get('/admin-users/search', [AdminAccessController::class, 'searchUsers'])->name('dashboard.admin-users.search');
            Route::post('/admin-users', [AdminAccessController::class, 'store'])->name('dashboard.admin-users.create');
            Route::get('/departments', [DepartmentController::class, 'index'])->name('dashboard.departments.index');
            Route::post('/departments', [DepartmentController::class, 'store'])->name('dashboard.departments.store');
            Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('dashboard.departments.update');
            Route::get('/school-years', [SchoolYearController::class, 'index'])->name('dashboard.school-years.index');
            Route::post('/school-years', [SchoolYearController::class, 'store'])->name('dashboard.school-years.store');
            Route::post('/school-years/{schoolYear}/activate', [SchoolYearController::class, 'activate'])->name('dashboard.school-years.activate');
            Route::post('/school-years/{schoolYear}/transition/open', [SchoolYearController::class, 'openTransition'])->name('dashboard.school-years.transition.open');
            Route::post('/school-years/{schoolYear}/transition/close', [SchoolYearController::class, 'closeTransition'])->name('dashboard.school-years.transition.close');
            Route::post('/organizations', [AdminAccessController::class, 'storeOrganization'])->name('dashboard.organizations.store');
            Route::put('/organizations/{organization}', [AdminAccessController::class, 'updateOrganization'])->name('dashboard.organizations.update');
            Route::post('/admin-assignments/{assignment}/revoke', [AdminAccessController::class, 'revoke'])->name('dashboard.admin-assignments.revoke');
            Route::post('/admin-assignments/{assignment}/expire', [AdminAccessController::class, 'expire'])->name('dashboard.admin-assignments.expire');
            Route::post('/osa-authority-overrides', [AdminAccessController::class, 'storeAuthorityOverride'])->name('dashboard.osa-authority-overrides.store');

            // Officer Roster (PRD Feature 1.3)
            Route::get('/organizations/{organization}/officer-roster', [OfficerRosterController::class, 'show'])->name('dashboard.officer-rosters.show');
            Route::get('/organizations/{organization}/officer-roster/eligible-members', [OfficerRosterController::class, 'eligibleMembers'])->name('dashboard.officer-rosters.eligible-members');
            Route::get('/organizations/{organization}/officer-roster/eligible-advisers', [OfficerRosterController::class, 'eligibleAdvisers'])->name('dashboard.officer-rosters.eligible-advisers');
            Route::post('/organizations/{organization}/officer-rosters/{schoolYear}/draft', [OfficerRosterController::class, 'saveDraft'])->name('dashboard.officer-rosters.draft');
            Route::post('/organizations/{organization}/officer-rosters/{schoolYear}/submit', [OfficerRosterController::class, 'submit'])->name('dashboard.officer-rosters.submit');
            Route::post('/organizations/{organization}/officer-rosters/{roster}/verify', [OfficerRosterController::class, 'verify'])->name('dashboard.officer-rosters.verify');
            Route::post('/organizations/{organization}/officer-rosters/{roster}/request-changes', [OfficerRosterController::class, 'requestChanges'])->name('dashboard.officer-rosters.request-changes');
            Route::get('/organizations/{organization}/officer-change-requests', [OfficerRosterController::class, 'listChangeRequests'])->name('dashboard.officer-change-requests.index');
            Route::post('/organizations/{organization}/officer-change-requests', [OfficerRosterController::class, 'createChangeRequest'])->name('dashboard.officer-change-requests.store');
            Route::post('/organizations/{organization}/officer-change-requests/{changeRequest}/approve', [OfficerRosterController::class, 'approveChangeRequest'])->name('dashboard.officer-change-requests.approve');
            Route::post('/organizations/{organization}/officer-change-requests/{changeRequest}/reject', [OfficerRosterController::class, 'rejectChangeRequest'])->name('dashboard.officer-change-requests.reject');
        });
});

// Developer Login Helpers (Strictly Local Env + Explicit Opt-In)
Route::get('/dev/login/psits-admin', [DevLoginController::class, 'loginAsPsitsAdmin'])
    ->name('dev.login.psits-admin');

Route::get('/dev/login/{role}', function (string $role) {
    abort_unless(config('app.env') === 'local' && config('services.eaes.dev_login_enabled'), 404);

    $validRoles = ['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin', 'Student'];
    if (! in_array($role, $validRoles)) {
        abort(400, 'Invalid test role.');
    }

    $emailPrefix = strtolower(str_replace([' ', '(', ')'], '', $role));
    $email = "{$emailPrefix}@usm.edu.ph";

    $university = University::firstOrCreate(
        ['domain' => 'usm.edu.ph'],
        ['name' => 'University of Southern Mindanao']
    );

    // Seed basic college and organization
    $college = College::firstOrCreate(
        ['code' => 'COE'],
        [
            'name' => 'College of Engineering',
            'university_id' => $university->id,
        ]
    );

    $department = Department::firstOrCreate(
        ['college_id' => $college->id, 'code' => 'ICT'],
        [
            'name' => 'Department of Information and Communication Technology',
            'status' => 'active',
        ]
    );

    // Seed programs
    $program1 = Program::firstOrCreate(
        ['department_id' => $department->id, 'code' => 'BSIS'],
        [
            'name' => 'Bachelor of Science in Information Systems',
        ]
    );

    $program2 = Program::firstOrCreate(
        ['department_id' => $department->id, 'code' => 'BSCS'],
        [
            'name' => 'Bachelor of Science in Computer Science',
        ]
    );

    $orgType = match ($role) {
        'USG Admin' => 'usg',
        'LSG Admin' => 'lsg',
        'Academic Unit Admin' => 'academic_unit',
        'Office Event Admin' => 'administrative_office',
        'HR Admin' => 'hr_unit',
        default => 'society',
    };

    $orgAcronym = match ($role) {
        'USG Admin' => 'USG',
        'LSG Admin' => 'COE LSG',
        'Academic Unit Admin' => 'COE ACADEMIC',
        'Office Event Admin' => 'OSA OFFICE',
        'HR Admin' => 'HR',
        default => 'TESTORG',
    };

    $org = Organization::firstOrCreate(
        ['acronym' => $orgAcronym, 'type' => $orgType, 'college_id' => in_array($orgType, ['lsg', 'society', 'academic_unit'], true) ? $college->id : null],
        [
            'department_id' => in_array($orgType, ['society', 'academic_unit'], true) ? $department->id : null,
            'name' => match ($orgType) {
                'lsg' => 'College of Engineering Local Student Government',
                'usg' => 'University Student Government',
                'academic_unit' => 'College of Engineering Academic Unit',
                'administrative_office' => 'Office of Student Affairs',
                'hr_unit' => 'Human Resource Management Office',
                default => 'Test Organization',
            },
            'status' => 'active',
        ]
    );
    if (in_array($orgType, ['society', 'academic_unit'], true)) {
        $org->update(['department_id' => $department->id]);
    }

    // Link programs to organization
    if (! $org->programs()->where('program_id', $program1->id)->exists()) {
        $org->programs()->attach($program1->id);
    }
    if (! $org->programs()->where('program_id', $program2->id)->exists()) {
        $org->programs()->attach($program2->id);
    }

    $user = User::firstOrCreate(
        ['email' => $email],
        [
            'name' => "Test {$role}",
            'google_sub' => "dev-{$emailPrefix}",
            'password' => null,
            'organization_id' => $role === 'Student' ? $org->id : null,
            'college_id' => $role === 'Student' ? $college->id : null,
            'program_id' => $role === 'Student' ? $program1->id : null,
            'program_code' => $role === 'Student' ? 'BSIS' : null,
        ]
    );

    if ($role === 'Student') {
        $user->update([
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program1->id,
            'program_code' => 'BSIS',
        ]);
    }

    if ($role === 'Student') {
        $user->syncRoles('Student');
    } elseif (! $user->adminAssignments()->where('role_name', $role)->where('status', AdminAssignment::STATUS_ACTIVE)->exists()) {
        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $role,
            organization: $org,
            college: $org->college,
            academicYear: now()->year.'-'.(now()->year + 1),
            termStart: now()->toDateString(),
            termEnd: now()->addYear()->toDateString(),
            positionTitle: 'Dev Login',
            approvedBy: $user,
            isPrimaryAdmin: true
        );
    }

    Auth::login($user);

    if ($user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin'])) {
        return redirect()->route('dashboard')->with('success', "Logged in as test {$role}");
    }

    return redirect()->route('portal')->with('success', 'Logged in as test Student');
})->name('dev.login');
