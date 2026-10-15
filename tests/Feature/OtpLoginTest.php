<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // A registered owner with a phone number.
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ]);
        User::withoutGlobalScopes()->where('email', 'owner@acme.test')
            ->update(['phone' => '+91 98765 43210']);
    }

    public function test_otp_login_succeeds_with_the_right_code(): void
    {
        // Request a code — in local/testing the code is returned for the test.
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])
            ->assertOk()
            ->json('data.dev_code');
        $this->assertNotNull($code);

        // Verify it and get a token.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+919876543210', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'owner@acme.test')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_in']]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => '000000'])
            ->assertStatus(422);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])->json('data.dev_code');

        LoginOtp::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_unknown_number_cannot_log_in_even_with_the_right_code(): void
    {
        // The request looks successful (no enumeration). In local/testing the code
        // is echoed for convenience, but it belongs to no account...
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000000'])
            ->assertOk()->json('data.dev_code');

        // ...so even the correct code can't log anyone in.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000000', 'code' => $code])
            ->assertStatus(422);
    }
}
