<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Admins edit the permissions of the default roles and build custom roles from permission
 * keys. The admin role itself is locked so nobody can lock everyone out.
 */
class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        return RoleResource::collection(Role::query()->with('permissions')->orderBy('id')->get());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);

        $role = DB::transaction(function () use ($request): Role {
            $role = Role::query()->create(['name' => $request->validated('name'), 'guard_name' => 'web']);
            $role->syncPermissions($request->validated('permissions'));

            return $role;
        });

        $this->audit->record('role.created', $role, ['after' => ['name' => $role->name, 'permissions' => $request->validated('permissions')]]);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        Gate::authorize('update', $role);

        if ($role->name === RoleName::Admin->value) {
            throw new ApiException('The admin role cannot be changed.', 'protected_role');
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $after = $request->validated('permissions');
        sort($after);

        $role->syncPermissions($after);

        $this->audit->record('role.updated', $role, [
            'before' => ['permissions' => $before],
            'after' => ['permissions' => $after],
        ]);

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Role $role): Response
    {
        Gate::authorize('delete', $role);

        if (in_array($role->name, RoleName::values(), true)) {
            throw new ApiException('Default roles cannot be deleted.', 'protected_role');
        }

        if ($role->users()->exists()) {
            throw new ApiException('Remove this role from all users before deleting it.', 'role_in_use');
        }

        $this->audit->record('role.deleted', $role, ['before' => ['name' => $role->name]]);
        $role->delete();

        return response()->noContent();
    }
}
