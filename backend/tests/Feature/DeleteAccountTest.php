<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Mail\AccountDeletionVerificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_account_verification_code_generation_and_delivery()
    {
        Mail::fake();
        Cache::flush();

        $tenant = Tenant::create([
            'company_name' => 'Delete Test Corp',
            'onboarding_step' => 'complete',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.delete@example.com',
            'password' => bcrypt('Password123!'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/settings/delete-account/send-code');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Verification code dispatched to your email address.');

        $cachedCode = Cache::get('account_deletion_code_' . $user->id);
        $this->assertNotNull($cachedCode);
        $this->assertEquals(6, strlen($cachedCode));

        Mail::assertSent(AccountDeletionVerificationMail::class, function ($mail) use ($user, $cachedCode) {
            return $mail->hasTo($user->email) && $mail->code === $cachedCode;
        });
    }

    public function test_delete_account_confirmation_flow()
    {
        Cache::flush();

        $tenant = Tenant::create([
            'company_name' => 'Delete Test Corp',
            'onboarding_step' => 'complete',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.delete@example.com',
            'password' => bcrypt('Password123!'),
        ]);

        Cache::put('account_deletion_code_' . $user->id, '987654', now()->addMinutes(15));

        // Attempt confirm with invalid code
        $responseInvalid = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/settings/delete-account/confirm', [
                'code' => '000000',
            ]);

        $responseInvalid->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired verification code.');

        $this->assertDatabaseHas('tenant_users', ['id' => $user->id]);

        // Confirm with correct code
        $responseConfirm = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/settings/delete-account/confirm', [
                'code' => '987654',
            ]);

        $responseConfirm->assertStatus(200)
            ->assertJsonPath('message', 'Account permanently deleted successfully.');

        $this->assertSoftDeleted('tenant_users', ['id' => $user->id]);
    }
}
