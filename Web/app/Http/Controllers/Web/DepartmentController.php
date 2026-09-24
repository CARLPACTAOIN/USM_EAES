<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Services\DepartmentManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOsa($request);

        $departments = Department::with('college')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => $this->serializeDepartment($department));

        return response()->json([
            'departments' => $departments,
        ]);
    }

    public function store(StoreDepartmentRequest $request, DepartmentManagementService $service): JsonResponse
    {
        $department = $service->create($request->validated(), $request->user());

        return response()->json([
            'department' => $this->serializeDepartment($department),
        ], 201);
    }

    public function update(
        UpdateDepartmentRequest $request,
        Department $department,
        DepartmentManagementService $service
    ): JsonResponse {
        $department = $service->update($department, $request->validated(), $request->user());

        return response()->json([
            'department' => $this->serializeDepartment($department),
        ]);
    }

    private function authorizeOsa(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Super Admin (OSA)'), 403);
    }

    private function serializeDepartment(Department $department): array
    {
        $department->loadMissing('college');

        return [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'college_id' => $department->college_id,
            'college' => [
                'id' => $department->college?->id,
                'name' => $department->college?->name,
                'code' => $department->college?->code,
            ],
        ];
    }
}
