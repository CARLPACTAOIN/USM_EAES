<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\ScannerSession;
use App\Models\User;
use App\Support\ScannerAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ScannerSessionService
{
    public function __construct(
        private readonly EventLifecycleService $lifecycle,
        private readonly ParticipationScopeService $participationScopes
    ) {
    }

    public function createSession(
        Event $event,
        User $scannerUser,
        User $issuedBy,
        ?string $label = null,
        bool $revokeExisting = true,
        ?EventScannerAssignment $assignment = null
    ): array {
        if ($revokeExisting) {
            $event->scannerSessions()
                ->where('scanner_user_id', $scannerUser->id)
                ->where('status', ScannerSession::STATUS_ACTIVE)
                ->get()
                ->each(fn (ScannerSession $session) => $this->revoke(
                    $session,
                    $issuedBy,
                    'Scanner session replaced for the same event and scanner.'
                ));
        }

        $expiresAt = $this->lifecycle->syncGraceEndsAt($event) ?? now()->addHours($this->syncGraceHours());
        $newToken = $scannerUser->createToken('scanner-' . $event->id, ['scan-qr-codes', 'manual-entry-id']);

        $session = ScannerSession::create([
            'event_id' => $event->id,
            'assignment_id' => $assignment?->id,
            'event_participation_scope_id' => $assignment?->event_participation_scope_id,
            'scanner_user_id' => $scannerUser->id,
            'token_id' => (string) $newToken->accessToken->id,
            'issued_by' => $issuedBy->id,
            'label' => $label,
            'status' => ScannerSession::STATUS_ACTIVE,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $this->audit($event, $issuedBy, $revokeExisting ? 'scanner-session-replaced' : 'scanner-session-generated', [
            'scanner_session_id' => $session->id,
            'assignment_id' => $assignment?->id,
            'event_participation_scope_id' => $assignment?->event_participation_scope_id,
            'scanner_user_id' => $scannerUser->id,
            'label' => $label,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);

        return [$session, $newToken->plainTextToken];
    }

    public function reissueTokenForSession(ScannerSession $session, string $deviceId): array
    {
        $scannerUser = $session->scannerUser;
        if (!$scannerUser) {
            throw new \RuntimeException('Scanner session has no scanner user.');
        }

        $oldTokenId = $session->token_id;
        $newToken = $scannerUser->createToken('scanner-' . $session->event_id, ['scan-qr-codes', 'manual-entry-id']);
        $deviceHash = hash('sha256', $deviceId);

        $updates = [
            'token_id' => (string) $newToken->accessToken->id,
            'last_seen_at' => now(),
        ];

        if (!$session->activated_at) {
            $updates['activated_at'] = now();
        }

        if (!$session->device_fingerprint_hash) {
            $updates['device_id'] = $deviceId;
            $updates['device_fingerprint_hash'] = $deviceHash;
        }

        $session->update($updates);

        if ($oldTokenId) {
            PersonalAccessToken::find($oldTokenId)?->delete();
        }

        $this->audit($session->event, $session->issuer, 'scanner-session-token-reissued', [
            'scanner_session_id' => $session->id,
            'assignment_id' => $session->assignment_id,
            'scanner_user_id' => $session->scanner_user_id,
        ]);

        return [$session->fresh(), $newToken->plainTextToken];
    }

    public function revoke(ScannerSession $session, User $user, string $reason = 'Revoked by admin.'): void
    {
        if ($session->status === ScannerSession::STATUS_REVOKED) {
            return;
        }

        $session->update([
            'status' => ScannerSession::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $user->id,
            'revocation_reason' => $reason,
        ]);

        $this->audit($session->event, $user, 'scanner-session-revoked', [
            'scanner_session_id' => $session->id,
            'reason' => $reason,
        ]);
    }

    public function validateRequest(Request $request, Event $event, bool $forSync = false, bool $allowCompleted = false): array
    {
        $this->lifecycle->update($event);
        $event->refresh();

        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$user || !$token instanceof PersonalAccessToken || !ScannerAccess::canOpenScannerSession($user, $token)) {
            return $this->deny(403, 'This token is not authorized for scanner sessions.', 'unauthorized');
        }

        if ($event->status !== 'approved' && $event->status !== 'completed') {
            return $this->deny(423, 'Scanner session is locked until the event proposal is approved.', 'proposal_locked');
        }

        $session = ScannerSession::where('event_id', $event->id)
            ->where('scanner_user_id', $user->id)
            ->where('token_id', (string) $token?->id)
            ->first();

        if (!$session) {
            if (!ScannerAccess::canAccessEvent($user, $event)) {
                return $this->deny(403, 'Unauthorized event boundary check.', 'event_boundary');
            }

            return $this->deny(403, 'Scanner token does not belong to this event session.', 'event_token_mismatch');
        }

        if (!ScannerAccess::canAccessEvent($user, $event) && !$session->assignment_id) {
            return $this->deny(403, 'Unauthorized event boundary check.', 'event_boundary');
        }

        if ($session->status === ScannerSession::STATUS_REVOKED && !$this->canSyncAfterAssignmentClosure($session, $event, $forSync)) {
            return $this->deny(401, 'This scanner session has been revoked by an admin.', 'revoked');
        }

        if ($this->isExpired($session)) {
            $session->update(['status' => ScannerSession::STATUS_EXPIRED]);
            $this->audit($event, $session->issuer, 'scanner-session-expired', [
                'scanner_session_id' => $session->id,
            ]);

            return $this->deny(401, 'Scanner session expired. Contact admin if you still have unsynced scans.', 'expired');
        }

        $assignmentGuard = $this->validateAssignmentState($session, $event, $forSync);
        if ($assignmentGuard) {
            return $assignmentGuard;
        }

        $scope = $this->scopeForSession($session, $event);
        if (!$scope) {
            return $this->deny(403, 'Scanner session is missing an approved participant scope.', 'participation_scope_missing');
        }

        $scopeGuard = $this->participationScopes->runtimeGuard($scope);
        if ($scopeGuard) {
            return $this->deny($scopeGuard['status'], $scopeGuard['message'], $scopeGuard['reason']);
        }

        if (!$allowCompleted && ($event->status === 'completed' || $event->lifecycle_phase === EventLifecycleService::PHASE_COMPLETED)) {
            return $this->deny(423, 'Event is completed. New scanner activity is closed.', 'event_completed');
        }

        $deviceId = $this->requestDeviceId($request);
        if ($deviceId) {
            $deviceHash = hash('sha256', $deviceId);
            if ($session->device_fingerprint_hash && !hash_equals($session->device_fingerprint_hash, $deviceHash)) {
                return $this->deny(403, 'This scanner session is already bound to another device.', 'device_mismatch');
            }

            $updates = ['last_seen_at' => now()];
            if (!$session->device_fingerprint_hash) {
                $updates['device_id'] = $deviceId;
                $updates['device_fingerprint_hash'] = $deviceHash;
                $updates['activated_at'] = now();
                $this->audit($event, $session->issuer, 'scanner-session-device-bound', [
                    'scanner_session_id' => $session->id,
                ]);
            } elseif (!$session->activated_at) {
                $updates['activated_at'] = now();
            }

            $session->update($updates);
        } else {
            $session->update(['last_seen_at' => now()]);
        }

        if ($forSync) {
            $syncGraceEndsAt = $this->lifecycle->syncGraceEndsAt($event);
            if ($syncGraceEndsAt && now()->gt($syncGraceEndsAt)) {
                return $this->deny(401, 'Scanner session expired. Contact admin if you still have unsynced scans.', 'sync_grace_closed');
            }
        }

        return ['allowed' => true, 'session' => $session, 'scope' => $scope];
    }

    public function validateScanTimestamp(Event $event, Carbon $scannedAt, ?string $scanType = null): ?array
    {
        [$scanStart, $scanEnd] = $scanType
            ? $this->lifecycle->scanWindowForType($event, $scanType)
            : $this->lifecycle->scanWindow($event);

        if ($scanStart && $scannedAt->lt($scanStart)) {
            return match ($scanType) {
                'time_in' => $this->deny(422, 'Time-in is not available before the early check-in window.', 'time_in_window_not_open'),
                'time_out' => $this->deny(422, 'Time-out is not available before the event starts.', 'time_out_window_not_open'),
                default => $this->deny(422, 'Event has not started yet. Scanning is not available.', 'event_not_started'),
            };
        }

        if ($scanEnd && $scannedAt->gt($scanEnd)) {
            return match ($scanType) {
                'time_in' => $this->deny(422, 'Time-in is closed for this event.', 'time_in_window_closed'),
                'time_out' => $this->deny(422, 'Time-out checkout window has closed, but pending valid scans can still be synced.', 'time_out_window_closed'),
                default => $this->deny(422, 'Event has ended. New scans are disabled, but pending valid scans can still be synced.', 'event_ended'),
            };
        }

        return null;
    }

    public function requestDeviceId(Request $request): ?string
    {
        return $request->input('device_id')
            ?? $request->header('X-Device-Id')
            ?? collect($request->input('scans', []))->pluck('device_id')->filter()->first();
    }

    public function scannerState(Event $event, ScannerSession $session): array
    {
        [$scanStart, $scanEnd] = $this->lifecycle->scanWindow($event);
        $scanWindows = $this->lifecycle->scanWindows($event);
        $scope = $this->scopeForSession($session, $event);

        return [
            'id' => $session->id,
            'assignment_id' => $session->assignment_id,
            'event_participation_scope_id' => $scope?->id,
            'participation_scope' => $this->participationScopes->scopePayload($scope),
            'label' => $session->label,
            'status' => $session->status,
            'expires_at' => $session->expires_at,
            'scan_window_start' => $scanStart?->toIso8601String(),
            'scan_window_end' => $scanEnd?->toIso8601String(),
            'time_in_window_start' => $scanWindows['time_in'][0]?->toIso8601String(),
            'time_in_window_end' => $scanWindows['time_in'][1]?->toIso8601String(),
            'time_out_window_start' => $scanWindows['time_out'][0]?->toIso8601String(),
            'time_out_window_end' => $scanWindows['time_out'][1]?->toIso8601String(),
            'sync_grace_ends_at' => $this->lifecycle->syncGraceEndsAt($event)?->toIso8601String(),
        ];
    }

    private function validateAssignmentState(ScannerSession $session, Event $event, bool $forSync): ?array
    {
        if (!$session->assignment_id) {
            return null;
        }

        $assignment = $session->assignment;
        if (!$assignment) {
            return $this->deny(401, 'Scanner assignment is no longer available.', 'assignment_missing');
        }

        if ($assignment->event_participation_scope_id && !$session->event_participation_scope_id) {
            $session->update(['event_participation_scope_id' => $assignment->event_participation_scope_id]);
        }

        if ($assignment->status === EventScannerAssignment::STATUS_ACTIVE && (!$assignment->expires_at || $assignment->expires_at->isFuture())) {
            return null;
        }

        if ($assignment->status === EventScannerAssignment::STATUS_ACTIVE && $assignment->expires_at && $assignment->expires_at->isPast()) {
            $assignment->markExpired();
        }

        if ($this->canSyncAfterAssignmentClosure($session, $event, $forSync)) {
            return null;
        }

        return $this->deny(
            401,
            'This scanner assignment is no longer active. Contact an event admin for access.',
            'assignment_inactive'
        );
    }

    private function isExpired(ScannerSession $session): bool
    {
        return $session->status === ScannerSession::STATUS_EXPIRED
            || ($session->expires_at && now()->greaterThan($session->expires_at));
    }

    private function canSyncAfterAssignmentClosure(ScannerSession $session, Event $event, bool $forSync): bool
    {
        if (!$forSync || !$session->assignment_id) {
            return false;
        }

        $assignment = $session->assignment;
        if (!$assignment) {
            return false;
        }

        $assignmentClosed = $assignment->status !== EventScannerAssignment::STATUS_ACTIVE
            || ($assignment->expires_at && $assignment->expires_at->isPast());
        $sessionClosedByAssignment = $session->status === ScannerSession::STATUS_REVOKED
            && str_contains(strtolower((string) $session->revocation_reason), 'assignment');

        if (!$assignmentClosed && !$sessionClosedByAssignment) {
            return false;
        }

        $syncGraceEndsAt = $this->lifecycle->syncGraceEndsAt($event);

        return !$syncGraceEndsAt || now()->lte($syncGraceEndsAt);
    }

    private function scopeForSession(ScannerSession $session, Event $event): ?EventParticipationScope
    {
        if ($session->event_participation_scope_id) {
            return $session->participationScope ?: EventParticipationScope::find($session->event_participation_scope_id);
        }

        if ($session->assignment?->event_participation_scope_id) {
            $session->update(['event_participation_scope_id' => $session->assignment->event_participation_scope_id]);

            return $session->assignment->participationScope
                ?: EventParticipationScope::find($session->assignment->event_participation_scope_id);
        }

        $scope = $this->participationScopes->ensureOrganizerScope($event);
        $session->update(['event_participation_scope_id' => $scope->id]);

        return $scope;
    }

    private function deny(int $status, string $message, string $reason): array
    {
        return [
            'allowed' => false,
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
        ];
    }

    private function audit(Event $event, User $admin, string $action, array $details): void
    {
        AuditLog::create([
            'target_id' => $event->id,
            'admin_id' => $admin->id,
            'action' => $action,
            'details' => $details + ['event_id' => $event->id],
        ]);
    }

    private function syncGraceHours(): int
    {
        return (int) config('services.eaes.sync_grace_period_hours', 72);
    }
}
