<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter', 'slug' => 'starter', 'price' => 0, 'trial_days' => 14, 'sort_order' => 1,
                'limits' => ['whatsapp_numbers' => 1, 'agents' => 2, 'contacts' => 1000, 'campaigns' => 2, 'workflows' => 1, 'storage_mb' => 512, 'api_access' => false],
            ],
            [
                'name' => 'Business', 'slug' => 'business', 'price' => 2999, 'trial_days' => 14, 'sort_order' => 2,
                'limits' => ['whatsapp_numbers' => 3, 'agents' => 10, 'contacts' => 50000, 'campaigns' => 50, 'workflows' => 20, 'storage_mb' => 10240, 'api_access' => true],
            ],
            [
                'name' => 'Enterprise', 'slug' => 'enterprise', 'price' => 9999, 'trial_days' => 0, 'sort_order' => 3,
                'limits' => ['whatsapp_numbers' => 25, 'agents' => 100, 'contacts' => 1000000, 'campaigns' => 1000, 'workflows' => 200, 'storage_mb' => 102400, 'api_access' => true],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], array_merge($plan, [
                'billing_period' => 'monthly',
                'currency' => 'INR',
                'is_active' => true,
                'is_public' => true,
            ]));
        }
    }
}
