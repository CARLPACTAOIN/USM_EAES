<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Web\AdminAccessController;
use App\Http\Controllers\Web\AiInsightsController;
use App\Http\Controllers\Web\AnalyticsOverviewController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EventAnalyticsController;
use App\Http\Controllers\Web\EventExportController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OrganizationProfileController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\VenueController;
use App\Models\AdminAssignment;
use App\Models\College;
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
    Route::middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin'])
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

            // Events / PPA
            Route::get('/events', [DashboardController::class, 'events'])->name('dashboard.events');
            Route::post('/events', [DashboardController::class, 'createEvent'])->name('dashboard.events.create');
            Route::post('/events/{event}/update', [DashboardController::class, 'updateEvent'])->name('dashboard.events.update');
            Route::post('/events/{event}/submit', [DashboardController::class, 'submitEvent'])->name('dashboard.events.submit');
            Route::post('/events/{event}/review', [DashboardController::class, 'reviewEvent'])->name('dashboard.events.review');
            Route::post('/events/{event}/approve', [DashboardController::class, 'approveEvent'])->name('dashboard.events.approve');
            Route::post('/events/{event}/revision', [DashboardController::class, 'requestEventRevision'])->name('dashboard.events.revision');
            Route::post('/events/{event}/reject', [DashboardController::class, 'rejectEvent'])->name('dashboard.events.reject');
            Route::post('/events/{event}/venue-amendment', [DashboardController::class, 'startVenueAmendment'])->name('dashboard.events.venue-amendment');
            Route::post('/events/{event}/link', [DashboardController::class, 'linkEvent'])->name('dashboard.events.link');
            Route::post('/events/{event}/hardcopy', [DashboardController::class, 'markHardcopy'])->name('dashboard.events.hardcopy');
            Route::post('/events/{event}/attendance-policy', [DashboardController::class, 'updateEventAttendancePolicy'])->name('dashboard.events.attendance-policy');
            Route::post('/events/{event}/participant-scopes', [DashboardController::class, 'storeParticipantScope'])->name('dashboard.events.participant-scopes.store');
            Route::post('/events/{event}/participant-scopes/{participationScope}/submit', [DashboardController::class, 'submitParticipantScope'])->name('dashboard.events.participant-scopes.submit');
            Route::post('/events/{event}/participant-scopes/{participationScope}/attendance-policy', [DashboardController::class, 'updateParticipantScopeAttendancePolicy'])->name('dashboard.events.participant-scopes.attendance-policy');
            Route::post('/events/{event}/participant-scopes/{participationScope}/review', [DashboardController::class, 'reviewParticipantScope'])->name('dashboard.events.participant-scopes.review');
            Route::post('/events/{event}/participant-scopes/{participationScope}/approve', [DashboardController::class, 'approveParticipantScope'])->name('dashboard.events.participant-scopes.approve');
            Route::post('/events/{event}/participant-scopes/{participationScope}/revision', [DashboardController::class, 'requestParticipantScopeRevision'])->name('dashboard.events.participant-scopes.revision');
            Route::post('/events/{event}/participant-scopes/{participationScope}/reject', [DashboardController::class, 'rejectParticipantScope'])->name('dashboard.events.participant-scopes.reject');
            Route::post('/events/{event}/participant-scopes/{participationScope}/acknowledge', [DashboardController::class, 'acknowledgeParticipantScope'])->name('dashboard.events.participant-scopes.acknowledge');
            Route::get('/events/{event}/proposal-document', [DashboardController::class, 'downloadProposal'])->name('dashboard.events.proposal-document');
            Route::get('/events/{event}/venue-evidence', [VenueController::class, 'downloadEvidence'])->name('dashboard.events.venue-evidence');
            Route::get('/events/{event}/permit-history', [VenueController::class, 'permitHistory'])->name('dashboard.events.permit-history');
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
            Route::get('/venue-availability', [VenueController::class, 'availability'])->name('dashboard.venue-availability');
            Route::get('/venues', [VenueController::class, 'index'])->name('dashboard.venues');
            Route::get('/admin-access', [AdminAccessController::class, 'index'])->name('dashboard.admin-access');
            Route::get('/admin-users/search', [AdminAccessController::class, 'searchUsers'])->name('dashboard.admin-users.search');
            Route::post('/admin-users', [AdminAccessController::class, 'store'])->name('dashboard.admin-users.create');
            Route::post('/organizations', [AdminAccessController::class, 'storeOrganization'])->name('dashboard.organizations.store');
            Route::put('/organizations/{organization}', [AdminAccessController::class, 'updateOrganization'])->name('dashboard.organizations.update');
            Route::post('/admin-assignments/{assignment}/revoke', [AdminAccessController::class, 'revoke'])->name('dashboard.admin-assignments.revoke');
            Route::post('/admin-assignments/{assignment}/expire', [AdminAccessController::class, 'expire'])->name('dashboard.admin-assignments.expire');
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

    // Seed programs
    $program1 = Program::firstOrCreate(
        ['code' => 'BSIS'],
        [
            'college_id' => $college->id,
            'name' => 'Bachelor of Science in Information Systems',
        ]
    );

    $program2 = Program::firstOrCreate(
        ['code' => 'BSCS'],
        [
            'college_id' => $college->id,
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
