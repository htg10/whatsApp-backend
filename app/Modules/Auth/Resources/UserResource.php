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
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
