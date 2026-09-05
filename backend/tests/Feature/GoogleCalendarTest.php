<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\BookingLink;
use App\Models\Appointment;
use App\Models\TenantGoogleToken;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;
use Mockery\MockInterface;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected BookingLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'ACME Corp']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@acme.com',
            'password' => bcrypt('secret123')
        ]);

        TenantGoogleToken::create([
            'tenant_id' => $this->tenant->id,
            'access_token' => 'mock_access',
            'refresh_token' => 'mock_refresh',
            'expires_at' => Carbon::now()->addHour(),
            'email' => 'jane@acme.com'
        ]);

        $this->link = BookingLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => 'google-call',
            'name' => 'Google Consultation',
            'duration' => 30,
            'buffer_before' => 15,
            'buffer_after' => 15,
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '12:00']],
            ]
        ]);
    }

    /** @test */
    public function slots_are_filtered_by_google_busy_intervals()
    {
        // Mock GoogleCalendarService to return a busy slot at 10:00 - 10:30
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkGoogleBusySlots')
                ->once()
                ->andReturn([
                    [
                        'start' => Carbon::parse('2026-07-20T10:00:00Z'),
                        'end' => Carbon::parse('2026-07-20T10:30:00Z'),
                    ]
                ]);
        });

        // Request slots for Monday 2026-07-20
        $response = $this->getJson('/api/public/booking-links/google-call?date=2026-07-20');
        $response->assertStatus(200);

        // Normally we have: 09:00, 09:30, 10:00, 10:30, 11:00, 11:30
        // Because of the busy slot at 10:00 to 10:30, and buffer limits:
        // - 09:30 slot ends at 10:00, which collides with busy start minus buffer_before? No, busy starts at 10:00, slot 9:30 ends at 10:00. Buffer before is 15 mins, so 10:00 - 15 mins = 09:45. Thus, 9:30 slot (ending at 10:00) overlaps!
        // - 10:00 slot is directly busy.
        // - 10:30 slot starts at 10:30, busy ends at 10:30. Buffer after is 15 mins, so 10:30 + 15 mins = 10:45. Thus 10:30 slot overlaps!
        // So the slots list must exclude those!
        $slots = $response->json('slots');
        
        // Assert that 10:00 is NOT in the list
        $this->assertNotContains('2026-07-20T10:00:00+00:00', $slots);
    }

    /** @test */
    public function public_booking_checks_google_conflict_and_fails_on_overlap()
    {
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkGoogleBusySlots')
                ->once()
                ->andReturn([
                    [
                        'start' => Carbon::parse('2026-07-20T10:00:00Z'),
                        'end' => Carbon::parse('2026-07-20T10:30:00Z'),
                    ]
                ]);
        });

        $payload = [
            'first_name' => 'Alice',
            'last_name' => 'Wond',
            'email' => 'alice@example.com',
            'phone' => '+133333333',
            'start_time' => '2026-07-20T10:15:00',
        ];

        $response = $this->postJson('/api/public/booking-links/google-call/book', $payload);
        $response->assertStatus(422);
    }

    /** @test */
    public function public_booking_succeeds_and_triggers_google_sync_when_slot_is_free()
    {
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkGoogleBusySlots')
                ->once()
                ->andReturn([]);
                
            $mock->shouldReceive('syncAppointment')
                ->once();
        });

        $successPayload = [
            'first_name' => 'Alice',
            'last_name' => 'Wond',
            'email' => 'alice@example.com',
            'phone' => '+133333333',
            'start_time' => '2026-07-20T09:00:00',
        ];

        $successResponse = $this->postJson('/api/public/booking-links/google-call/book', $successPayload);
        $successResponse->assertStatus(200);
    }

    /** @test */
    public function cancelling_an_appointment_deletes_google_calendar_event()
    {
        $this->actingAs($this->user);

        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Marley',
            'email' => 'bob@example.com',
            'phone' => '+1000'
        ]);

        $appointment = Appointment::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'booking_link_id' => $this->link->id,
            'start_time' => Carbon::now()->addDay(),
            'end_time' => Carbon::now()->addDay()->addMinutes(30),
            'status' => 'scheduled',
            'google_event_id' => 'mock_event_123'
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) use ($appointment) {
            $mock->shouldReceive('deleteEvent')
                ->once();
        });

        $response = $this->putJson("/api/appointments/{$appointment->id}/cancel");
        $response->assertStatus(200);
    }
}
