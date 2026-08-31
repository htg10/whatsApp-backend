<?php

namespace App\Modules\Team\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Company team management — the workspace admin lists, invites, re-roles, and
 * deactivates users within their own tenant. Platform/super-admin users are
 * never exposed or editable here.
 */
class TeamController extends Controller
{
    /** Roles a company admin may assign (never super-admin). */
    private const ASSIGNABLE = ['tenant-owner', 'manager', 'agent'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('team.view');

        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get();

        return $this->ok([
            'members' => $users->map(fn (User $u) => $this->memberArray($u)),
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('team.invite');

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where(fn ($q) => $q->where('tenant_id', $tenantId))],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(self::ASSIGNABLE)],
        ]);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($data['role']);

        return $this->ok(['member' => $this->memberArray($user)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.update');

        $user = $this->findMember($request, $uuid);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', 'string', Rule::in(self::ASSIGNABLE)],
        ]);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $this->ok(['member' => $this->memberArray($user->fresh())]);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.update');

        $user = $this->findMember($request, $uuid);

        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot deactivate your own account.', [], 422);
        }

        $user->update(['status' => $user->status === 'active' ? 'suspended' : 'active']);

        return $this->ok(['member' => $this->memberArray($user->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.remove');

        $user = $this->findMember($request, $uuid);

        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot remove your own account.', [], 422);
        }

        $user->delete();

        return $this->ok(['message' => 'Team member removed.']);
    }

    // ---- helpers ----

    private function findMember(Request $request, string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->firstOrFail();
    }

    private function memberArray(User $u): array
    {
        return [
            'id' => $u->uuid,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'role' => $u->getRoleNames()->first(),
            'roles' => $u->getRoleNames()->values(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }

    /** @return array<int,array{value:string,label:string}> */
    private function assignableRoles(): array
    {
        $labels = ['tenant-owner' => 'Owner', 'manager' => 'Manager', 'agent' => 'Agent'];

        return collect(self::ASSIGNABLE)
            ->filter(fn ($r) => Role::where('name', $r)->where('guard_name', 'api')->exists())
            ->map(fn ($r) => ['value' => $r, 'label' => $labels[$r] ?? $r])
            ->values()
            ->all();
    }
}
