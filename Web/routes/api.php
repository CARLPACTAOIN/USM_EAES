<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AttendanceSyncController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\EventProposalController;
use App\Http\Controllers\Api\NlpQueryController;
use App\Http\Controllers\Api\PendingStudentLinkController;
use App\Http\Controllers\Api\ScannerAssignmentController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'throttle:api-authenticated']);

// API Version 1 Namespace
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
    Route::post('/auth/google/mobile', [AuthController::class, 'mobileGoogleLogin'])->middleware('throttle:api-google-login');

    // Protected Sanctum routes
    Route::middleware(['auth:sanctum', 'throttle:api-authenticated'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:api-write');
        Route::post('/mobile/logout', [AuthController::class, 'logout'])->middleware('throttle:api-write');
        Route::post('/scanner/validate', [AuthController::class, 'validateScannerSession']);
        Route::get('/scanner/assignments', [ScannerAssignmentController::class, 'index']);
        Route::post('/scanner/assignments/{assignment}/activate', [ScannerAssignmentController::class, 'activate'])->middleware('throttle:api-write');

        // Event Proposals (PPA)
        Route::get('/events', [EventProposalController::class, 'index']);
        Route::get('/venues', [VenueController::class, 'index']);
        Route::post('/events', [EventProposalController::class, 'store'])->middleware(['tenant.scope:create-proposals', 'throttle:api-write']);
        Route::post('/events/{event}/submit', [EventProposalController::class, 'submit'])->middleware(['tenant.scope:create-proposals', 'throttle:api-write']);
        Route::patch('/events/{event}', [EventProposalController::class, 'update'])->middleware(['tenant.scope:create-proposals', 'throttle:api-write']);
        Route::post('/events/{event}/link', [EventProposalController::class, 'linkSubEvent'])->middleware(['tenant.scope:create-proposals', 'throttle:api-write']);
        Route::post('/events/{event}/venue-amendment', [EventProposalController::class, 'startVenueAmendment'])->middleware(['tenant.scope:create-proposals', 'throttle:api-write']);
        Route::get('/events/{event}/venue-evidence', [VenueController::class, 'evidence']);
        Route::get('/events/{event}/permit-history', [VenueController::class, 'history']);
        Route::get('/events/{event}/permit-history/{permitVersion}/evidence', [VenueController::class, 'versionEvidence']);

        // Attendance Hydration and Synchronization
        Route::get('/events/{event}/hydrate', [AttendanceSyncController::class, 'hydrate'])->middleware('tenant.scope:scan-qr-codes');
        Route::post('/attendance/sync', [AttendanceSyncController::class, 'sync'])->middleware(['tenant.scope:scan-qr-codes', 'throttle:api-scanner-sync']);
        Route::get('/pending-student-links', [PendingStudentLinkController::class, 'index'])->middleware('role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin');
        Route::post('/pending-student-links/{pendingStudentLink}/resolve', [PendingStudentLinkController::class, 'resolve'])->middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin', 'throttle:api-write']);
        Route::post('/pending-student-links/{pendingStudentLink}/flag', [PendingStudentLinkController::class, 'flag'])->middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin', 'throttle:api-write']);

        // Student Evaluations
        Route::post('/evaluations', [EvaluationController::class, 'store'])->middleware(['tenant.scope:submit-evaluations', 'throttle:api-write']);

        // NLP Query Assistant
        Route::post('/admin/nlp-query', [NlpQueryController::class, 'query'])->middleware(['role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|Academic Unit Admin|Office Event Admin|HR Admin', 'throttle:api-nlp']);

        // Real-Time Analytics & Gawad Parangal
        Route::get('/events/{event}/analytics', [AnalyticsController::class, 'eventAnalytics']);
        Route::get('/organizations/{organization}/gawad-metrics', [AnalyticsController::class, 'gawadParangalMetrics']);
    });
});
