<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Contact;
use App\Models\BookingLink;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['company_name' => 'ACME Corporation']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@acme.com',
            'password' => bcrypt('secret123')
        ]);
    }

    /** @test */
    public function can_create_and_list_booking_links()
    {
        $this->actingAs($this->user);

        $payload = [
            'name' => 'Discovery Consultation',
            'slug' => 'discovery-call',
            'duration' => 30,
            'buffer_before' => 10,
            'buffer_after' => 10,
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '17:00']],
                'tuesday' => [['start' => '09:00', 'end' => '17:00']],
            ]
        ];

        $response = $this->postJson('/api/booking-links', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('booking_link.name', 'Discovery Consultation');

        $listResponse = $this->getJson('/api/booking-links');
        $listResponse->assertStatus(200);
        $listResponse->assertJsonCount(1, 'booking_links');
    }

    /** @test */
    public function can_check_public_available_time_slots()
    {
        $link = BookingLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => 'demo-call',
            'name' => 'Demo Call',
            'duration' => 30,
            'buffer_before' => 15,
            'buffer_after' => 15,
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '11:00']], // 2 hours
            ]
        ]);

        // Monday 2026-07-20
        $response = $this->getJson('/api/public/booking-links/demo-call?date=2026-07-20');
        $response->assertStatus(200);

        // Standard slots generated (09:00, 09:30, 10:00, 10:30)
        // Wait, 10:30 slot ends at 11:00, so it is valid.
        $response->assertJsonCount(4, 'slots');
    }

    /** @test */
    public function public_booking_conflict_prevention_and_contact_matching()
    {
        $link = BookingLink::create([
            'tenant_id' => $this->tenant->id,
            'slug' => 'quick-sync',
            'name' => 'Quick Sync',
            'duration' => 30,
            'buffer_before' => 15,
            'buffer_after' => 15,
            'working_hours' => [
                'monday' => [['start' => '09:00', 'end' => '12:00']],
            ]
        ]);

        $existingContact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Existing',
            'last_name' => 'User',
            'email' => 'client@acme.com',
            'phone' => '+155555555'
        ]);

        // Book slot at 10:00
        $bookingTime = '2026-07-20T10:00:00';
        $payload = [
            'first_name' => 'Existing',
            'last_name' => 'User',
            'email' => 'client@acme.com',
            'phone' => '+155555555',
            'start_time' => $bookingTime,
            'notes' => 'Looking forward!'
        ];

        $response = $this->postJson('/api/public/booking-links/quick-sync/book', $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $this->tenant->id,
            'contact_id' => $existingContact->id,
            'notes' => 'Looking forward!'
        ]);

        // Try booking overlapping slot (10:15) - conflicts with 10:00 booking due to duration & buffer
        $conflictPayload = [
            'first_name' => 'Other',
            'last_name' => 'User',
            'email' => 'other@example.com',
            'phone' => '+199999999',
            'start_time' => '2026-07-20T10:15:00',
        ];

        $conflictResponse = $this->postJson('/api/public/booking-links/quick-sync/book', $conflictPayload);
        $conflictResponse->assertStatus(422);
    }
}
