<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\MeetingService;
use App\Models\TenantMeetingToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class MeetingIntegrationController extends Controller
{
    protected MeetingService $meetingService;

    public function __construct(MeetingService $meetingService)
    {
        $this->meetingService = $meetingService;
    }

    /**
     * Get meeting integrations status.
     */
    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $zoomToken = TenantMeetingToken::where('tenant_id', $tenant->id)
            ->where('provider', 'zoom')
            ->first();

        $teamsToken = TenantMeetingToken::where('tenant_id', $tenant->id)
            ->where('provider', 'teams')
            ->first();

        return response()->json([
            'zoom_connected' => (bool)$zoomToken,
            'zoom_email' => $zoomToken ? $zoomToken->email : null,
            'zoom_configured' => !empty(config('services.zoom.client_id')) && !empty(config('services.zoom.client_secret')),
            'teams_connected' => (bool)$teamsToken,
            'teams_email' => $teamsToken ? $teamsToken->email : null,
            'teams_configured' => !empty(config('services.teams.client_id')) && !empty(config('services.teams.client_secret')),
        ]);
    }

    /**
     * Get OAuth auth URL.
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        $provider = $request->query('provider');

        $tenant = $request->user()->tenant;
        if ($tenant && $provider) {
            $limitCheck = app(\App\Services\PlanLimitService::class)->canUseIntegration($tenant, $provider);
            if (!$limitCheck['allowed']) {
                return response()->json([
                    'message' => $limitCheck['reason'],
                    'error_code' => $limitCheck['code']
                ], 403);
            }
        }

        if ($provider === 'zoom') {
            $url = $this->meetingService->getZoomAuthUrl($tenant->id);
        } else if ($provider === 'teams') {
            $url = $this->meetingService->getTeamsAuthUrl($tenant->id);
        } else {
            return response()->json(['message' => 'Invalid meeting provider.'], 400);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * OAuth callback handler.
     */
    public function callback(Request $request)
    {
        $stateStr = $request->query('state');
        $code = $request->query('code');

        $tenantId = null;
        $provider = 'zoom';

        try {
            $stateDecoded = json_decode($stateStr, true);
            if (is_array($stateDecoded)) {
                $tenantId = (int)($stateDecoded['tenant_id'] ?? null);
                $provider = $stateDecoded['provider'] ?? 'zoom';
            }
        } catch (Exception $e) {
            Log::error("State decoding failed in Meeting callback: " . $e->getMessage());
        }

        $frontendUrl = \App\Providers\AppServiceProvider::getFrontendUrl();

        if (!$tenantId || !$code) {
            return redirect($frontendUrl . '/settings/integrations?meeting_error=' . urlencode('Invalid OAuth state callback response.'));
        }

        try {
            if ($provider === 'zoom') {
                $this->meetingService->exchangeZoomToken($code, $tenantId);
            } else if ($provider === 'teams') {
                $this->meetingService->exchangeTeamsToken($code, $tenantId);
            } else {
                throw new Exception("Unsupported meeting provider.");
            }

            return redirect($frontendUrl . "/settings/integrations?meeting_{$provider}_success=true");
        } catch (Exception $e) {
            Log::error("Meeting callback error [{$provider}]: " . $e->getMessage());
            return redirect($frontendUrl . '/settings/integrations?meeting_error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Disconnect meeting integration.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $provider = $request->query('provider');

        if (!in_array($provider, ['zoom', 'teams'])) {
            return response()->json(['message' => 'Invalid meeting provider.'], 400);
        }

        TenantMeetingToken::where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->delete();

        return response()->json(['message' => "Disconnected {$provider} successfully."]);
    }
}
