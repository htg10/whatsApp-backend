<?php

namespace App\Modules\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe user shape. Exposes the uuid (never the internal id) and never
 * password / token / 2FA columns.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'is_super_admin' => (bool) $this->is_super_admin,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            // Enabled plan feature keys (social/chatbot/automations/reports/...).
            // null = no plan / super admin → no feature gating (all available).
            'plan_features' => (! $this->is_super_admin && $this->tenant_id)
                ? app(\App\Modules\Billing\Services\PlanLimitService::class)->planFeatures($this->tenant_id)
                : null,
            // Numeric plan limits (key => int, or null = unlimited). 0 = blocked.
            // null map for super admin / no tenant → no limit gating.
            'plan_limits' => (! $this->is_super_admin && $this->tenant_id)
                ? app(\App\Modules\Billing\Services\PlanLimitService::class)->limitsMap($this->tenant_id)
                : null,
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
