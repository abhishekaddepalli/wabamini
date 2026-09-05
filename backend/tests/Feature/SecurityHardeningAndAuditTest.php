<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CrmIntegration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SecurityHardeningAndAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user role changes triggers audit logs.
     */
    public function test_user_role_change_triggers_audit_log(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Role Log Tenant',
            'status' => 'active',
        ]);

        $role1 = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'manager',
            'display_name' => 'Manager',
            'permissions' => ['view_conversations'],
        ]);

        $role2 = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'agent',
            'display_name' => 'Agent',
            'permissions' => ['view_conversations', 'reply_conversations'],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password123',
            'role_id' => $role1->id,
            'status' => 'active',
        ]);

        // Login as the user
        $this->actingAs($user, 'web');

        // Change role
        $user->update(['role_id' => $role2->id]);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role_change',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        $log = AuditLog::where('action', 'role_change')->first();
        $this->assertEquals($role1->id, $log->meta['old_role_id']);
        $this->assertEquals($role2->id, $log->meta['new_role_id']);
    }

    /**
     * Test plan override changes triggers audit logs.
     */
    public function test_plan_override_triggers_audit_log(): void
    {
        $plan1 = Plan::create([
            'name' => 'Trial Plan',
            'is_active' => true,
        ]);

        $plan2 = Plan::create([
            'name' => 'Pro Plan',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'company_name' => 'Plan Log Tenant',
            'status' => 'active',
            'plan_id' => $plan1->id,
        ]);

        $admin = Admin::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin@saas.com',
            'password' => 'secret_admin',
            'role_id' => 1,
        ]);

        $this->actingAs($admin, 'admin');

        // Update Tenant Plan (Plan override)
        $tenant->update(['plan_id' => $plan2->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'plan_override',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
        ]);

        $log = AuditLog::where('action', 'plan_override')->first();
        $this->assertEquals($plan1->id, $log->meta['old_plan_id']);
        $this->assertEquals($plan2->id, $log->meta['new_plan_id']);
    }

    /**
     * Test billing modifications triggers audit logs.
     */
    public function test_billing_change_triggers_audit_log(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Billing Log Tenant',
            'status' => 'active',
            'stripe_subscription_id' => 'sub_old',
        ]);

        // Update subscription ID
        $tenant->update([
            'stripe_subscription_id' => 'sub_new',
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing_change',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
        ]);

        $log = AuditLog::where('action', 'billing_change')->first();
        $this->assertEquals('active', $log->meta['old_status']);
        $this->assertEquals('suspended', $log->meta['new_status']);
        $this->assertEquals('sub_old', $log->meta['old_stripe_subscription_id']);
        $this->assertEquals('sub_new', $log->meta['new_stripe_subscription_id']);
    }

    /**
     * Test CRM Integration credentials encryption.
     */
    public function test_crm_integration_tokens_are_encrypted(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Encryption Tenant',
            'status' => 'active',
        ]);

        $rawAccessToken = 'super_secret_crm_access_token_123';
        $rawRefreshToken = 'super_secret_crm_refresh_token_xyz';

        $integration = CrmIntegration::create([
            'tenant_id' => $tenant->id,
            'provider' => 'hubspot',
            'access_token' => $rawAccessToken,
            'refresh_token' => $rawRefreshToken,
            'email' => 'crm@example.com',
            'field_mapping' => [],
            'metadata' => [],
            'sync_direction' => 'bidirectional',
        ]);

        // Verify database holds the encrypted value and NOT the raw value
        $dbRow = \Illuminate\Support\Facades\DB::table('crm_integrations')->where('id', $integration->id)->first();
        $this->assertNotEquals($rawAccessToken, $dbRow->access_token);
        $this->assertNotEquals($rawRefreshToken, $dbRow->refresh_token);

        // Verify decryption is transparent via Eloquent model
        $freshIntegration = CrmIntegration::find($integration->id);
        $this->assertEquals($rawAccessToken, $freshIntegration->access_token);
        $this->assertEquals($rawRefreshToken, $freshIntegration->refresh_token);
    }
}
