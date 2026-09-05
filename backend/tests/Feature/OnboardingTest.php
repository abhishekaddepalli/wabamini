<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant in step 1
        $this->tenant = Tenant::create([
            'company_name' => 'Pending Corp',
            'status' => 'trial',
            'onboarding_step' => '1',
        ]);

        // Create user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Max',
            'last_name' => 'Planck',
            'email' => 'max@planck.org',
            'password' => Hash::make('Planck123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Test Step 1 saves details and advances onboarding_step to '2'.
     */
    public function test_user_can_submit_onboarding_step_1(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/onboarding/step-1', [
            'company_name' => 'Planck Quantum Labs',
            'team_size' => '6-20',
            'industry_category' => 'Technology',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('tenant.onboarding_step', '2');

        $this->tenant->refresh();
        $this->assertEquals('Planck Quantum Labs', $this->tenant->company_name);
        $this->assertEquals('6-20', $this->tenant->team_size);
        $this->assertEquals('Technology', $this->tenant->industry_category);
        $this->assertEquals('2', $this->tenant->onboarding_step);
    }

    /**
     * Test Step 2 advances onboarding_step to '3'.
     */
    public function test_user_can_submit_onboarding_step_2(): void
    {
        $this->actingAs($this->user);
        $this->tenant->update(['onboarding_step' => '2']);

        $response = $this->postJson('/api/onboarding/step-2');

        $response->assertStatus(200)
            ->assertJsonPath('tenant.onboarding_step', '3');

        $this->tenant->refresh();
        $this->assertEquals('3', $this->tenant->onboarding_step);
    }

    /**
     * Test Step 3 completes onboarding.
     */
    public function test_user_can_complete_onboarding_step_3(): void
    {
        $this->actingAs($this->user);
        $this->tenant->update(['onboarding_step' => '3']);

        $response = $this->postJson('/api/onboarding/step-3');

        $response->assertStatus(200)
            ->assertJsonPath('tenant.onboarding_step', 'complete');

        $this->tenant->refresh();
        $this->assertEquals('complete', $this->tenant->onboarding_step);
    }

    /**
     * Test auth/me eager loads tenant details.
     */
    public function test_auth_me_returns_eager_loaded_tenant(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure(['user' => ['tenant']]);
    }
}
