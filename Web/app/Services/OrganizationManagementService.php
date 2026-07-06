<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationManagementService
{
    public const ORGANIZATION_TYPES = [
        'society',
        'usg',
        'lsg',
        'non_academic_org',
        'academic_unit',
        'administrative_office',
        'hr_unit',
    ];

    public function create(array $data, User $actor): Organization
    {
        $this->assertValidPayload($data);

        return DB::transaction(function () use ($data, $actor) {
            $organization = Organization::create([
                'college_id' => in_array($data['type'], ['usg', 'administrative_office', 'hr_unit'], true)
                    ? null
                    : ($data['college_id'] ?? null),
                'name' => $data['name'],
                'acronym' => strtoupper($data['acronym']),
                'type' => $data['type'],
                'logo_path' => $this->storeLogo($data['logo'] ?? null),
                'status' => $data['status'] ?? 'active',
            ]);

            if (in_array($organization->type, ['society', 'non_academic_org'], true) && !empty($data['program_ids'])) {
                $organization->programs()->sync($data['program_ids']);
            }

            AuditLog::create([
                'target_id' => $organization->id,
                'admin_id' => $actor->id,
                'action' => 'create-organization',
                'details' => [
                    'name' => $organization->name,
                    'acronym' => $organization->acronym,
                    'type' => $organization->type,
                    'college_id' => $organization->college_id,
                ],
            ]);

            return $organization;
        });
    }

    public function update(Organization $organization, array $data, User $actor): Organization
    {
        if (!in_array($organization->type, self::ORGANIZATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'organization' => 'This organization type cannot be managed through Admin Access.',
            ]);
        }

        $this->assertValidPayload(array_merge($data, [
            'type' => $organization->type,
            'college_id' => $data['college_id'] ?? $organization->college_id,
        ]), $organization);

        return DB::transaction(function () use ($organization, $data, $actor) {
            $organization->update([
                'name' => $data['name'],
                'acronym' => strtoupper($data['acronym']),
                'status' => $data['status'] ?? $organization->status,
                'logo_path' => $this->storeLogo($data['logo'] ?? null) ?: $organization->logo_path,
            ]);

            if (in_array($organization->type, ['society', 'non_academic_org'], true) && array_key_exists('program_ids', $data)) {
                $organization->programs()->sync($data['program_ids'] ?? []);
            }

            AuditLog::create([
                'target_id' => $organization->id,
                'admin_id' => $actor->id,
                'action' => 'update-organization',
                'details' => [
                    'name' => $organization->name,
                    'acronym' => $organization->acronym,
                    'type' => $organization->type,
                    'status' => $organization->status,
                ],
            ]);

            return $organization->fresh(['college', 'programs']);
        });
    }

    private function assertValidPayload(array $data, ?Organization $existing = null): void
    {
        $type = $data['type'] ?? null;

        if (!in_array($type, self::ORGANIZATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Select a recognized organization type.',
            ]);
        }

        if (in_array($type, ['society', 'lsg', 'academic_unit'], true) && empty($data['college_id'])) {
            throw ValidationException::withMessages([
                'college_id' => 'College is required for society, LSG, and academic unit organizations.',
            ]);
        }

        if (in_array($type, ['usg', 'administrative_office', 'hr_unit'], true) && !empty($data['college_id'])) {
            throw ValidationException::withMessages([
                'college_id' => 'University-wide offices cannot be tied to a college.',
            ]);
        }

        $acronym = strtoupper($data['acronym'] ?? '');
        $collegeId = in_array($type, ['usg', 'administrative_office', 'hr_unit'], true) ? null : ($data['college_id'] ?? null);

        $duplicate = Organization::query()
            ->where('type', $type)
            ->where('acronym', $acronym)
            ->when(
                $collegeId,
                fn ($query) => $query->where('college_id', $collegeId),
                fn ($query) => $query->whereNull('college_id')
            )
            ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'acronym' => 'An organization with this acronym already exists for the selected scope.',
            ]);
        }

        if (in_array($type, ['society', 'non_academic_org'], true) && !empty($data['program_ids'])) {
            $validProgramCount = Program::query()
                ->whereIn('id', $data['program_ids'])
                ->when($collegeId, fn ($query) => $query->where('college_id', $collegeId))
                ->count();

            if ($validProgramCount !== count($data['program_ids'])) {
                throw ValidationException::withMessages([
                    'program_ids' => 'All covered programs must belong to the selected college.',
                ]);
            }
        }
    }

    private function storeLogo(?UploadedFile $logo): ?string
    {
        if (!$logo) {
            return null;
        }

        return $logo->store('organizations/logos', 'public');
    }
}
