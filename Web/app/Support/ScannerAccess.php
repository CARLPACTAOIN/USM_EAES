<?php

namespace App\Support;

use App\Models\Event;
use App\Models\User;
use Laravel\Sanctum\Contracts\HasAbilities;

class ScannerAccess
{
    private const SCANNER_ROLES = [
        'Super Admin (OSA)',
        'USG Admin',
        'LSG Admin',
        'Society Admin',
        'ARO Admin',
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

        return self::isScannerActor($user) && self::tokenCan($token, $permission);
    }

    public static function canOpenScannerSession(User $user, ?HasAbilities $token = null): bool
    {
        if (!self::isScannerActor($user)) {
            return false;
        }

        return self::tokenCan($token, 'scan-qr-codes')
            || $user->getAllPermissions()->contains(fn ($item) => $item->name === 'scan-qr-codes');
    }

    public static function canAccessEvent(User $user, Event $event): bool
    {
        if (self::hasRole($user, 'Super Admin (OSA)')) {
            return true;
        }

        $event->loadMissing('organization');
        $user->loadMissing('organization');

        if (!$user->organization || !$event->organization) {
            return false;
        }

        if (self::hasRole($user, 'LSG Admin')) {
            return $user->organization->college_id === $event->organization->college_id;
        }

        return $event->organization_id === $user->organization_id;
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
}
