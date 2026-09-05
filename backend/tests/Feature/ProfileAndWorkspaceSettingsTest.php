<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileAndWorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test a user can update their personal profile.
     */
    public function test_user_can_update_profile_name_email_and_password(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'General Settings Tenant',
            'status' => 'active',
        ]);

        $role = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'agent',
            'display_name' => 'Agent',
            'permissions' => [],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'oldpassword123',
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        $response = $this->patchJson('/api/auth/profile', [
            'first_name' => 'JaneNew',
            'last_name' => 'DoeNew',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('JaneNew', $user->first_name);
        $this->assertEquals('DoeNew', $user->last_name);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    /**
     * Test workspace owner can update workspace settings.
     */
    public function test_workspace_owner_can_update_workspace_settings(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Old Workspace Name',
            'status' => 'active',
        ]);

        $roleOwner = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'owner',
            'display_name' => 'Owner',
            'permissions' => [],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'role_id' => $roleOwner->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        $response = $this->patchJson('/api/auth/workspace', [
            'company_name' => 'Brand New Company Ltd',
            'team_size' => '10-50',
            'industry_category' => 'Technology',
            'default_language' => 'en',
        ]);

        $response->assertStatus(200);

        $tenant->refresh();
        $this->assertEquals('Brand New Company Ltd', $tenant->company_name);
        $this->assertEquals('10-50', $tenant->team_size);
        $this->assertEquals('Technology', $tenant->industry_category);
        $this->assertEquals('en', $tenant->default_language);
    }

    /**
     * Test non-owners cannot update workspace settings.
     */
    public function test_member_cannot_update_workspace_settings(): void
    {
        $tenant = Tenant::create([
            'company_name' => 'Workspace Protected',
            'status' => 'active',
        ]);

        $roleAgent = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'agent',
            'display_name' => 'Agent',
            'permissions' => [],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Agent',
            'last_name' => 'User',
            'email' => 'agent@example.com',
            'password' => 'password123',
            'role_id' => $roleAgent->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        $response = $this->patchJson('/api/auth/workspace', [
            'company_name' => 'Hacker Ltd',
        ]);

        $response->assertStatus(403);
    }
}
