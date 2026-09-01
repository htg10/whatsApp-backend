<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Auth\Actions\RegisterTenantAction;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform (super-admin) management of companies. A "company" is a tenant plus
 * its owner account. Only super admins may use these endpoints.
 */
class CompanyController extends Controller
{
    public function __construct(private readonly RegisterTenantAction $register) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenants = Tenant::query()
            ->withCount(['users'])
            ->orderByDesc('created_at')
            ->get();

        return $this->ok([
            'companies' => $tenants->map(function (Tenant $t) {
                $owner = User::where('tenant_id', $t->id)->orderBy('id')->first();
                return [
                    'id' => $t->uuid ?? (string) $t->id,
                    'name' => $t->company_name ?? $t->name,
                    'status' => $t->status,
                    'users_count' => $t->users_count,
                    'owner_name' => $owner?->name,
                    'owner_email' => $owner?->email,
                    'trial_ends_at' => $t->trial_ends_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        // Preserve the super admin's auth context — the action logs in as the new
        // owner while provisioning tenant records.
        $superAdmin = $request->user();

        $owner = $this->register->execute(new RegisterData(
            companyName: $data['company_name'],
            name: $data['owner_name'],
            email: $data['owner_email'],
            password: $data['password'],
            phone: $data['phone'] ?? null,
        ));

        auth('api')->setUser($superAdmin);

        $tenant = $owner->tenant;

        return $this->ok([
            'company' => [
                'id' => $tenant->uuid ?? (string) $tenant->id,
                'name' => $tenant->company_name ?? $tenant->name,
                'status' => $tenant->status,
                'owner_name' => $owner->name,
                'owner_email' => $owner->email,
                'users_count' => 1,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'created_at' => $tenant->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant = $this->findTenant($uuid);
        $suspend = $tenant->status !== 'suspended';

        $tenant->update([
            'status' => $suspend ? 'suspended' : 'active',
            'suspended_at' => $suspend ? now() : null,
        ]);

        return $this->ok(['message' => $suspend ? 'Company suspended.' : 'Company reactivated.', 'status' => $tenant->status]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $tenant = $this->findTenant($uuid);
        $tenant->delete();

        return $this->ok(['message' => 'Company deleted.']);
    }

    private function findTenant(string $uuid): Tenant
    {
        return Tenant::where('uuid', $uuid)->firstOrFail();
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Super admin only.');
    }
}
