<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Mail\EmailVerificationCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SensitiveEmailUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test full two-step email updates flow.
     */
    public function test_two_step_sensitive_email_update_flow(): void
    {
        Mail::fake();

        $tenant = Tenant::create([
            'company_name' => 'Email Step Verification Tenant',
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
            'first_name' => 'Joe',
            'last_name' => 'Tester',
            'email' => 'current@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        // Step 1: Request code to current email
        $response1 = $this->postJson('/api/auth/profile/email/send-current-code');
        $response1->assertStatus(200);

        $currentCode = Cache::get('email_update_current_code_' . $user->id);
        $this->assertNotNull($currentCode);

        Mail::assertSent(EmailVerificationCodeMail::class, function ($mail) use ($user, $currentCode) {
            return $mail->hasTo($user->email) && $mail->code === $currentCode && $mail->type === 'current';
        });

        // Step 2: Verify current email code
        $response2 = $this->postJson('/api/auth/profile/email/verify-current-code', [
            'code' => $currentCode,
        ]);
        $response2->assertStatus(200);
        $this->assertTrue(Cache::get('email_update_current_verified_' . $user->id));

        // Step 3: Request verification code to proposed new email address
        $newEmailAddress = 'proposed_new@example.com';
        $response3 = $this->postJson('/api/auth/profile/email/send-new-code', [
            'new_email' => $newEmailAddress,
        ]);
        $response3->assertStatus(200);

        $newCode = Cache::get('email_update_new_code_' . $user->id);
        $this->assertNotNull($newCode);
        $this->assertEquals($newEmailAddress, Cache::get('email_update_new_email_' . $user->id));

        Mail::assertSent(EmailVerificationCodeMail::class, function ($mail) use ($newEmailAddress, $newCode) {
            return $mail->hasTo($newEmailAddress) && $mail->code === $newCode && $mail->type === 'new';
        });

        // Step 4: Confirm new email updates
        $response4 = $this->postJson('/api/auth/profile/email/confirm-update', [
            'code' => $newCode,
        ]);
        $response4->assertStatus(200);

        $user->refresh();
        $this->assertEquals($newEmailAddress, $user->email);

        // Verify clean cache states
        $this->assertNull(Cache::get('email_update_current_verified_' . $user->id));
        $this->assertNull(Cache::get('email_update_new_code_' . $user->id));
        $this->assertNull(Cache::get('email_update_new_email_' . $user->id));
    }
}
