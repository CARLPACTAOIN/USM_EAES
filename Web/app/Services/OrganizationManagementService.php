<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationProgram;
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

    private const UNIVERSITY_WIDE_TYPES = [
        'usg',
        'administrative_office',
        'hr_unit',
    ];

    private const COLLEGE_REQUIRED_TYPES = [
        'society',
        'lsg',
        'academic_unit',
    ];

    private const DEPARTMENT_BACKED_TYPES = [
        'society',
        'academic_unit',
    ];

    private const PROGRAM_LINKABLE_TYPES = [
        'society',
        'non_academic_org',
        'academic_unit',
    ];

    /**
     * @param  array{name: string, acronym: string, type: string, college_id?: string|null, department_id?: string|null, program_ids?: array<int, string>, logo?: UploadedFile|null, status?: string|null}  $data
     */
    public function create(array $data, User $actor): Organization
    {
        $data = $this->normalizePayload($data);

        $this->assertValidPayload($data);

        return DB::transaction(function () use ($data, $actor) {
            $organization = Organization::create([
                'college_id' => in_array($data['type'], self::UNIVERSITY_WIDE_TYPES, true)
                    ? null
                    : ($data['college_id'] ?? null),
                'department_id' => in_array($data['type'], self::DEPARTMENT_BACKED_TYPES, true)
                    ? ($data['department_id'] ?? null)
                    : null,
                'name' => $data['name'],
                'acronym' => strtoupper($data['acronym']),
                'type' => $data['type'],
                'logo_path' => $this->storeLogo($data['logo'] ?? null),
                'status' => $data['status'] ?? 'active',
            ]);

            if (in_array($organization->type, self::PROGRAM_LINKABLE_TYPES, true) && ! empty($data['program_ids'])) {
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
                    'department_id' => $organization->department_id,
                ],
            ]);

            return $organization;
        });
    }

    /**
     * @param  array{name: string, acronym: string, college_id?: string|null, department_id?: string|null, program_ids?: array<int, string>, logo?: UploadedFile|null, status?: string|null}  $data
     */
    public function update(Organization $organization, array $data, User $actor): Organization
    {
        if (! in_array($organization->type, self::ORGANIZATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'organization' => 'This organization type cannot be managed through Admin Access.',
            ]);
        }

        $data = $this->normalizePayload(array_merge($data, [
            'type' => $organization->type,
            'college_id' => $data['college_id'] ?? $organization->college_id,
            'department_id' => $data['department_id'] ?? $organization->department_id,
        ]), $organization);

        $this->assertValidPayload($data, $organization);

        return DB::transaction(function () use ($organization, $data, $actor) {
            $organization->update([
                'college_id' => in_array($organization->type, self::UNIVERSITY_WIDE_TYPES, true)
                    ? null
                    : ($data['college_id'] ?? null),
                'department_id' => in_array($organization->type, self::DEPARTMENT_BACKED_TYPES, true)
                    ? ($data['department_id'] ?? null)
                    : null,
                'name' => $data['name'],
                'acronym' => strtoupper($data['acronym']),
                'status' => $data['status'] ?? $organization->status,
                'logo_path' => $this->storeLogo($data['logo'] ?? null) ?: $organization->logo_path,
            ]);

            if (in_array($organization->type, self::PROGRAM_LINKABLE_TYPES, true) && array_key_exists('program_ids', $data)) {
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
                    'college_id' => $organization->college_id,
                    'department_id' => $organization->department_id,
                    'status' => $organization->status,
                ],
            ]);

            return $organization->fresh(['college', 'department', 'programs']);
        });
    }

    /**
     * @param  array{name?: string, acronym?: string, type?: string|null, college_id?: string|null, department_id?: string|null, program_ids?: array<int, string>, logo?: UploadedFile|null, status?: string|null}  $data
     * @return array{name?: string, acronym?: string, type?: string|null, college_id?: string|null, department_id?: string|null, program_ids?: array<int, string>, logo?: UploadedFile|null, status?: string|null}
     */
    private function normalizePayload(array $data, ?Organization $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type;

        if (! in_array($type, self::DEPARTMENT_BACKED_TYPES, true)) {
            return $data;
        }

        $departmentId = $data['department_id'] ?? $existing?->department_id;
        $programIds = array_values(array_filter($data['program_ids'] ?? []));

        if (! $departmentId && $programIds !== []) {
            $programDepartmentIds = Program::query()
                ->whereIn('id', $programIds)
                ->pluck('department_id')
                ->filter()
                ->unique()
                ->values();

            if ($programDepartmentIds->count() === 1) {
                $departmentId = $programDepartmentIds->first();
            }
        }

        if ($departmentId) {
            $department = Department::find($departmentId);

            if ($department) {
                $data['department_id'] = $department->id;
                $data['college_id'] = $department->college_id;
            }
        }

        return $data;
    }

    /**
     * @param  array{name?: string, acronym?: string, type?: string|null, college_id?: string|null, department_id?: string|null, program_ids?: array<int, string>, logo?: UploadedFile|null, status?: string|null}  $data
     */
    private function assertValidPayload(array $data, ?Organization $existing = null): void
    {
        $type = $data['type'] ?? null;

        if (! in_array($type, self::ORGANIZATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Select a recognized organization type.',
            ]);
        }

        if (in_array($type, self::COLLEGE_REQUIRED_TYPES, true) && empty($data['college_id'])) {
            throw ValidationException::withMessages([
                'college_id' => 'College is required for society, LSG, and academic unit organizations.',
            ]);
        }

        if (in_array($type, self::UNIVERSITY_WIDE_TYPES, true) && ! empty($data['college_id'])) {
            throw ValidationException::withMessages([
                'college_id' => 'University-wide offices cannot be tied to a college.',
            ]);
        }

        if (in_array($type, self::DEPARTMENT_BACKED_TYPES, true) && empty($data['department_id'])) {
            throw ValidationException::withMessages([
                'department_id' => 'Department is required for society and academic unit organizations.',
            ]);
        }

        if (! in_array($type, self::DEPARTMENT_BACKED_TYPES, true) && ! empty($data['department_id'])) {
            throw ValidationException::withMessages([
                'department_id' => 'Only society and academic unit organizations can be tied to a department.',
            ]);
        }

        if (! empty($data['department_id'])) {
            $department = Department::find($data['department_id']);

            if (! $department || $department->college_id !== ($data['college_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'department_id' => 'The selected department must belong to the selected college.',
                ]);
            }
        }

        $acronym = strtoupper($data['acronym'] ?? '');
        $collegeId = in_array($type, self::UNIVERSITY_WIDE_TYPES, true) ? null : ($data['college_id'] ?? null);

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

        if (in_array($type, self::PROGRAM_LINKABLE_TYPES, true) && ! empty($data['program_ids'])) {
            $programs = Program::query()
                ->whereIn('id', $data['program_ids'])
                ->when($collegeId, fn ($query) => $query->where('college_id', $collegeId))
                ->get(['id', 'department_id']);

            if ($programs->count() !== count($data['program_ids'])) {
                throw ValidationException::withMessages([
                    'program_ids' => 'All covered programs must belong to the selected college.',
                ]);
            }

            if (in_array($type, self::DEPARTMENT_BACKED_TYPES, true)) {
                OrganizationProgram::assertProgramsBelongToDepartment($data['department_id'], $programs->pluck('id')->all());
            }
        }
    }

    private function storeLogo(?UploadedFile $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        return $logo->store('organizations/logos', 'public');
    }
}
