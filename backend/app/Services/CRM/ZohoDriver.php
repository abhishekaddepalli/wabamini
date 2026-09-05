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

class ZohoDriver implements CrmServiceInterface
{
    public function getAuthUrl(int $tenantId): string
    {
        $clientId = config('services.zoho.client_id');
        if (empty($clientId)) {
            throw new Exception("Zoho Integration is not configured. Please define ZOHO_CLIENT_ID in your environment.");
        }

        $redirectUri = route('crm.oauth.callback', ['provider' => 'zoho']);

        return "https://accounts.zoho.com/oauth/v2/auth?" . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'scope' => 'ZohoCRM.modules.contacts.ALL',
            'redirect_uri' => $redirectUri,
            'state' => $tenantId,
            'access_type' => 'offline',
            'prompt' => 'consent'
        ]);
    }

    public function handleCallback(string $code, int $tenantId): CrmIntegration
    {
        $clientId = config('services.zoho.client_id');
        $clientSecret = config('services.zoho.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception("Zoho credentials are not configured. Please define ZOHO_CLIENT_ID and ZOHO_CLIENT_SECRET.");
        }

        $redirectUri = route('crm.oauth.callback', ['provider' => 'zoho']);

        $response = Http::asForm()->post("https://accounts.zoho.com/oauth/v2/token", [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code
        ]);

        if (!$response->successful()) {
            throw new Exception("Zoho OAuth exchange failed: " . $response->body());
        }

        $data = $response->json();
        
        return CrmIntegration::updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => 'zoho'],
            [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                'email' => 'connected_user@zoho.com',
                'field_mapping' => $this->getDefaultMapping(),
                'sync_direction' => 'bidirectional',
                'last_sync_at' => Carbon::now(),
                'metadata' => [
                    'api_domain' => $data['api_domain'] ?? 'https://www.zohoapis.com'
                ]
            ]
        );
    }

    public function refreshAccessToken(CrmIntegration $integration): string
    {
        if ($integration->expires_at && $integration->expires_at->isFuture()) {
            return $integration->access_token;
        }

        $clientId = config('services.zoho.client_id');
        $clientSecret = config('services.zoho.client_secret');

        if (empty($clientId) || empty($clientSecret) || empty($integration->refresh_token)) {
            throw new Exception("Unable to refresh Zoho token: credentials or refresh token missing.");
        }

        $response = Http::asForm()->post("https://accounts.zoho.com/oauth/v2/token", [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $integration->refresh_token
        ]);

        if (!$response->successful()) {
            throw new Exception("Zoho token refresh failed: " . $response->body());
        }

        $data = $response->json();
        $integration->update([
            'access_token' => $data['access_token'],
            'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    public function pushContact(Contact $contact, CrmIntegration $integration): CrmSyncLog
    {
        $accessToken = $this->refreshAccessToken($integration);
        $mapping = $integration->field_mapping ?? $this->getDefaultMapping();
        $metadata = $integration->metadata ?? [];
        $apiDomain = $metadata['api_domain'] ?? 'https://www.zohoapis.com';

        $lastLog = CrmSyncLog::where('crm_integration_id', $integration->id)
            ->where('contact_id', $contact->id)
            ->where('status', 'success')
            ->whereNotNull('external_id')
            ->first();

        $externalId = $lastLog ? $lastLog->external_id : null;

        $properties = [];
        foreach ($mapping as $whatsOmniKey => $zohoKey) {
            if ($whatsOmniKey === 'tags') continue;
            $val = $contact->{$whatsOmniKey};
            if ($val !== null) {
                $properties[$zohoKey] = $val;
            }
        }

        try {
            if ($externalId) {
                $response = Http::withHeaders([
                    'Authorization' => "Zoho-oauthtoken {$accessToken}"
                ])->put("{$apiDomain}/crm/v3/Contacts/{$externalId}", [
                    'data' => [$properties]
                ]);
            } else {
                $response = Http::withHeaders([
                    'Authorization' => "Zoho-oauthtoken {$accessToken}"
                ])->post("{$apiDomain}/crm/v3/Contacts", [
                    'data' => [$properties]
                ]);
            }

            if (!$response->successful()) {
                throw new Exception("Zoho API push failed: " . $response->body());
            }

            $responseData = $response->json();
            $results = $responseData['data'] ?? [];
            if (!empty($results) && isset($results[0]['details']['id'])) {
                $externalId = $results[0]['details']['id'];
            }

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
                'description' => "Synced contact payload to Zoho successfully (External ID: {$externalId})."
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
        $metadata = $integration->metadata ?? [];
        $apiDomain = $metadata['api_domain'] ?? 'https://www.zohoapis.com';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Zoho-oauthtoken {$accessToken}"
            ])->get("{$apiDomain}/crm/v3/Contacts");

            if (!$response->successful()) {
                throw new Exception("Zoho API pull failed: " . $response->body());
            }

            $pulledContacts = $response->json('data') ?? [];

            $importedCount = 0;
            $mergedCount = 0;

            \App\Observers\ContactObserver::$bypass = true;
            try {
                foreach ($pulledContacts as $zhContact) {
                    $zhId = $zhContact['id'];

                    $contactData = [];
                    foreach ($mapping as $whatsOmniKey => $zohoKey) {
                        if ($whatsOmniKey === 'tags') continue;
                        $contactData[$whatsOmniKey] = $zhContact[$zohoKey] ?? null;
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
                        'external_id' => $zhId,
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
            'first_name' => 'First_Name',
            'last_name' => 'Last_Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'lifecycle_stage' => 'Lead_Source'
        ];
    }
}
