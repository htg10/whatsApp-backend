<?php

namespace App\Modules\Billing\Services;

use App\Models\Contact;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Central plan-limit + feature engine. Limits and feature flags live in the
 * plan's `limits` / `features` JSON (no hard-coded numbers), so new limits and
 * features can be added by editing a plan — never by changing code.
 *
 * A limit that is absent, null, or < 0 means UNLIMITED.
 */
class PlanLimitService
{
    /** The tenant's current plan (via its latest subscription), or null. */
    public function planFor(int $tenantId): ?Plan
    {
        return Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->with('plan')
            ->first()?->plan;
    }

    /** Numeric limit for a key. null return = unlimited. */
    public function limit(int $tenantId, string $key): ?int
    {
        $plan = $this->planFor($tenantId);
        if (! $plan) {
            return null; // no plan → treat as unlimited so nothing breaks
        }
        $v = $plan->limit($key);
        if ($v === null || $v === '' || (int) $v < 0) {
            return null; // unlimited
        }
        return (int) $v;
    }

    /** Whether a plan feature flag is enabled. No plan → allowed by default. */
    public function feature(int $tenantId, string $key): bool
    {
        $plan = $this->planFor($tenantId);
        if (! $plan) {
            return true;
        }
        return (bool) data_get($plan->features, $key, false);
    }

    /** Current usage for a metric key. */
    public function usage(int $tenantId, string $key): int
    {
        return match ($key) {
            'agents' => User::where('tenant_id', $tenantId)
                ->where('is_super_admin', false)
                ->whereHas('roles', fn ($q) => $q->where('name', 'agent'))
                ->count(),
            'contacts' => Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'campaigns' => Campaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            default => 0,
        };
    }

    /**
     * Returns [allowed, limit, used, remaining]. limit === null means unlimited.
     */
    public function status(int $tenantId, string $limitKey, string $usageKey): array
    {
        $limit = $this->limit($tenantId, $limitKey);
        $used = $this->usage($tenantId, $usageKey);
        return [
            'allowed' => $limit === null || $used < $limit,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    /** Throw a friendly validation error if the tenant is at its agent limit. */
    public function assertCanAddAgent(int $tenantId): void
    {
        $limit = $this->limit($tenantId, 'max_agents');
        if ($limit === null) {
            return; // unlimited
        }
        $used = $this->usage($tenantId, 'agents');
        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'plan' => ["Your current plan allows a maximum of {$limit} agents. Please upgrade your plan to create more agents."],
            ]);
        }
    }

    /**
     * Feature keys the plan makes available — used to gate which permissions an
     * admin may grant to an agent (an agent can never exceed the plan).
     * Returns null when the plan has no features map (→ allow all).
     */
    public function planFeatures(int $tenantId): ?array
    {
        $plan = $this->planFor($tenantId);
        if (! $plan || empty($plan->features)) {
            return null;
        }
        return collect($plan->features)
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->all();
    }
}
