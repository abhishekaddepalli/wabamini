<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;

class AdminUserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected AdminRole $adminRole;
    protected Tenant $tenant;
    protected Role $clientRole;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = AdminRole::create([
            'name' => 'Super Admin',
            'permissions' => ['*'],
        ]);

        $this->admin = Admin::create([
            'role_id' => $this->adminRole->id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_users_test@whatsomni.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Tenant Dev Workspace',
            'domain' => 'tenant-dev',
            'status' => 'active',
        ]);

        $this->clientRole = Role::create([
            'name' => 'owner',
            'display_name' => 'Owner',
            'permissions' => json_encode(['*']),
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@whatsomni.com',
            'password' => bcrypt('password123'),
            'role_id' => $this->clientRole->id,
            'status' => 'active',
        ]);
    }

    /**
     * Test admin can fetch users list index.
     */
    public function test_admin_can_fetch_users_index(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'users' => [
                    'data' => [
                        '*' => [
                            'id',
                            'first_name',
                            'last_name',
                            'email',
                            'role',
                            'tenant',
                        ]
                    ]
                ],
                'roles',
                'tenants'
            ]);
    }

    /**
     * Test admin can search users.
     */
    public function test_admin_can_search_users(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/users?search=john.doe');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('users.data'));

        $responseEmpty = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/users?search=NonExistentUser');

        $responseEmpty->assertStatus(200);
        $this->assertCount(0, $responseEmpty->json('users.data'));
    }

    /**
     * Test admin can create a new user.
     */
    public function test_admin_can_create_user(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/api/admin/users', [
                'tenant_id' => $this->tenant->id,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@whatsomni.com',
                'password' => 'password123',
                'role_id' => $this->clientRole->id,
                'status' => 'active',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.first_name', 'Jane')
            ->assertJsonPath('user.email', 'jane.smith@whatsomni.com');

        $this->assertDatabaseHas('tenant_users', [
            'email' => 'jane.smith@whatsomni.com',
            'first_name' => 'Jane',
        ]);
    }

    /**
     * Test admin can update user details.
     */
    public function test_admin_can_update_user(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/api/admin/users/{$this->user->id}", [
                'tenant_id' => $this->tenant->id,
                'first_name' => 'JohnUpdated',
                'last_name' => 'DoeUpdated',
                'email' => 'john.doe@whatsomni.com',
                'role_id' => $this->clientRole->id,
                'status' => 'suspended',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.first_name', 'JohnUpdated')
            ->assertJsonPath('user.status', 'suspended');

        $this->assertDatabaseHas('tenant_users', [
            'id' => $this->user->id,
            'first_name' => 'JohnUpdated',
            'status' => 'suspended',
        ]);
    }

    /**
     * Test admin can delete user.
     */
    public function test_admin_can_delete_user(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->deleteJson("/api/admin/users/{$this->user->id}");

        $response->assertStatus(200);
        
        // Assert soft delete
        $this->assertSoftDeleted('tenant_users', [
            'id' => $this->user->id
        ]);
    }
}
