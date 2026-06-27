<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PortalController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\AiInsightsController;
use App\Http\Controllers\Web\EventExportController;
use App\Http\Controllers\Web\AnalyticsOverviewController;
use App\Http\Controllers\Web\AdminApplicationController;
use App\Http\Controllers\Web\AdminAccessController;

// ─── Public / Guest Routes ──────────────────────────────────
Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [AuthController::class, 'login'])->name('login');

// Google OAuth
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// ─── Authenticated Routes ───────────────────────────────────
Route::middleware(['auth'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // ─── Student Portal ─────────────────────────────────────
    Route::prefix('portal')->group(function () {
        Route::get('/', [PortalController::class, 'index'])->name('portal');
        Route::get('/profile', [PortalController::class, 'profile'])->name('portal.profile');
        Route::post('/profile', [PortalController::class, 'updateProfile'])->name('portal.profile.update');
        Route::get('/admin-applications', [AdminApplicationController::class, 'index'])->name('portal.admin-applications');
        Route::post('/admin-applications', [AdminApplicationController::class, 'store'])->name('portal.admin-applications.store');
        Route::get('/evaluations/{event}', [PortalController::class, 'evaluation'])->name('portal.evaluation');
        Route::post('/evaluations', [PortalController::class, 'submitEvaluation'])->name('portal.evaluation.submit');
    });

    // ─── Admin Dashboard ────────────────────────────────────
    Route::middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|ARO Admin'])
        ->prefix('dashboard')
        ->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/ai', [AiInsightsController::class, 'index'])->name('dashboard.ai');
            Route::post('/ai/query', [AiInsightsController::class, 'query'])->name('dashboard.ai.query');

            // Events / PPA
            Route::get('/events', [DashboardController::class, 'events'])->name('dashboard.events');
            Route::post('/events', [DashboardController::class, 'createEvent'])->name('dashboard.events.create');
            Route::post('/events/{event}/submit', [DashboardController::class, 'submitEvent'])->name('dashboard.events.submit');
            Route::post('/events/{event}/review', [DashboardController::class, 'reviewEvent'])->name('dashboard.events.review');
            Route::post('/events/{event}/approve', [DashboardController::class, 'approveEvent'])->name('dashboard.events.approve');
            Route::post('/events/{event}/reject', [DashboardController::class, 'rejectEvent'])->name('dashboard.events.reject');
            Route::post('/events/{event}/link', [DashboardController::class, 'linkEvent'])->name('dashboard.events.link');
            Route::post('/events/{event}/hardcopy', [DashboardController::class, 'markHardcopy'])->name('dashboard.events.hardcopy');
            Route::get('/events/{event}/proposal-document', [DashboardController::class, 'downloadProposal'])->name('dashboard.events.proposal-document');
            Route::post('/events/{event}/scanner-link', [DashboardController::class, 'generateScannerLink'])->name('dashboard.events.scanner-link');
            Route::post('/events/{event}/sentiments/analyze', [AiInsightsController::class, 'analyzeEventSentiments'])->name('dashboard.events.sentiments.analyze');
            Route::get('/events/{event}/exports/attendance.pdf', [EventExportController::class, 'attendancePdf'])->name('dashboard.events.exports.attendance.pdf');
            Route::get('/events/{event}/exports/attendance.xlsx', [EventExportController::class, 'attendanceExcel'])->name('dashboard.events.exports.attendance.excel');
            Route::get('/events/{event}/exports/evaluations.pdf', [EventExportController::class, 'evaluationsPdf'])->name('dashboard.events.exports.evaluations.pdf');
            Route::get('/events/{event}/exports/evaluations.xlsx', [EventExportController::class, 'evaluationsExcel'])->name('dashboard.events.exports.evaluations.excel');
            Route::get('/events/{event}/analytics', [\App\Http\Controllers\Api\AnalyticsController::class, 'eventAnalytics'])->name('dashboard.events.analytics');

            // Organization Performance Analytics Overview
            Route::get('/analytics', [AnalyticsOverviewController::class, 'index'])->name('dashboard.analytics');

            // Pending QR Links
            Route::get('/pending-student-links', [DashboardController::class, 'pendingLinks'])->name('dashboard.pending-links');
            Route::post('/pending-student-links/{id}/resolve', [DashboardController::class, 'resolveLink'])->name('dashboard.pending-links.resolve');
            Route::post('/pending-student-links/{id}/flag', [DashboardController::class, 'flagLink'])->name('dashboard.pending-links.flag');

            // Admin User Provisioning (Super Admin only)
            Route::get('/admin-users', [AdminAccessController::class, 'index'])->name('dashboard.admin-users');
            Route::get('/admin-access', [AdminAccessController::class, 'index'])->name('dashboard.admin-access');
            Route::post('/admin-users', [AdminAccessController::class, 'store'])->name('dashboard.admin-users.create');
            Route::post('/admin-applications/{application}/approve', [AdminAccessController::class, 'approve'])->name('dashboard.admin-applications.approve');
            Route::post('/admin-applications/{application}/reject', [AdminAccessController::class, 'reject'])->name('dashboard.admin-applications.reject');
            Route::post('/admin-assignments/{assignment}/revoke', [AdminAccessController::class, 'revoke'])->name('dashboard.admin-assignments.revoke');
            Route::post('/admin-assignments/{assignment}/expire', [AdminAccessController::class, 'expire'])->name('dashboard.admin-assignments.expire');
        });
});

