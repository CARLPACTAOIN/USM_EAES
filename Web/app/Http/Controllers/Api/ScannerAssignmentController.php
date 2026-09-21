<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventScannerAssignment;
use App\Models\ScannerSession;
use App\Services\EventLifecycleService;
use App\Services\EventSessionScheduleService;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScannerAssignmentController extends Controller
{
    private const ACTIVE_SESSION_EXISTS_MESSAGE = 'This scanner assignment is already active on another device. Ask an admin to revoke the old scanner session before activating this device.';

    public function __construct(
        private readonly ScannerAssignmentService $assignments,
        private readonly ScannerSessionService $scannerSessions,
        private readonly EventLifecycleService $lifecycle,
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventSessionScheduleService $sessionSchedules,
    ) {}

    public function index(Request $request)
    {
        if (! $this->tokenCanManageAssignments($request)) {
            return response()->json([
                'success' => false,
                'message' => 'This token can only be used for its assigned mobile scanner actions.',
            ], 403);
        }

        $deviceId = $request->header('X-Device-Id');
        $assignments = $this->assignments
            ->assignmentsForUser($request->user())
            ->map(fn (EventScannerAssignment $assignment) => $this->assignmentPayload($assignment, $deviceId));

        return response()->json([
            'success' => true,
            'assignments' => $assignments->values(),
        ]);
    }

    public function activate(Request $request, EventScannerAssignment $assignment)
    {
        if (! $this->tokenCanManageAssignments($request)) {
            return response()->json([
                'success' => false,
                'message' => 'This token cannot activate scanner sessions.',
            ], 403);
        }

        if ($assignment->scanner_user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'This scanner assignment belongs to another user.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
            'device_name' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $assignment->loadMissing(['participationScope.organization', 'event.eventDays', 'event.organization', 'scannerUser']);
        $event = $assignment->event;
        $this->lifecycle->update($event);
        $event->refresh()->loadMissing(['eventDays', 'organization']);

        if (! $assignment->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'This scanner assignment is no longer active.',
                'reason' => 'assignment_inactive',
            ], 423);
        }

        if (! $assignment->participationScope) {
            return response()->json([
                'success' => false,
                'message' => 'This scanner assignment is missing a participant scope.',
                'reason' => 'participation_scope_missing',
            ], 423);
        }

        if ($guard = $this->participationScopes->runtimeGuard($assignment->participationScope)) {
            return response()->json([
                'success' => false,
                'message' => $guard['message'],
                'reason' => $guard['reason'],
            ], $guard['status']);
        }

        if (! $this->activationAllowed($event)) {
            return response()->json([
                'success' => false,
                'message' => 'Scanner activation is not available for this event.',
                'reason' => 'activation_locked',
                'event_status' => $event->status,
                'event_phase' => $event->lifecycle_phase,
            ], 423);
        }

        $deviceId = $request->input('device_id');
        $activeAssignmentSession = $this->activeAssignmentSession($assignment);
        if ($activeAssignmentSession) {
            if ($this->sessionDeviceMatches($activeAssignmentSession, $deviceId) === false) {
                return $this->activeSessionExistsResponse();
            }

            [$session, $plainTextToken] = $this->scannerSessions->reissueTokenForSession(
                $activeAssignmentSession,
                $deviceId
            );

            $assignment->update(['last_activated_at' => now()]);

            return response()->json([
                'success' => true,
                'resumed' => true,
                'message' => 'Scanner assignment resumed.',
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
                'assignment' => $this->assignmentPayload($assignment->fresh(['participationScope.organization', 'event.organization', 'event.eventDays', 'scannerSessions']), $deviceId),
                'event' => $this->eventPayload($event),
                'scanner_session' => $this->scannerSessions->scannerState($event, $session->fresh()),
            ]);
        }

        if ($this->otherActiveAssignmentSession($assignment, $request)) {
            return $this->activeSessionExistsResponse();
        }

        [$session, $plainTextToken] = $this->scannerSessions->createSession(
            event: $event,
            scannerUser: $request->user(),
            issuedBy: $assignment->assignedBy,
            label: $assignment->label,
            revokeExisting: false,
            assignment: $assignment
        );
        $session->update([
            'device_id' => $deviceId,
            'device_fingerprint_hash' => hash('sha256', $deviceId),
            'activated_at' => now(),
            'last_seen_at' => now(),
        ]);

        $assignment->update(['last_activated_at' => now()]);

        return response()->json([
            'success' => true,
            'resumed' => false,
            'message' => 'Scanner assignment activated.',
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'assignment' => $this->assignmentPayload($assignment->fresh(['participationScope.organization', 'event.organization', 'event.eventDays', 'scannerSessions']), $deviceId),
            'event' => $this->eventPayload($event),
            'scanner_session' => $this->scannerSessions->scannerState($event, $session->fresh()),
        ]);
    }

    private function assignmentPayload(EventScannerAssignment $assignment, ?string $deviceId = null): array
    {
        $assignment->loadMissing(['participationScope.organization', 'event.organization', 'event.eventDays', 'scannerSessions']);
        $event = $assignment->event;
        $activationAllowed = $assignment->isActive()
            && $this->activationAllowed($event)
            && $assignment->participationScope
            && ! $this->participationScopes->runtimeGuard($assignment->participationScope);

        $activeSession = $this->activeAssignmentSession($assignment);
        $activeSessionDeviceMatches = $activeSession && $deviceId
            ? $this->sessionDeviceMatches($activeSession, $deviceId)
            : null;
        $otherActiveSession = $this->otherActiveAssignmentSession($assignment);
        $activationState = 'locked';
        $activationMessage = null;

        if ($activationAllowed) {
            if ($activeSession && $activeSessionDeviceMatches === false) {
                $activationState = 'blocked_active_session';
                $activationMessage = self::ACTIVE_SESSION_EXISTS_MESSAGE;
            } elseif ($activeSession) {
                $activationState = 'resume';
                $activationMessage = 'Scanner assignment can be resumed on this device.';
            } elseif ($otherActiveSession) {
                $activationState = 'blocked_active_session';
                $activeSessionDeviceMatches = false;
                $activationMessage = 'Another scanner assignment is already active for this account. Ask an admin to revoke the old scanner session before activating this event.';
            } else {
                $activationState = 'activate';
            }
        }

        return [
            'id' => $assignment->id,
            'label' => $assignment->label,
            'status' => $assignment->status,
            'expires_at' => $assignment->expires_at,
            'last_activated_at' => $assignment->last_activated_at,
            'max_devices' => $assignment->max_devices,
            'activation_allowed' => $activationAllowed,
            'session_available' => $assignment->scannerSessions
                ->filter(fn (ScannerSession $session) => $this->sessionIsOpen($session))
                ->isNotEmpty(),
            'activation_state' => $activationState,
            'active_session_device_matches' => $activeSessionDeviceMatches,
            'activation_message' => $activationMessage,
            'event' => $this->eventPayload($event),
            'participation_scope' => $this->participationScopes->scopePayload($assignment->participationScope),
        ];
    }

    private function eventPayload(Event $event): array
    {
        $event->loadMissing(['organization', 'eventDays.sessions']);

        return [
            'id' => $event->id,
            'title' => $event->title,
            'status' => $event->status,
            'lifecycle_phase' => $event->lifecycle_phase,
            'event_domain' => $event->event_domain,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'organization' => [
                'id' => $event->organization?->id,
                'name' => $event->organization?->name,
                'acronym' => $event->organization?->acronym,
                'type' => $event->organization?->type,
            ],
            'event_days' => $event->eventDays
                ->sortBy('day_number')
                ->values()
                ->map(fn ($day) => [
                    'id' => $day->id,
                    'day_number' => $day->day_number,
                    'date' => $day->date,
                    'start_time' => $day->start_time,
                    'end_time' => $day->end_time,
                    'sessions' => $this->sessionSchedules->sessionsForDay($day)
                        ->map(fn ($session): array => [
                            'id' => $session->id,
                            'name' => $session->name,
                            'start_time' => $session->start_time,
                            'end_time' => $session->end_time,
                            'required_for_attendance' => (bool) $session->required_for_attendance,
                        ])
                        ->values()
                        ->all(),
                ]),
            'sync_grace_ends_at' => $this->lifecycle->syncGraceEndsAt($event)?->toIso8601String(),
        ];
    }

    private function activationAllowed(Event $event): bool
    {
        if ($event->status !== 'approved') {
            return false;
        }

        if ($event->lifecycle_phase === EventLifecycleService::PHASE_COMPLETED) {
            return false;
        }

        $syncGraceEndsAt = $this->lifecycle->syncGraceEndsAt($event);

        return ! $syncGraceEndsAt || now()->lte($syncGraceEndsAt);
    }

    private function activeAssignmentSession(EventScannerAssignment $assignment): ?ScannerSession
    {
        if ($assignment->relationLoaded('scannerSessions')) {
            return $assignment->scannerSessions
                ->filter(fn (ScannerSession $session) => $this->sessionIsOpen($session))
                ->sortByDesc('updated_at')
                ->first();
        }

        return ScannerSession::query()
            ->where('assignment_id', $assignment->id)
            ->where('scanner_user_id', $assignment->scanner_user_id)
            ->where('status', ScannerSession::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('updated_at')
            ->first();
    }

    private function otherActiveAssignmentSession(EventScannerAssignment $assignment, ?Request $request = null): ?ScannerSession
    {
        $scannerUserId = $request?->user()?->id ?? $assignment->scanner_user_id;

        return ScannerSession::query()
            ->where('scanner_user_id', $scannerUserId)
            ->where('status', ScannerSession::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereNotNull('assignment_id')
            ->where('assignment_id', '!=', $assignment->id)
            ->whereHas('assignment', fn ($query) => $query->active())
            ->latest('updated_at')
            ->first();
    }

    private function sessionIsOpen(ScannerSession $session): bool
    {
        if ($session->status !== ScannerSession::STATUS_ACTIVE) {
            return false;
        }

        return ! $session->expires_at || $session->expires_at->isFuture();
    }

    private function sessionDeviceMatches(ScannerSession $session, string $deviceId): ?bool
    {
        if (! $session->device_fingerprint_hash) {
            return null;
        }

        return hash_equals($session->device_fingerprint_hash, hash('sha256', $deviceId));
    }

    private function activeSessionExistsResponse()
    {
        return response()->json([
            'success' => false,
            'message' => self::ACTIVE_SESSION_EXISTS_MESSAGE,
            'reason' => 'active_session_exists',
        ], 409);
    }

    private function tokenCanManageAssignments(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token && ($token->can('scanner-assignments') || $token->can('*'));
    }
}
