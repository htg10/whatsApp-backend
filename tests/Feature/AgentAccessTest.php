<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agent has the granular conversations.* / templates.view / analytics.view
 * permissions but NOT whatsapp.view / whatsapp.manage. These endpoints must
 * gate on the granular permissions the agent actually holds.
 */
class AgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();

        $owner = User::withoutGlobalScopes()->where('email', 'owner@acme.test')->first();
        Subscription::create([
            'tenant_id' => $owner->tenant_id,
            'plan_id' => Plan::where('slug', 'enterprise')->value('id'),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $agent = User::factory()->forTenant($owner->tenant)->create();
        $agent->assignRole('agent');
        $this->agentToken = auth('api')->login($agent);
    }

    private function agent(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->agentToken}");
    }

    public function test_agent_can_open_the_inbox(): void
    {
        $this->agent()->getJson('/api/v1/whatsapp/conversations')
            ->assertOk()
            ->assertJsonStructure(['data' => ['conversations', 'meta']]);
    }

    public function test_agent_can_view_templates_and_analytics(): void
    {
        $this->agent()->getJson('/api/v1/templates')->assertOk();
        $this->agent()->getJson('/api/v1/analytics/dashboard')->assertOk();
    }

    public function test_agent_cannot_create_a_template(): void
    {
        $this->agent()->postJson('/api/v1/templates', [
            'name' => 'promo_offer', 'language' => 'en_US', 'category' => 'MARKETING',
            'body' => 'Hello',
        ])->assertStatus(403);
    }
}
