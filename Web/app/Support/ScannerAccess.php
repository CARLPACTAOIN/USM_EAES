<?php

namespace App\Support;

use App\Models\EventScannerAssignment;
use App\Models\Event;
use App\Models\ScannerSession;
use App\Models\User;
use App\Services\AdminScopeService;
use Laravel\Sanctum\Contracts\HasAbilities;

class ScannerAccess
{
    private const SCANNER_ROLES = [
        'Super Admin (OSA)',
        'USG Admin',
        'LSG Admin',
        'Society Admin',
        'Academic Unit Admin',
        'Office Event Admin',
        'HR Admin',
        'Scanner',
    ];

    private const SCANNER_TOKEN_PERMISSIONS = [
        'scan-qr-codes',
        'manual-entry-id',
    ];

    public static function grantsPermission(User $user, string $permission, ?HasAbilities $token = null): bool
    {
        if ($user->getAllPermissions()->contains(fn ($item) => $item->name === $permission)) {
            return true;
        }

        if (!in_array($permission, self::SCANNER_TOKEN_PERMISSIONS, true)) {
            return false;
        }

        return self::tokenCan($token, $permission)
            && (self::isScannerActor($user) || self::hasScannerSessionToken($user, $token));
    }

    public static function canOpenScannerSession(User $user, ?HasAbilities $token = null): bool
    {
        if ($user->getAllPermissions()->contains(fn ($item) => $item->name === 'scan-qr-codes')) {
            return true;
        }

        return self::tokenCan($token, 'scan-qr-codes')
            && (self::isScannerActor($user) || self::hasScannerSessionToken($user, $token));
    }

    public static function canAccessEvent(User $user, Event $event): bool
    {
        if (self::hasActiveAssignmentForEvent($user, $event)) {
            return true;
        }

        $scope = app(AdminScopeService::class)->forUser($user);

        return $scope?->canAccessEvent($event) ?? false;
    }

    public static function isScannerActor(User $user): bool
    {
        foreach (self::SCANNER_ROLES as $role) {
            if (self::hasRole($user, $role)) {
                return true;
            }
        }

        return false;
    }

    public static function hasRole(User $user, string $role): bool
    {
        return $user->getRoleNames()->contains($role) || $user->hasRole($role);
    }

    private static function tokenCan(?HasAbilities $token, string $ability): bool
    {
        return $token !== null && ($token->can($ability) || $token->can('*'));
    }

    public static function hasActiveAssignmentForEvent(User $user, Event $event): bool
    {
        return EventScannerAssignment::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $user->id)
            ->active()
            ->exists();
    }

    public static function hasAnyActiveAssignment(User $user): bool
    {
        return EventScannerAssignment::query()
            ->where('scanner_user_id', $user->id)
            ->active()
            ->exists();
    }

    public static function hasScannerSessionForEvent(User $user, Event $event, ?HasAbilities $token): bool
    {
        if (!$token) {
            return false;
        }

        $tokenId = $token->id ?? (method_exists($token, 'getKey') ? $token->getKey() : null);
        if (!$tokenId) {
            return false;
        }

        return ScannerSession::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $user->id)
            ->where('token_id', (string) $tokenId)
            ->exists();
    }

    private static function hasScannerSessionToken(User $user, ?HasAbilities $token): bool
    {
        if (!$token) {
            return false;
        }

        $tokenId = $token->id ?? (method_exists($token, 'getKey') ? $token->getKey() : null);
        if (!$tokenId) {
            return false;
        }

        return ScannerSession::query()
            ->where('scanner_user_id', $user->id)
            ->where('token_id', (string) $tokenId)
            ->exists();
    }
}
