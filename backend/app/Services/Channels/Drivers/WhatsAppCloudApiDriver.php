<?php

namespace App\Services\Channels\Drivers;

use App\Contracts\ChannelProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppCloudApiDriver implements ChannelProviderInterface
{
    /**
     * Send outbound message via Meta's Graph API.
     */
    public function sendMessage(array $credentials, array $messageData): array
    {
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        $accessToken = $credentials['system_user_access_token'] ?? null;

        if (!$phoneNumberId || !$accessToken) {
            throw new Exception("WhatsApp Cloud API configuration keys (phone_number_id, system_user_access_token) are missing.");
        }

        $recipientPhone = preg_replace('/[^0-9]/', '', $messageData['external_chat_id']);
        $url = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipientPhone,
        ];

        $mediaUrl = $messageData['media_url'] ?? null;

        if ($mediaUrl) {
            $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);

            if ($isImage) {
                $payload['type'] = 'image';
                $payload['image'] = [
                    'link' => $mediaUrl,
                    'caption' => $messageData['body'] ?? ''
                ];
            } else {
                $payload['type'] = 'document';
                $payload['document'] = [
                    'link' => $mediaUrl,
                    'caption' => $messageData['body'] ?? '',
                    'filename' => basename(parse_url($mediaUrl, PHP_URL_PATH))
                ];
            }
        } else {
            // Format message format type
            if (isset($messageData['type']) && $messageData['type'] === 'template') {
                $payload['type'] = 'template';
                $payload['template'] = $messageData['template'];
            } else {
                $payload['type'] = 'text';
                $payload['text'] = [
                    'preview_url' => false,
                    'body' => $messageData['body'] ?? '',
                ];
            }
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if (!$response->successful()) {
                $errorData = $response->json();
                $errMessage = $errorData['error']['message'] ?? 'Meta API validation error';
                Log::error("WhatsApp Cloud API failed send: " . json_encode($errorData));

                return [
                    'external_message_id' => null,
                    'delivery_status' => 'failed',
                    'error_message' => $errMessage,
                ];
            }

            $responseData = $response->json();
            $wamid = $responseData['messages'][0]['id'] ?? null;

            return [
                'external_message_id' => $wamid,
                'delivery_status' => 'sent',
                'error_message' => null,
            ];

        } catch (Exception $e) {
            Log::error("WhatsApp API Network Exception: " . $e->getMessage());
            return [
                'external_message_id' => null,
                'delivery_status' => 'failed',
                'error_message' => "Network Error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify authenticity of Meta X-Hub-Signature-256 header.
     */
    public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
    {
        $clientSecret = $credentials['webhook_secret'] ?? config('services.facebook.client_secret') ?? '';

        $signatureHeader = $headers['x-hub-signature-256'] ?? $headers['X-Hub-Signature-256'] ?? null;
        if (!$signatureHeader) {
            if (empty($clientSecret) && !app()->environment('production')) {
                return true;
            }
            return false;
        }

        $parts = explode('=', $signatureHeader);
        if (count($parts) !== 2 || $parts[0] !== 'sha256') {
            return false;
        }

        $signature = $parts[1];

        if (empty($clientSecret)) {
            if (app()->environment('production')) {
                Log::error("WhatsApp Cloud API signature verification failed: webhook secret is missing in production.");
                return false;
            }
            return true;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $clientSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Normalize Meta WhatsApp payload.
     */
    public function normalizeInboundPayload(array $payload): array
    {
        $entry = $payload['entry'][0] ?? [];
        $change = $entry['changes'][0] ?? [];
        $value = $change['value'] ?? [];
        $msg = $value['messages'][0] ?? [];

        $contact = $value['contacts'][0] ?? [];
        $senderName = $contact['profile']['name'] ?? 'WhatsApp User';

        $from = $msg['from'] ?? '';
        $messageId = $msg['id'] ?? '';
        $type = $msg['type'] ?? 'text';

        $body = '';
        $mediaUrl = null;
        if ($type === 'text') {
            $body = $msg['text']['body'] ?? '';
        } elseif ($type === 'button') {
            $body = $msg['button']['text'] ?? '';
        } elseif ($type === 'interactive') {
            $body = $msg['interactive']['button_reply']['title'] 
                ?? $msg['interactive']['list_reply']['title'] 
                ?? '';
        } elseif ($type === 'image') {
            $mediaUrl = $msg['image']['id'] ?? null;
            $body = $msg['image']['caption'] ?? '';
        } elseif ($type === 'document') {
            $mediaUrl = $msg['document']['id'] ?? null;
            $body = $msg['document']['caption'] ?? '';
        }

        return [
            'external_chat_id' => (string)$from,
            'sender_identifier' => $senderName,
            'external_message_id' => (string)$messageId,
            'message_type' => $type,
            'body' => $body,
            'media_url' => $mediaUrl,
        ];
    }

    /**
     * Normalize Meta WhatsApp delivery status update payload.
     */
    public function normalizeStatusPayload(array $payload): array
    {
        $entry = $payload['entry'][0] ?? [];
        $change = $entry['changes'][0] ?? [];
        $value = $change['value'] ?? [];
        $status = $value['statuses'][0] ?? [];

        $messageId = $status['id'] ?? '';
        $statusName = $status['status'] ?? 'delivered';
        
        $errorMsg = null;
        if ($statusName === 'failed' && isset($status['errors'][0])) {
            $errorMsg = $status['errors'][0]['message'] ?? 'Meta API delivery error';
        }

        return [
            'external_message_id' => (string)$messageId,
            'delivery_status' => $statusName,
            'error_message' => $errorMsg,
        ];
    }
}
