<?php

namespace App\Support;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Event;
use App\Models\Organization;

class AdminScope
{
    public function __construct(
        public readonly ?AdminAssignment $assignment,
        public readonly string $roleName,
        public readonly ?string $organizationId,
        public readonly ?string $collegeId,
        public readonly ?Organization $organization,
        public readonly ?College $college,
    ) {
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleName === 'Super Admin (OSA)';
    }

    public function isUsgAdmin(): bool
    {
        return $this->roleName === 'USG Admin';
    }

    public function isLsgAdmin(): bool
    {
        return $this->roleName === 'LSG Admin';
    }

    public function isAcademicUnitAdmin(): bool
    {
        return $this->roleName === 'Academic Unit Admin';
    }

    public function isOfficeEventAdmin(): bool
    {
        return $this->roleName === 'Office Event Admin';
    }

    public function isHrAdmin(): bool
    {
        return $this->roleName === 'HR Admin';
    }

    public function isOrganizationScoped(): bool
    {
        return filled($this->organizationId);
    }

    public function isCollegeScoped(): bool
    {
        return filled($this->collegeId);
    }

    public function canAccessOrganization(?Organization $organization): bool
    {
        if (!$organization) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isUsgAdmin()) {
            return $organization->type === 'usg' || $organization->id === $this->organizationId;
        }

        if ($this->isLsgAdmin()) {
            return filled($this->collegeId) && $organization->college_id === $this->collegeId;
        }

        return filled($this->organizationId) && $organization->id === $this->organizationId;
    }

    public function canAccessEvent(Event $event): bool
    {
        $event->loadMissing('organization');

        return $this->canAccessOrganization($event->organization);
    }
}