// Developer Login Helper (Strictly Local Env + Explicit Opt-In)
Route::get('/dev/login/{role}', function (string $role) {
        abort_unless(config('app.env') === 'local' && config('services.eaes.dev_login_enabled'), 404);

        $validRoles = ['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'ARO Admin', 'Student'];
        if (!in_array($role, $validRoles)) {
            abort(400, 'Invalid test role.');
        }

        $emailPrefix = strtolower(str_replace([' ', '(', ')'], '', $role));
        $email = "{$emailPrefix}@usm.edu.ph";

        $university = \App\Models\University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        // Seed basic college and organization
        $college = \App\Models\College::firstOrCreate(
            ['code' => 'COE'],
            [
                'name' => 'College of Engineering',
                'university_id' => $university->id
            ]
        );

        // Seed programs
        $program1 = \App\Models\Program::firstOrCreate(
            ['code' => 'BSIS'],
            [
                'college_id' => $college->id,
                'name' => 'Bachelor of Science in Information Systems'
            ]
        );

        $program2 = \App\Models\Program::firstOrCreate(
            ['code' => 'BSCS'],
            [
                'college_id' => $college->id,
                'name' => 'Bachelor of Science in Computer Science'
            ]
        );

        $orgType = match ($role) {
            'USG Admin' => 'usg',
            'LSG Admin' => 'lsg',
            'ARO Admin' => 'aro',
            default => 'society',
        };

        $orgAcronym = match ($role) {
            'USG Admin' => 'USG',
            'LSG Admin' => 'COE LSG',
            'ARO Admin' => 'ARO',
            default => 'TESTORG',
        };

        $org = \App\Models\Organization::firstOrCreate(
            ['acronym' => $orgAcronym, 'type' => $orgType, 'college_id' => $orgType === 'lsg' || $orgType === 'society' ? $college->id : null],
            [
                'name' => $orgType === 'lsg' ? 'College of Engineering Local Student Government' : ($orgType === 'usg' ? 'University Student Government' : ($orgType === 'aro' ? 'Admission and Records Office' : 'Test Organization')),
                'status' => 'active'
            ]
        );

        // Link programs to organization
        if (!$org->programs()->where('program_id', $program1->id)->exists()) {
            $org->programs()->attach($program1->id);
        }
        if (!$org->programs()->where('program_id', $program2->id)->exists()) {
            $org->programs()->attach($program2->id);
        }

        $user = \App\Models\User::firstOrCreate(
            ['email' => $email],
            [
                'name' => "Test {$role}",
                'google_sub' => "dev-{$emailPrefix}",
                'password' => null,
                'organization_id' => $role === 'Student' ? $org->id : null,
                'college_id' => $role === 'Student' ? $college->id : null,
                'program_id' => $role === 'Student' ? $program1->id : null,
                'program_code' => $role === 'Student' ? 'BSIS' : null
            ]
        );

        if ($role === 'Student') {
            $user->update([
                'organization_id' => $org->id,
                'college_id' => $college->id,
                'program_id' => $program1->id,
                'program_code' => 'BSIS'
            ]);
        }

        if ($role === 'Student') {
            $user->syncRoles('Student');
        } elseif (!$user->adminAssignments()->where('role_name', $role)->where('status', \App\Models\AdminAssignment::STATUS_ACTIVE)->exists()) {
            app(\App\Services\AdminAssignmentService::class)->createAssignment(
                user: $user,
                roleName: $role,
                organization: $org,
                college: $org->college,
                academicYear: now()->year . '-' . (now()->year + 1),
                termStart: now()->toDateString(),
                termEnd: now()->addYear()->toDateString(),
                positionTitle: 'Dev Login',
                approvedBy: $user,
                isPrimaryAdmin: true
            );
        }

        \Illuminate\Support\Facades\Auth::login($user);

        if ($user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'ARO Admin'])) {
            return redirect()->route('dashboard')->with('success', "Logged in as test {$role}");
        }

        return redirect()->route('portal')->with('success', "Logged in as test Student");
})->name('dev.login');
