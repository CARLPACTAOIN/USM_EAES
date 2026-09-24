<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DepartmentManagementService
{
    public function create(array $data, User $actor): Department
    {
        return DB::transaction(function () use ($data, $actor) {
            $department = Department::create([
                'college_id' => $data['college_id'],
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'] ?? 'active',
            ]);

            AuditLog::create([
                'target_id' => $department->id,
                'admin_id' => $actor->id,
                'action' => 'create-department',
                'details' => [
                    'college_id' => $department->college_id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'status' => $department->status,
                ],
            ]);

            return $department->fresh('college');
        });
    }

    public function update(Department $department, array $data, User $actor): Department
    {
        return DB::transaction(function () use ($department, $data, $actor) {
            $department->update([
                'college_id' => $data['college_id'],
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'] ?? 'active',
            ]);

            AuditLog::create([
                'target_id' => $department->id,
                'admin_id' => $actor->id,
                'action' => 'update-department',
                'details' => [
                    'college_id' => $department->college_id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'status' => $department->status,
                ],
            ]);

            return $department->fresh('college');
        });
    }
}
