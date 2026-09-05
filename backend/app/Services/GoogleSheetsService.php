<?php

namespace App\Services;

use App\Models\TenantGoogleToken;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Sheets as GoogleSheetsApi;
use Google\Service\Oauth2 as GoogleOauth2;
use Exception;
use Illuminate\Support\Carbon;

class GoogleSheetsService
{
    /**
     * Get configured Google Client instance.
     */
    public function getClient(TenantGoogleToken $tokenModel = null, string $type = 'sheets'): GoogleClient
    {
        if (!$this->isConfigured()) {
            throw new Exception("Google API client is not configured. Please define GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the environment.");
        }

        $client = new GoogleClient();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri') ?: (rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/google/callback'));
        
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');

        if ($type === 'calendar') {
            $client->addScope(\Google\Service\Calendar::CALENDAR);
        } else {
            $client->addScope(GoogleSheetsApi::SPREADSHEETS_READONLY);
            $client->addScope(GoogleDriveService::DRIVE_METADATA_READONLY);
        }
        
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        if ($tokenModel) {
            $client->setAccessToken([
                'access_token' => $tokenModel->decrypted_access_token,
                'refresh_token' => $tokenModel->decrypted_refresh_token,
                'expires_in' => max(0, $tokenModel->expires_at->timestamp - time()),
                'created' => $tokenModel->created_at->timestamp,
            ]);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $tokenModel->decrypted_refresh_token;
                if ($refreshToken) {
                    $newTokens = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    if (isset($newTokens['access_token'])) {
                        $tokenModel->update([
                            'access_token' => $newTokens['access_token'],
                            'expires_at' => Carbon::now()->addSeconds($newTokens['expires_in'] ?? 3600),
                        ]);
                    }
                }
            }
        }

        return $client;
    }

    /**
     * Check if Google OAuth credentials are configured.
     */
    public function isConfigured(): bool
    {
        return !empty(config('services.google.client_id')) && !empty(config('services.google.client_secret'));
    }

    /**
     * Get OAuth Redirect URL.
     */
    public function getAuthUrl(int $tenantId, string $type = 'sheets'): string
    {
        $client = $this->getClient(null, $type);
        $client->setState(json_encode(['tenant_id' => $tenantId, 'type' => $type]));
        return $client->createAuthUrl();
    }

    /**
     * Exchange code and persist tokens.
     */
    public function handleCallback(string $code, int $tenantId, string $type = 'sheets'): TenantGoogleToken
    {
        $client = $this->getClient(null, $type);
        $payload = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($payload['error'])) {
            throw new Exception("Google OAuth Error: " . ($payload['error_description'] ?? $payload['error']));
        }

        $accessToken = $payload['access_token'];
        $refreshToken = $payload['refresh_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? 3600;

        // Fetch User Info to save email reference
        $oauth2 = new GoogleOauth2($client);
        $userInfo = $oauth2->userinfo->get();
        $email = $userInfo->getEmail();

        $tokenData = [
            'type' => $type,
            'access_token' => $accessToken,
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'email' => $email,
        ];

        if ($refreshToken) {
            $tokenData['refresh_token'] = $refreshToken;
        }

        return TenantGoogleToken::updateOrCreate(
            ['tenant_id' => $tenantId, 'type' => $type],
            $tokenData
        );
    }

    /**
     * List user's spreadsheets from Google Drive.
     */
    public function listSpreadsheets(int $tenantId): array
    {
        $token = TenantGoogleToken::where('tenant_id', $tenantId)->where('type', 'sheets')->first();
        if (!$token) {
            throw new Exception("Google Account not connected.");
        }

        $client = $this->getClient($token, 'sheets');
        $drive = new GoogleDriveService($client);

        $response = $drive->files->listFiles([
            'q' => "mimeType = 'application/vnd.google-apps.spreadsheet'",
            'fields' => 'files(id, name)',
            'pageSize' => 50,
        ]);

        $files = [];
        foreach ($response->getFiles() as $file) {
            $files[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
            ];
        }

        return $files;
    }

    /**
     * List tabs/sheets inside a spreadsheet.
     */
    public function listSheets(int $tenantId, string $spreadsheetId): array
    {
        $token = TenantGoogleToken::where('tenant_id', $tenantId)->where('type', 'sheets')->first();
        if (!$token) {
            throw new Exception("Google Account not connected.");
        }

        $client = $this->getClient($token, 'sheets');
        $service = new GoogleSheetsApi($client);

        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheets = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $sheets[] = $sheet->getProperties()->getTitle();
        }

        return $sheets;
    }

    /**
     * Get rows from a sheet.
     */
    public function getSheetData(int $tenantId, string $spreadsheetId, string $sheetName): array
    {
        $token = TenantGoogleToken::where('tenant_id', $tenantId)->where('type', 'sheets')->first();
        if (!$token) {
            throw new Exception("Google Account not connected.");
        }

        $client = $this->getClient($token, 'sheets');
        $service = new GoogleSheetsApi($client);

        $range = $sheetName . '!A1:Z500';
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        
        return $response->getValues() ?: [];
    }
}
