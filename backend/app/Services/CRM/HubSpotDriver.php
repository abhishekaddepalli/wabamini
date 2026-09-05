<?php

namespace App\Services\CRM;

use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\CrmIntegration;
use App\Models\CrmSyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class HubSpotDriver implements CrmServiceInterface
{
    public function getAuthUrl(int $tenantId): string
    {
        $clientId = config('services.hubspot.client_id');
        if (empty($clientId)) {
            throw new Exception("HubSpot Integration is not configured. Please define HUBSPOT_CLIENT_ID in your environment.");
        }

        $redirectUri = route('crm.oauth.callback', ['provider' => 'hubspot']);
        $scopes = 'crm.objects.contacts.read crm.objects.contacts.write';

        return "https://app.hubspot.com/oauth/authorize?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $tenantId
        ]);
    }

    public function handleCallback(string $code, int $tenantId): CrmIntegration
    {
        $clientId = config('services.hubspot.client_id');
        $clientSecret = config('services.hubspot.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception("HubSpot client credentials are not configured. Please define HUBSPOT_CLIENT_ID and HUBSPOT_CLIENT_SECRET.");
        }

        $redirectUri = route('crm.oauth.callback', ['provider' => 'hubspot']);

        $response = Http::asForm()->post("https://api.hubapi.com/oauth/v1/token", [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code
        ]);

        if (!$response->successful()) {
            throw new Exception("HubSpot OAuth token exchange failed: " . ($response->json('message') ?: $response->body()));
        }

        $data = $response->json();
        
        return CrmIntegration::updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => 'hubspot'],
            [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                'email' => 'connected_user@hubspot.com',
                'field_mapping' => $this->getDefaultMapping(),
                'sync_direction' => 'bidirectional',
                'last_sync_at' => Carbon::now()
            ]
        );
    }

    public function refreshAccessToken(CrmIntegration $integration): string
    {
        if ($integration->expires_at && $integration->expires_at->isFuture()) {
            return $integration->access_token;
        }

        $clientId = config('services.hubspot.client_id');
        $clientSecret = config('services.hubspot.client_secret');

        if (empty($clientId) || empty($clientSecret) || empty($integration->refresh_token)) {
            throw new Exception("Unable to refresh HubSpot token: credentials or refresh token missing.");
        }

        $response = Http::asForm()->post("https://api.hubapi.com/oauth/v1/token", [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $integration->refresh_token
        ]);

        if (!$response->successful()) {
            throw new Exception("HubSpot OAuth token refresh failed: " . $response->body());
        }

        $data = $response->json();
        $integration->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
            'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    public function pushContact(Contact $contact, CrmIntegration $integration): CrmSyncLog
    {
        $accessToken = $this->refreshAccessToken($integration);
        $mapping = $integration->field_mapping ?? $this->getDefaultMapping();

        $lastLog = CrmSyncLog::where('crm_integration_id', $integration->id)
            ->where('contact_id', $contact->id)
            ->where('status', 'success')
            ->whereNotNull('external_id')
            ->first();

        $externalId = $lastLog ? $lastLog->external_id : null;

        $properties = [];
        foreach ($mapping as $whatsOmniKey => $hubSpotKey) {
            if ($whatsOmniKey === 'tags') continue;
            $val = $contact->{$whatsOmniKey};
            if ($val !== null) {
                $properties[$hubSpotKey] = $val;
            }
        }

        try {
            if ($externalId) {
                $response = Http::withToken($accessToken)
                    ->patch("https://api.hubapi.com/crm/v3/objects/contacts/{$externalId}", [
                        'properties' => $properties
                    ]);
            } else {
                $response = Http::withToken($accessToken)
                    ->post("https://api.hubapi.com/crm/v3/objects/contacts", [
                        'properties' => $properties
                    ]);
            }

            if (!$response->successful()) {
                throw new Exception("HubSpot API push failed: " . ($response->json('message') ?: $response->body()));
            }

            $responseData = $response->json();
            $externalId = $responseData['id'] ?? $externalId;

            $log = CrmSyncLog::create([
                'tenant_id' => $contact->tenant_id,
                'crm_integration_id' => $integration->id,
                'contact_id' => $contact->id,
                'external_id' => $externalId,
                'action' => 'push',
                'status' => 'success'
            ]);

            ContactActivity::create([
                'tenant_id' => $contact->tenant_id,
                'contact_id' => $contact->id,
                'activity_type' => 'crm_sync',
                'description' => "Synced contact payload to HubSpot successfully (External ID: {$externalId})."
            ]);

            return $log;
        } catch (Exception $e) {
            $log = CrmSyncLog::create([
                'tenant_id' => $contact->tenant_id,
                'crm_integration_id' => $integration->id,
                'contact_id' => $contact->id,
                'external_id' => $externalId,
                'action' => 'push',
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            return $log;
        }
    }

    public function pullContacts(CrmIntegration $integration, string $duplicateStrategy = 'merge'): array
    {
        $accessToken = $this->refreshAccessToken($integration);
        $mapping = $integration->field_mapping ?? $this->getDefaultMapping();

        try {
            $response = Http::withToken($accessToken)
                ->get("https://api.hubapi.com/crm/v3/objects/contacts", [
                    'limit' => 50,
                    'properties' => implode(',', array_values($mapping))
                ]);

            if (!$response->successful()) {
                throw new Exception("HubSpot API pull failed: " . $response->body());
            }

            $pulledContacts = $response->json('results') ?? [];

            $importedCount = 0;
            $mergedCount = 0;

            \App\Observers\ContactObserver::$bypass = true;
            try {
                foreach ($pulledContacts as $hsContact) {
                    $hsId = $hsContact['id'];
                    $props = $hsContact['properties'];

                    $contactData = [];
                    foreach ($mapping as $whatsOmniKey => $hubSpotKey) {
                        if ($whatsOmniKey === 'tags') continue;
                        $contactData[$whatsOmniKey] = $props[$hubSpotKey] ?? null;
                    }

                    $existingContact = null;
                    if (!empty($contactData['email'])) {
                        $existingContact = Contact::where('tenant_id', $integration->tenant_id)
                            ->where('email', $contactData['email'])
                            ->first();
                    }
                    if (!$existingContact && !empty($contactData['phone'])) {
                        $existingContact = Contact::where('tenant_id', $integration->tenant_id)
                            ->where('phone', $contactData['phone'])
                            ->first();
                    }

                    if ($existingContact && $duplicateStrategy === 'merge') {
                        $toUpdate = [];
                        foreach ($contactData as $k => $v) {
                            if (empty($existingContact->{$k}) && !empty($v)) {
                                $toUpdate[$k] = $v;
                            }
                        }
                        if (!empty($toUpdate)) {
                            $existingContact->update($toUpdate);
                        }
                        $contact = $existingContact;
                        $mergedCount++;
                    } else {
                        $contact = Contact::create(array_merge($contactData, [
                            'tenant_id' => $integration->tenant_id,
                            'lifecycle_stage' => $contactData['lifecycle_stage'] ?? 'lead'
                        ]));
                        $importedCount++;
                    }

                    CrmSyncLog::create([
                        'tenant_id' => $integration->tenant_id,
                        'crm_integration_id' => $integration->id,
                        'contact_id' => $contact->id,
                        'external_id' => $hsId,
                        'action' => 'pull',
                        'status' => 'success'
                    ]);
                }
            } finally {
                \App\Observers\ContactObserver::$bypass = false;
            }

            $integration->update(['last_sync_at' => Carbon::now()]);

            return [
                'success' => true,
                'imported' => $importedCount,
                'merged' => $mergedCount
            ];

        } catch (Exception $e) {
            CrmSyncLog::create([
                'tenant_id' => $integration->tenant_id,
                'crm_integration_id' => $integration->id,
                'action' => 'pull',
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function getDefaultMapping(): array
    {
        return [
            'first_name' => 'firstname',
            'last_name' => 'lastname',
            'email' => 'email',
            'phone' => 'phone',
            'lifecycle_stage' => 'hs_lifecycle_stage'
        ];
    }
}
