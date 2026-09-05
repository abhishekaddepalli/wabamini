<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\TenantMeetingToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class MeetingService
{
    /**
     * Generate meeting join link for an appointment.
     */
    public function generateMeetingLink(Appointment $appointment): ?string
    {
        $link = $appointment->bookingLink;
        if (!$link) {
            return null;
        }

        $locationType = $link->location_type;

        if ($locationType === 'custom') {
            return $link->custom_location;
        }

        if ($locationType === 'zoom') {
            return $this->createZoomMeeting($appointment);
        }

        if ($locationType === 'teams') {
            return $this->createTeamsMeeting($appointment);
        }

        return null;
    }

    /**
     * Zoom OAuth URL.
     */
    public function getZoomAuthUrl(int $tenantId): string
    {
        $clientId = config('services.zoom.client_id');
        $redirectUri = config('services.zoom.redirect_uri') ?: (rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/meetings/callback');

        return "https://zoom.us/oauth/authorize?" . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => json_encode(['tenant_id' => $tenantId, 'provider' => 'zoom']),
        ]);
    }

    /**
     * Microsoft Teams OAuth URL.
     */
    public function getTeamsAuthUrl(int $tenantId): string
    {
        $clientId = config('services.teams.client_id');
        $redirectUri = config('services.teams.redirect_uri') ?: (rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/meetings/callback');

        return "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?" . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'User.Read OnlineMeetings.ReadWrite offline_access',
            'state' => json_encode(['tenant_id' => $tenantId, 'provider' => 'teams']),
        ]);
    }

    /**
     * Exchange callback code for Zoom credentials.
     */
    public function exchangeZoomToken(string $code, int $tenantId): TenantMeetingToken
    {
        $clientId = config('services.zoom.client_id') ?: 'zoom_client_id_dummy';
        $clientSecret = config('services.zoom.client_secret') ?: 'zoom_client_secret_dummy';
        $redirectUri = config('services.zoom.redirect_uri') ?: (rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/meetings/callback');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (!$response->successful()) {
            throw new Exception("Zoom token exchange failed: " . $response->body());
        }

        $data = $response->json();

        // Fetch User Info
        $userResponse = Http::withToken($data['access_token'])
            ->get('https://api.zoom.us/v2/users/me');
        $email = $userResponse->successful() ? $userResponse->json('email') : null;

        return TenantMeetingToken::updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => 'zoom'],
            [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                'email' => $email,
            ]
        );
    }

    /**
     * Exchange callback code for Teams credentials.
     */
    public function exchangeTeamsToken(string $code, int $tenantId): TenantMeetingToken
    {
        $clientId = config('services.teams.client_id') ?: 'teams_client_id_dummy';
        $clientSecret = config('services.teams.client_secret') ?: 'teams_client_secret_dummy';
        $redirectUri = config('services.teams.redirect_uri') ?: (rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/meetings/callback');

        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new Exception("Teams token exchange failed: " . $response->body());
        }

        $data = $response->json();

        // Fetch User Info
        $userResponse = Http::withToken($data['access_token'])
            ->get('https://graph.microsoft.com/v1.0/me');
        $email = $userResponse->successful() ? $userResponse->json('mail') ?? $userResponse->json('userPrincipalName') : null;

        return TenantMeetingToken::updateOrCreate(
            ['tenant_id' => $tenantId, 'provider' => 'teams'],
            [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
                'email' => $email,
            ]
        );
    }

    /**
     * Create Zoom Meeting.
     */
    protected function createZoomMeeting(Appointment $appointment): ?string
    {
        try {
            $token = TenantMeetingToken::where('tenant_id', $appointment->tenant_id)
                ->where('provider', 'zoom')
                ->first();

            if (!$token) {
                Log::warning("Zoom not connected for tenant {$appointment->tenant_id}");
                return null;
            }

            $accessToken = $this->getZoomAccessToken($token);

            $durationMinutes = $appointment->bookingLink ? $appointment->bookingLink->duration : 30;

            $response = Http::withToken($accessToken)
                ->post('https://api.zoom.us/v2/users/me/meetings', [
                    'topic' => $appointment->bookingLink ? $appointment->bookingLink->name : 'WhatsOmni Appointment',
                    'type' => 2, // Scheduled Meeting
                    'start_time' => $appointment->start_time->toIso8601String(),
                    'duration' => $durationMinutes,
                    'timezone' => 'UTC',
                ]);

            if ($response->successful()) {
                return $response->json('join_url');
            }

            Log::error("Zoom meeting creation failed: " . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error("createZoomMeeting exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create Microsoft Teams Meeting.
     */
    protected function createTeamsMeeting(Appointment $appointment): ?string
    {
        try {
            $token = TenantMeetingToken::where('tenant_id', $appointment->tenant_id)
                ->where('provider', 'teams')
                ->first();

            if (!$token) {
                Log::warning("Teams not connected for tenant {$appointment->tenant_id}");
                return null;
            }

            $accessToken = $this->getTeamsAccessToken($token);

            $response = Http::withToken($accessToken)
                ->post('https://graph.microsoft.com/v1.0/me/onlineMeetings', [
                    'startDateTime' => $appointment->start_time->toIso8601String(),
                    'endDateTime' => $appointment->end_time->toIso8601String(),
                    'subject' => $appointment->bookingLink ? $appointment->bookingLink->name : 'WhatsOmni Appointment',
                ]);

            if ($response->successful()) {
                return $response->json('joinWebUrl');
            }

            Log::error("Teams meeting creation failed: " . $response->body());
            return null;
        } catch (Exception $e) {
            Log::error("createTeamsMeeting exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure valid Zoom access token.
     */
    protected function getZoomAccessToken(TenantMeetingToken $token): string
    {
        if (!$token->isExpired()) {
            return $token->decrypted_access_token;
        }

        $clientId = config('services.zoom.client_id') ?: 'zoom_client_id_dummy';
        $clientSecret = config('services.zoom.client_secret') ?: 'zoom_client_secret_dummy';

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->decrypted_refresh_token,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $token->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $token->decrypted_refresh_token,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
            ]);
            return $data['access_token'];
        }

        throw new Exception("Zoom refresh token failed: " . $response->body());
    }

    /**
     * Ensure valid Teams access token.
     */
    protected function getTeamsAccessToken(TenantMeetingToken $token): string
    {
        if (!$token->isExpired()) {
            return $token->decrypted_access_token;
        }

        $clientId = config('services.teams.client_id') ?: 'teams_client_id_dummy';
        $clientSecret = config('services.teams.client_secret') ?: 'teams_client_secret_dummy';

        $response = Http::asForm()
            ->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->decrypted_refresh_token,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $token->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $token->decrypted_refresh_token,
                'expires_at' => Carbon::now()->addSeconds($data['expires_in'] ?? 3600),
            ]);
            return $data['access_token'];
        }

        throw new Exception("Teams refresh token failed: " . $response->body());
    }
}
