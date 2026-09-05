<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Test Corp',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.notifier@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
    }

    /** @test */
    public function can_list_tenant_notifications(): void
    {
        $this->actingAs($this->user);

        Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Test Notification',
            'body' => 'Welcome to the platform.',
            'type' => 'assignment',
        ]);

        $response = $this->getJson('/api/notifications');
        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    /** @test */
    public function can_mark_notifications_as_read(): void
    {
        $this->actingAs($this->user);

        $notification = Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Test Alert',
            'body' => 'Something happened.',
            'type' => 'mention',
        ]);

        $this->assertNull($notification->read_at);

        $response = $this->patchJson("/api/notifications/{$notification->id}/read");
        $response->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** @test */
    public function can_dismiss_notifications(): void
    {
        $this->actingAs($this->user);

        $notification = Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Dismissible Alert',
            'body' => 'You can clear this.',
            'type' => 'payment_failed',
        ]);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }
}
