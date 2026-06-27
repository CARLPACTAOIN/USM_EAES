<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventProposalController;
use App\Http\Controllers\Api\AttendanceSyncController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\NlpQueryController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\PendingStudentLinkController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// API Version 1 Namespace
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected Sanctum routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/scanner/validate', [AuthController::class, 'validateScannerSession']);

        // Event Proposals (PPA)
        Route::get('/events', [EventProposalController::class, 'index']);
        Route::post('/events', [EventProposalController::class, 'store'])->middleware('tenant.scope:create-proposals');
        Route::post('/events/{event}/submit', [EventProposalController::class, 'submit'])->middleware('tenant.scope:create-proposals');
        Route::post('/events/{event}/review', [EventProposalController::class, 'startReview'])->middleware('tenant.scope:approve-proposals');
        Route::post('/events/{event}/approve', [EventProposalController::class, 'approve'])->middleware('tenant.scope:approve-proposals');
        Route::post('/events/{event}/reject', [EventProposalController::class, 'reject'])->middleware('tenant.scope:approve-proposals');
        Route::post('/events/{event}/link', [EventProposalController::class, 'linkSubEvent'])->middleware('tenant.scope:create-proposals');

        // Attendance Hydration and Synchronization
        Route::get('/events/{event}/hydrate', [AttendanceSyncController::class, 'hydrate'])->middleware('tenant.scope:scan-qr-codes');
        Route::post('/attendance/sync', [AttendanceSyncController::class, 'sync'])->middleware('tenant.scope:scan-qr-codes');
        Route::get('/pending-student-links', [PendingStudentLinkController::class, 'index'])->middleware('role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|ARO Admin');
        Route::post('/pending-student-links/{pendingStudentLink}/resolve', [PendingStudentLinkController::class, 'resolve'])->middleware('role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|ARO Admin');
        Route::post('/pending-student-links/{pendingStudentLink}/flag', [PendingStudentLinkController::class, 'flag'])->middleware('role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|ARO Admin');

        // Student Evaluations
        Route::post('/evaluations', [EvaluationController::class, 'store'])->middleware('tenant.scope:submit-evaluations');

        // NLP Query Assistant
        Route::post('/admin/nlp-query', [NlpQueryController::class, 'query'])->middleware('role:Super Admin (OSA)|USG Admin|LSG Admin|Society Admin|ARO Admin');

        // Real-Time Analytics & Gawad Parangal
        Route::get('/events/{event}/analytics', [AnalyticsController::class, 'eventAnalytics']);
        Route::get('/organizations/{organization}/gawad-metrics', [AnalyticsController::class, 'gawadParangalMetrics']);
    });
});
