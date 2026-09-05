<?php

namespace App\Services\Channels\Drivers;

use App\Contracts\ChannelProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FacebookMessengerDriver implements ChannelProviderInterface
{
    /**
     * Send outbound message via Meta's Graph API for Messenger.
     */
    public function sendMessage(array $credentials, array $messageData): array
    {
        $pageAccessToken = $credentials['page_access_token'] ?? null;
        $pageId = $credentials['page_id'] ?? null;

        if (!$pageAccessToken || !$pageId) {
            throw new Exception("Facebook Messenger configuration keys (page_access_token, page_id) are missing.");
        }

        if ($pageAccessToken === 'mock_token') {
            return [
                'external_message_id' => 'mock_fb_msg_' . uniqid(),
                'delivery_status' => 'sent',
                'error_message' => null,
            ];
        }

        $recipientId = $messageData['external_chat_id'];
        $body = $messageData['body'] ?? '';


        $url = "https://graph.facebook.com/v20.0/me/messages";

        $payload = [
            'recipient' => [
                'id' => $recipientId,
            ],
            'message' => [
                'text' => $body,
            ],
        ];

        try {
            $response = Http::withToken($pageAccessToken)
                ->timeout(10)
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error("Facebook Messenger API returned error: " . $response->body());
                return [
                    'external_message_id' => null,
                    'delivery_status' => 'failed',
                    'error_message' => $response->json('error.message') ?? 'Unknown Meta API error.',
                ];
            }

            return [
                'external_message_id' => $response->json('message_id') ?? 'fb_msg_' . uniqid(),
                'delivery_status' => 'sent',
                'error_message' => null,
            ];
        } catch (Exception $e) {
            Log::error("Facebook Messenger sendMessage failed: " . $e->getMessage());
            return [
                'external_message_id' => null,
                'delivery_status' => 'failed',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify authenticity of webhook signature.
     */
    public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
    {

        // Meta webhooks sign using App Secret
        $appSecret = config('services.facebook.client_secret');
        if (empty($appSecret)) {
            if (app()->environment('production')) {
                Log::error("Facebook Messenger webhook verification failed: FACEBOOK_CLIENT_SECRET is not configured in production.");
                return false;
            }
            Log::warning("Facebook Messenger webhook verification skipped in local environment: FACEBOOK_CLIENT_SECRET is empty.");
            return true;
        }

        $hubSig = $headers['x-hub-signature-256'] ?? $headers['X-Hub-Signature-256'] ?? '';
        if (empty($hubSig)) {
            return false;
        }

        $parts = explode('=', $hubSig);
        if (count($parts) !== 2 || $parts[0] !== 'sha256') {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($expectedSig, $parts[1]);
    }

    /**
     * Normalize incoming webhook payload into a unified internal schema.
     */
    public function normalizeInboundPayload(array $payload): array
    {

        // Check for direct flat schema (e.g. for mock testing or internal dispatchers)
        if (isset($payload['external_chat_id'])) {
            return [
                'external_chat_id' => (string)$payload['external_chat_id'],
                'sender_identifier' => $payload['sender_identifier'] ?? 'Facebook User',
                'external_message_id' => (string)($payload['external_message_id'] ?? 'fb_msg_' . uniqid()),
                'message_type' => $payload['message_type'] ?? 'text',
                'body' => $payload['body'] ?? '',
                'media_url' => $payload['media_url'] ?? null,
            ];
        }

        // Standard Meta Webhook format
        $messaging = $payload['entry'][0]['messaging'][0] ?? [];
        $senderId = $messaging['sender']['id'] ?? 'unknown_fb_user';
        $message = $messaging['message'] ?? [];
        $mid = $message['mid'] ?? 'fb_msg_' . uniqid();
        $text = $message['text'] ?? '';

        // Handle Quick Reply text payload if present
        if (isset($message['quick_reply']['payload'])) {
            $text = $message['quick_reply']['payload'];
        }
        
        $mediaUrl = null;
        $msgType = 'text';
        if (isset($message['attachments'][0])) {
            $attachment = $message['attachments'][0];
            $msgType = $attachment['type'] ?? 'text';
            $mediaUrl = $attachment['payload']['url'] ?? null;
        }

        return [
            'external_chat_id' => (string)$senderId,
            'sender_identifier' => 'Facebook User ' . substr($senderId, -4),
            'external_message_id' => (string)$mid,
            'message_type' => $msgType,
            'body' => $text,
            'media_url' => $mediaUrl,
        ];
    }

    /**
     * Normalize delivery status update webhook payload.
     */
    public function normalizeStatusPayload(array $payload): array
    {
        if (isset($payload['external_message_id'])) {
            return [
                'external_message_id' => (string)$payload['external_message_id'],
                'delivery_status' => $payload['delivery_status'] ?? 'delivered',
                'error_message' => $payload['error_message'] ?? null,
            ];
        }

        // Parse official Meta status payload if any
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'][0] ?? [];
        $mid = $statuses['id'] ?? '';
        $status = $statuses['status'] ?? 'delivered';

        return [
            'external_message_id' => (string)$mid,
            'delivery_status' => $status,
            'error_message' => $statuses['errors'][0]['message'] ?? null,
        ];
    }
}
