<?php

namespace App\Services\Channels\Drivers;

use App\Contracts\ChannelProviderInterface;
use App\Services\SMS\Providers\TwilioSmsProvider;
use App\Services\SMS\Providers\VonageSmsProvider;
use App\Services\SMS\Providers\PlivoSmsProvider;
use App\Services\SMS\Providers\MessageBirdSmsProvider;
use App\Services\SMS\Providers\SinchSmsProvider;
use App\Services\SMS\Providers\TelnyxSmsProvider;
use Exception;

class SmsDriver implements ChannelProviderInterface
{
    /**
     * Resolve the inner SMS gateway provider class.
     */
    protected function getProvider(array $credentials)
    {
        $providerName = strtolower($credentials['provider'] ?? 'twilio');

        switch ($providerName) {
            case 'twilio':
                return new TwilioSmsProvider();
            case 'vonage':
                return new VonageSmsProvider();
            case 'plivo':
                return new PlivoSmsProvider();
            case 'messagebird':
                return new MessageBirdSmsProvider();
            case 'sinch':
                return new SinchSmsProvider();
            case 'telnyx':
                return new TelnyxSmsProvider();
            default:
                throw new Exception("Unsupported SMS provider: {$providerName}");
        }
    }

    /**
     * Send outgoing SMS.
     */
    public function sendMessage(array $credentials, array $messageData): array
    {
        $provider = $this->getProvider($credentials);
        return $provider->sendSms($credentials, $messageData['external_chat_id'], $messageData['body'] ?? '');
    }

    /**
     * Verify incoming webhook signature.
     */
    public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
    {
        $provider = $this->getProvider($credentials);
        
        $payloadArr = json_decode($payload, true) ?? [];
        if (empty($payloadArr)) {
            parse_str($payload, $payloadArr);
        }

        return $provider->verifyWebhookSignature($headers, $payloadArr, $credentials);
    }

    /**
     * Normalize incoming SMS payload.
     */
    public function normalizeInboundPayload(array $payload): array
    {
        // Auto-detect provider format
        $providerName = 'twilio';
        if (isset($payload['msisdn']) || isset($payload['messageId'])) {
            $providerName = 'vonage';
        } elseif (isset($payload['MessageUUID']) || isset($payload['message_uuid'])) {
            $providerName = 'plivo';
        } elseif (isset($payload['originator']) || isset($payload['recipient'])) {
            $providerName = 'messagebird';
        } elseif (isset($payload['data']['payload'])) {
            $providerName = 'telnyx';
        } elseif (isset($payload['from']) && is_array($payload['to'] ?? null)) {
            $providerName = 'sinch';
        }

        switch ($providerName) {
            case 'vonage':
                $provider = new VonageSmsProvider();
                break;
            case 'plivo':
                $provider = new PlivoSmsProvider();
                break;
            case 'messagebird':
                $provider = new MessageBirdSmsProvider();
                break;
            case 'sinch':
                $provider = new SinchSmsProvider();
                break;
            case 'telnyx':
                $provider = new TelnyxSmsProvider();
                break;
            default:
                $provider = new TwilioSmsProvider();
                break;
        }

        return $provider->normalizeInboundPayload($payload);
    }

    /**
     * Normalize status payload.
     */
    public function normalizeStatusPayload(array $payload): array
    {
        $externalId = $payload['external_message_id'] ?? $payload['MessageSid'] ?? $payload['messageId'] ?? null;
        
        return [
            'external_message_id' => (string)$externalId,
            'delivery_status' => $payload['delivery_status'] ?? 'delivered',
            'error_message' => $payload['error_message'] ?? null,
        ];
    }
}
