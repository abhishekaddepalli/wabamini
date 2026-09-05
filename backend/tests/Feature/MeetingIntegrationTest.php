<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\BookingLink;
use App\Models\Appointment;
use App\Models\TenantMeetingToken;
use App\Models\TenantGoogleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Carbon\Carbon;

class MeetingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'ACME meetings']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Meeting',
            'email' => 'john.meet@acme.com',
            'password' => bcrypt('secret123')
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Client',
            'last_name' => 'Video',
            'email' => 'client.vid@example.com',
            'phone' => '+188888888',
        ]);
    }

    /** @test */
    public function can_get_meeting_status_and_auth_urls()
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/integrations/meetings/status');
        $response->assertStatus(200)
            ->assertJson([
                'zoom_connected' => false,
                'teams_connected' => false,
            ]);

        $zoomUrl = $this->getJson('/api/integrations/meetings/auth-url?provider=zoom');
        $zoomUrl->assertStatus(200);
        $this->assertStringContainsString('zoom.us/oauth/authorize', $zoomUrl->json('url'));

        $teamsUrl = $this->getJson('/api/integrations/meetings/auth-url?provider=teams');
        $teamsUrl->assertStatus(200);
        $this->assertStringContainsString('login.microsoftonline.com', $teamsUrl->json('url'));
    }

    /** @test */
    public function handles_zoom_callback_and_disconnects()
    {
        Http::fake([
            'https://zoom.us/oauth/token' => Http::response([
                'access_token' => 'zoom_access_test',
                'refresh_token' => 'zoom_refresh_test',
                'expires_in' => 3600,
            ], 200),
            'https://api.zoom.us/v2/users/me' => Http::response([
                'email' => 'zoom.user@example.com'
            ], 200),
        ]);

        $state = json_encode(['tenant_id' => $this->tenant->id, 'provider' => 'zoom']);

        $response = $this->get('/api/integrations/meetings/callback?code=mock_code&state=' . urlencode($state));
        $response->assertRedirect();
        $this->assertStringContainsString('meeting_zoom_success=true', $response->headers->get('Location'));

        $this->assertDatabaseHas('tenant_meeting_tokens', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'zoom',
            'email' => 'zoom.user@example.com',
        ]);

        // Disconnect
        $this->actingAs($this->user);
        $disconnect = $this->deleteJson('/api/integrations/meetings/disconnect?provider=zoom');
        $disconnect->assertStatus(200);

        $this->assertDatabaseMissing('tenant_meeting_tokens', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'zoom',
        ]);
    }

    /** @test */
    public function booking_link_creates_zoom_meeting()
    {
        // Setup Zoom Token
        TenantMeetingToken::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'zoom',
            'access_token' => 'zoom_access',
            'refresh_token' => 'zoom_refresh',
            'expires_at' => Carbon::now()->addHours(2),
            'email' => 'zoom.user@example.com',
        ]);

        $link = BookingLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => 'zoom-consultation',
            'name' => 'Zoom Consultation',
            'duration' => 30,
            'location_type' => 'zoom',
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '17:00']],
            ],
        ]);

        Http::fake([
            'https://api.zoom.us/v2/users/me/meetings' => Http::response([
                'join_url' => 'https://zoom.us/j/123456789'
            ], 200),
        ]);

        $payload = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+155555555',
            'start_time' => '2026-07-20T10:00:00',
        ];

        $response = $this->postJson('/api/public/booking-links/zoom-consultation/book', $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $this->tenant->id,
            'meeting_link' => 'https://zoom.us/j/123456789',
        ]);
    }

    /** @test */
    public function booking_link_creates_teams_meeting()
    {
        // Setup Teams Token
        TenantMeetingToken::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'teams',
            'access_token' => 'teams_access',
            'refresh_token' => 'teams_refresh',
            'expires_at' => Carbon::now()->addHours(2),
            'email' => 'teams.user@example.com',
        ]);

        $link = BookingLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => 'teams-sync',
            'name' => 'Teams Sync',
            'duration' => 30,
            'location_type' => 'teams',
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '17:00']],
            ],
        ]);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/onlineMeetings' => Http::response([
                'joinWebUrl' => 'https://teams.live.com/meet/987654321'
            ], 200),
        ]);

        $payload = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '+155555555',
            'start_time' => '2026-07-20T11:00:00',
        ];

        $response = $this->postJson('/api/public/booking-links/teams-sync/book', $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $this->tenant->id,
            'meeting_link' => 'https://teams.live.com/meet/987654321',
        ]);
    }
}
