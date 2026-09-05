<?php

namespace App\Services\Channels\Drivers;

use App\Contracts\ChannelProviderInterface;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Email;
use Exception;
use Illuminate\Support\Facades\Log;

class EmailDriver implements ChannelProviderInterface
{
    /**
     * Send outbound message via custom dynamic SMTP transport.
     */
    public function sendMessage(array $credentials, array $messageData): array
    {
        $provider = $credentials['provider'] ?? 'smtp';

        // Unique Message-ID for this email
        $msgId = Str::uuid() . '@whatsomni.io';

        // Extract subject and body
        $subject = 'Conversation Reply';
        $body = $messageData['body'] ?? '';

        if (preg_match('/^Subject:\s*(.*?)\n\n(.*)/s', str_replace("\r", "", $body), $matches)) {
            $subject = trim($matches[1]);
            $body = trim($matches[2]);
        }

        if ($provider === 'sandbox') {
            return [
                'external_message_id' => $msgId,
                'delivery_status' => 'sent',
                'error_message' => null,
            ];
        }

        try {
            $host = $credentials['smtp_host'] ?? 'localhost';
            $port = (int)($credentials['smtp_port'] ?? 587);
            $user = $credentials['smtp_username'] ?? '';
            $pass = $credentials['smtp_password'] ?? '';
            $encryption = $credentials['smtp_encryption'] ?? 'tls';

            // Symfony Mailer DSN construction
            $scheme = 'smtp';
            if ($encryption === 'ssl') {
                $scheme = 'smtps';
            }

            $dsn = "{$scheme}://" . urlencode($user) . ":" . urlencode($pass) . "@{$host}:{$port}";
            $transport = Transport::fromDsn($dsn);
            $mailer = new SymfonyMailer($transport);

            $email = (new Email())
                ->from($credentials['email_address'] ?? 'support@whatsomni.io')
                ->to($messageData['external_chat_id'])
                ->subject($subject)
                ->html(nl2br(e($body)));

            $email->getHeaders()->addIdHeader('Message-ID', $msgId);

            // Find parent conversation to inject threading headers
            $conversation = Conversation::where('external_chat_id', $messageData['external_chat_id'])
                ->whereHas('channelConnection', function ($q) {
                    $q->where('channel_type', 'email');
                })
                ->latest()
                ->first();

            if ($conversation) {
                // Look for last message to reply to
                $lastMsg = Message::where('conversation_id', $conversation->id)
                    ->whereNotNull('external_message_id')
                    ->latest()
                    ->first();

                if ($lastMsg && str_contains($lastMsg->external_message_id, '@')) {
                    $cleanLastMsgId = trim($lastMsg->external_message_id, '<>');
                    $email->getHeaders()->addIdHeader('In-Reply-To', $cleanLastMsgId);
                    $email->getHeaders()->addIdHeader('References', $cleanLastMsgId);
                }
            }

            $mailer->send($email);

            return [
                'external_message_id' => $msgId,
                'delivery_status' => 'sent',
                'error_message' => null,
            ];

        } catch (Exception $e) {
            Log::error("EmailDriver sending failed: " . $e->getMessage());
            return [
                'external_message_id' => null,
                'delivery_status' => 'failed',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify webhook signature (always returns true for standard email parsers).
     */
    public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
    {
        return true;
    }

    /**
     * Normalize incoming email webhooks.
     */
    public function normalizeInboundPayload(array $payload): array
    {
        // Extract headers or raw values
        $from = $payload['from'] ?? $payload['sender'] ?? 'customer@example.com';
        // Extract clean email address if format is "John Doe <john@doe.com>"
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        $subject = $payload['subject'] ?? 'No Subject';
        $text = $payload['text'] ?? $payload['html'] ?? $payload['body'] ?? '';

        $bodyText = "Subject: {$subject}\n\n{$text}";

        return [
            'external_chat_id' => (string)trim($from),
            'sender_identifier' => $payload['sender_name'] ?? $payload['sender_identifier'] ?? null,
            'external_message_id' => (string)($payload['message_id'] ?? $payload['Message-ID'] ?? '<inbound-' . uniqid() . '@whatsomni.io>'),
            'message_type' => 'text',
            'body' => $bodyText,
            'media_url' => null,
            'in_reply_to' => $payload['in_reply_to'] ?? $payload['In-Reply-To'] ?? null,
        ];
    }

    /**
     * Normalize status payload updates.
     */
    public function normalizeStatusPayload(array $payload): array
    {
        return [
            'external_message_id' => (string)($payload['external_message_id'] ?? $payload['message_id'] ?? ''),
            'delivery_status' => $payload['delivery_status'] ?? 'delivered',
            'error_message' => $payload['error_message'] ?? null,
        ];
    }
}
