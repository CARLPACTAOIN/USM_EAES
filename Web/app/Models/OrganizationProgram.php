<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class OrganizationProgram extends Pivot
{
    private const DEPARTMENT_BACKED_TYPES = [
        'society',
        'academic_unit',
    ];

    protected $table = 'organization_programs';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'program_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (OrganizationProgram $pivot): void {
            self::assertCanLink((string) $pivot->organization_id, (string) $pivot->program_id);
        });
    }

    public static function assertCanLink(string $organizationId, string $programId): void
    {
        $organization = Organization::query()->find($organizationId);
        $program = Program::query()->find($programId);

        if (! $organization || ! $program) {
            return;
        }

        if (! in_array($organization->type, self::DEPARTMENT_BACKED_TYPES, true)) {
            return;
        }

        if (! $organization->department_id) {
            return;
        }

        self::assertProgramsBelongToDepartment($organization->department_id, [$program->id]);
    }

    /**
     * @param  array<int, string>  $programIds
     */
    public static function assertProgramsBelongToDepartment(string $departmentId, array $programIds): void
    {
        $programCount = Program::query()
            ->whereIn('id', $programIds)
            ->where('department_id', $departmentId)
            ->count();

        if ($programCount !== count($programIds)) {
            throw ValidationException::withMessages([
                'program_ids' => 'All covered programs must belong to the organization department.',
            ]);
        }
    }
}
