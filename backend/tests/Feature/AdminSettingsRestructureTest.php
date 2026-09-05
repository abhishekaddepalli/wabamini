<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\SaasAdminInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsRestructureTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::where('email', 'admin@whatsomni.com')->first();
        if (!$this->admin) {
            $this->role = AdminRole::firstOrCreate([
                'name' => 'super_admin',
            ], [
                'display_name' => 'Super Administrator',
                'permissions' => ['*'],
            ]);

            $this->admin = Admin::create([
                'role_id' => $this->role->id,
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'admin@whatsomni.com',
                'password' => bcrypt('password123'),
                'status' => 'active',
            ]);
        } else {
            $this->role = $this->admin->role;
            if (!$this->role) {
                $this->role = AdminRole::firstOrCreate([
                    'name' => 'super_admin',
                ], [
                    'display_name' => 'Super Administrator',
                    'permissions' => ['*'],
                ]);
                $this->admin->update(['role_id' => $this->role->id]);
            }
        }
    }

    /**
     * Test admin can fetch and update general mailer settings.
     */
    public function test_admin_can_fetch_and_update_settings(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure(['settings']);

        $updateResponse = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/settings', [
                'settings' => [
                    'branding_name' => 'FlowSuperBranding',
                ]
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('settings.branding_name', 'FlowSuperBranding');
    }

    /**
     * Test admin can manage administrative team members and invitations.
     */
    public function test_admin_can_manage_team_and_invitations(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/settings/team');

        $response->assertStatus(200)
            ->assertJsonStructure(['members', 'invitations', 'roles']);

        // Invite a new admin
        $inviteResponse = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/settings/team/invite', [
                'email' => 'new.assistant@whatsomni.com',
                'role_id' => $this->role->id,
            ]);

        $inviteResponse->assertStatus(200)
            ->assertJsonPath('invitation.email', 'new.assistant@whatsomni.com');

        $invitation = SaasAdminInvitation::first();
        $this->assertNotNull($invitation);

        // Revoke the invitation
        $revokeResponse = $this->actingAs($this->admin, 'admin')
            ->deleteJson("/api/admin/settings/team/invite/{$invitation->id}");

        $revokeResponse->assertStatus(200);
        $this->assertEquals(0, SaasAdminInvitation::count());
    }
}
