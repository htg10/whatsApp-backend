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
 * Company team management. Two roles only:
 *   - Admin ("tenant-owner"): full access to everything, manages users.
 *   - User ("user"): sees ONLY the features the admin ticked when creating them.
 *
 * A User has no role-level permissions; the admin grants per-feature permissions
 * directly on the user, so the frontend nav (gated by user.permissions) shows
 * exactly the ticked features.
 */
class TeamController extends Controller
{
    private const ADMIN_ROLE = 'tenant-owner';
    private const USER_ROLE = 'user';

    /**
     * Fixed permission set every "User" gets — the day-to-day operational
     * features. Excludes admin-only areas (Team, Billing, WhatsApp setup).
     * All users have identical access; there is no per-user customisation.
     */
    private const USER_PERMS = [
        'conversations.view', 'conversations.reply', 'conversations.note', 'conversations.tag', 'conversations.status',
        'contacts.view', 'contacts.create', 'contacts.update',
        'campaigns.view', 'campaigns.create',
        'workflows.view', 'workflows.create',
        'bots.view', 'bots.create',
        'agents.view',
        'templates.view',
        'analytics.view',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('team.view');

        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get();

        return $this->ok([
            'members' => $users->map(fn (User $u) => $this->memberArray($u)),
            'roles' => [
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'user', 'label' => 'User'],
            ],
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
            'role' => ['required', 'string', Rule::in(['admin', 'user'])],
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

        $this->applyAccess($user, $data['role']);

        return $this->ok(['member' => $this->memberArray($user->fresh())], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('team.update');

        $user = $this->findMember($request, $uuid);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', 'string', Rule::in(['admin', 'user'])],
        ]);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }

        if (isset($data['role'])) {
            $this->applyAccess($user, $data['role']);
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

    // ---- access application ----

    /**
     * Apply the chosen role. Admins get everything via the owner role; Users get
     * the same fixed operational permission set, granted directly.
     */
    private function applyAccess(User $user, string $role): void
    {
        if ($role === 'admin') {
            Role::findOrCreate(self::ADMIN_ROLE, 'api');
            $user->syncRoles([self::ADMIN_ROLE]);
            $user->syncPermissions([]); // everything comes via the owner role
            return;
        }

        Role::findOrCreate(self::USER_ROLE, 'api'); // marker role, no base permissions
        $user->syncRoles([self::USER_ROLE]);
        $user->syncPermissions(self::USER_PERMS);   // identical access for every user
    }

    private function findMember(Request $request, string $uuid): User
    {
        return User::where('uuid', $uuid)
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('is_super_admin', false)
            ->firstOrFail();
    }

    /** "admin" if the user has the owner role, else "user". */
    private function roleKey(User $user): string
    {
        return $user->hasRole(self::ADMIN_ROLE) ? 'admin' : 'user';
    }

    private function memberArray(User $u): array
    {
        $roleKey = $this->roleKey($u);
        return [
            'id' => $u->uuid,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'role' => $roleKey,
            'role_label' => $roleKey === 'admin' ? 'Admin' : 'User',
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
