<?php

namespace App\Modules\Auth\Actions;

use App\Models\LeadStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Auth\DTOs\RegisterData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisions a brand-new tenant on self-signup. Everything happens in one DB
 * transaction so a half-created tenant can never exist.
 *
 * Runs with NO authenticated user, so tenant-owned models receive their
 * tenant_id explicitly (the BelongsToTenant force-fill only applies when a
 * tenant user is in context).
 */
class RegisterTenantAction
{
    /** Default pipeline seeded for every new tenant. */
    private const DEFAULT_LEAD_STATUSES = [
        ['name' => 'New', 'color' => '#3b82f6', 'is_default' => true],
        ['name' => 'Contacted', 'color' => '#8b5cf6'],
        ['name' => 'Qualified', 'color' => '#f59e0b'],
        ['name' => 'Won', 'color' => '#22c55e', 'is_won' => true],
        ['name' => 'Lost', 'color' => '#ef4444', 'is_lost' => true],
    ];

    public function execute(RegisterData $data): User
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data->companyName,
                'slug' => $this->uniqueSlug($data->companyName),
                'company_name' => $data->companyName,
                'email' => $data->email,
                'phone' => $data->phone,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $user = new User([
                'tenant_id' => $tenant->id,
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'status' => 'active',
            ]);
            $user->password = $data->password; // hashed via cast
            $user->save();

            // Provision the remaining records AS the new owner, so BelongsToTenant
            // stamps them with THIS tenant — never an ambient/previous auth context.
            auth('api')->setUser($user);

            $user->assignRole('tenant-owner');

            Wallet::create(['tenant_id' => $tenant->id, 'currency' => 'INR', 'balance_minor' => 0]);

            $this->seedLeadStatuses($tenant->id);
            $this->startTrialSubscription($tenant);

            return $user;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function seedLeadStatuses(int $tenantId): void
    {
        foreach (self::DEFAULT_LEAD_STATUSES as $i => $status) {
            LeadStatus::create(array_merge($status, [
                'tenant_id' => $tenantId,
                'sort_order' => $i + 1,
            ]));
        }
    }

    private function startTrialSubscription(Tenant $tenant): void
    {
        $plan = Plan::where('slug', 'starter')->first() ?? Plan::orderBy('sort_order')->first();

        if ($plan === null) {
            return; // no catalog seeded yet; billing can be attached later
        }

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays($plan->trial_days ?: 14),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($plan->trial_days ?: 14),
        ]);
    }
}
