<?php

namespace Tests\Feature;

use App\Mail\AccountDeletionVerificationMail;
use App\Mail\EmailVerificationCodeMail;
use App\Mail\SupportTicketReplyMail;
use App\Mail\TeamInvitationMail;
use App\Mail\TestPlatformMail;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Tests\TestCase;

class MailRenderTest extends TestCase
{
    public function test_verify_email_notification_renders_html(): void
    {
        $user = new User([
            'first_name' => 'John',
            'email' => 'john@example.com',
        ]);
        $user->id = 1;

        $notification = new VerifyEmailNotification();
        $mailable = $notification->toMail($user);

        $html = $mailable->render();

        $this->assertStringContainsString('Verify your workspace', $html);
        $this->assertStringContainsString('Hello John', $html);
        $this->assertStringContainsString('Activate Account', $html);
    }

    public function test_reset_password_notification_renders_html(): void
    {
        $user = new User([
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
        ]);
        $user->id = 1;

        $notification = new ResetPasswordNotification('test-token-123');
        $mailable = $notification->toMail($user);

        $html = $mailable->render();

        $this->assertStringContainsString('Reset your password', $html);
        $this->assertStringContainsString('Hello Jane', $html);
        $this->assertStringContainsString('Reset Password', $html);
    }

    public function test_email_verification_code_mail_renders_html(): void
    {
        $mail = new EmailVerificationCodeMail('849201', 'current');
        $html = $mail->render();

        $this->assertStringContainsString('849201', $html);
        $this->assertStringContainsString('Identity Verification Code', $html);
    }

    public function test_account_deletion_verification_mail_renders_html(): void
    {
        $mail = new AccountDeletionVerificationMail('123456');
        $html = $mail->render();

        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('Confirm Account Deletion', $html);
    }

    public function test_team_invitation_mail_renders_html(): void
    {
        $mail = new TeamInvitationMail('Acme Corp', 'https://whatsomni.com/accept');
        $html = $mail->render();

        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('https://whatsomni.com/accept', $html);
    }

    public function test_support_ticket_reply_mail_renders_html(): void
    {
        $mail = new SupportTicketReplyMail(101, 'We have resolved your issue.', 'https://whatsomni.com/help/tickets/101');
        $html = $mail->render();

        $this->assertStringContainsString('#101', $html);
        $this->assertStringContainsString('We have resolved your issue.', $html);
    }

    public function test_test_platform_mail_renders_html(): void
    {
        $mail = new TestPlatformMail('WhatsOmni Production');
        $html = $mail->render();

        $this->assertStringContainsString('WhatsOmni Production', $html);
        $this->assertStringContainsString('Platform Mailer Connection Test', $html);
    }
}
