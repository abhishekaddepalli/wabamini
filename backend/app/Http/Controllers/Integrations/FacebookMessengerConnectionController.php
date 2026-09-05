<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class FacebookMessengerConnectionController extends Controller
{
    /**
     * Get active Facebook Messenger connection status and all connected pages for the tenant.
     */
    public function status(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $connections = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'messenger')
            ->orderBy('id', 'desc')
            ->get();

        $connectionsList = $connections->map(function ($conn) {
            $creds = $conn->decrypted_credentials;
            return [
                'id' => $conn->id,
                'name' => $conn->name,
                'status' => $conn->status,
                'page_id' => $creds['page_id'] ?? '',
                'webhook_url' => rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$conn->id}",
                'webhook_verify_token' => $creds['webhook_verify_token'] ?? '',
                'raw_credentials' => $creds,
                'created_at' => $conn->created_at?->toIso8601String(),
            ];
        });

        $firstConn = $connections->first();
        $firstCreds = $firstConn ? $firstConn->decrypted_credentials : [];

        return response()->json([
            'connected' => $connections->isNotEmpty(),
            'connections' => $connectionsList,
            'id' => $firstConn?->id,
            'name' => $firstConn?->name,
            'status' => $firstConn?->status,
            'page_id' => $firstCreds['page_id'] ?? '',
            'webhook_url' => $firstConn ? rtrim(config('app.url'), '/') . "/api/webhooks/channel/{$firstConn->id}" : '',
            'webhook_verify_token' => $firstCreds['webhook_verify_token'] ?? '',
        ]);
    }

    /**
     * Redirect to Facebook Login OAuth page for Page permissions.
     */
    public function redirect(Request $request)
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'messenger');
            if (!$limitCheck['allowed']) {
                return redirect('/channels?error=' . urlencode($limitCheck['reason']));
            }
        }

        $appId = \App\Models\PlatformSetting::get('facebook_client_id') ?: config('services.facebook.client_id');
        $redirectUri = rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/messenger/oauth/callback';
        $scope = 'pages_show_list,pages_messaging,pages_manage_metadata';
        $state = csrf_token();

        if (!$appId) {
            return redirect('/channels?error=' . urlencode('Facebook App ID (FACEBOOK_CLIENT_ID) is missing in server environment or platform settings.'));
        }

        $url = "https://www.facebook.com/v20.0/dialog/oauth?" . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
        ]);

        return redirect($url);
    }

    /**
     * Handle the Facebook OAuth Callback.
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        if (!$code) {
            return redirect('/channels?error=No+oauth+code+returned+from+Facebook');
        }

        $appId = \App\Models\PlatformSetting::get('facebook_client_id') ?: config('services.facebook.client_id');
        $appSecret = \App\Models\PlatformSetting::get('facebook_client_secret') ?: config('services.facebook.client_secret');
        $redirectUri = rtrim(config('app.url') ?: url('/'), '/') . '/api/integrations/messenger/oauth/callback';

        try {
            // 1. Exchange auth code for user access token
            $response = Http::get("https://graph.facebook.com/v20.0/oauth/access_token", [
                'client_id' => $appId,
                'redirect_uri' => $redirectUri,
                'client_secret' => $appSecret,
                'code' => $code,
            ]);

            if (!$response->successful()) {
                throw new Exception("OAuth token exchange failed: " . $response->body());
            }

            $userAccessToken = $response->json('access_token');

            // 2. Exchange short-lived token for long-lived user access token
            $response = Http::get("https://graph.facebook.com/v20.0/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $userAccessToken,
            ]);

            if (!$response->successful()) {
                throw new Exception("Long-lived token exchange failed: " . $response->body());
            }

            $longLivedAccessToken = $response->json('access_token');

            // 3. Query Facebook Pages managed by user
            $response = Http::withToken($longLivedAccessToken)
                ->get("https://graph.facebook.com/v20.0/me/accounts");

            if (!$response->successful()) {
                throw new Exception("Failed to query user Facebook pages: " . $response->body());
            }

            $pages = $response->json('data') ?? [];
            if (empty($pages)) {
                throw new Exception("No Facebook Pages found managed by user.");
            }

            // Connect using the first returned Page
            $targetPage = $pages[0];
            $tenantId = $request->user()->tenant_id ?? 1;

            // Look for existing connection matching this page ID
            $existing = ChannelConnection::where('tenant_id', $tenantId)
                ->where('channel_type', 'messenger')
                ->get();

            $connection = null;
            foreach ($existing as $conn) {
                $creds = $conn->decrypted_credentials;
                if (($creds['page_id'] ?? '') === $targetPage['id']) {
                    $connection = $conn;
                    break;
                }
            }

            if (!$connection) {
                $tenant = $request->user()?->tenant;
                if ($tenant) {
                    $limitCheck = app(\App\Services\PlanLimitService::class)->canConnectChannel($tenant, 'messenger');
                    if (!$limitCheck['allowed']) {
                        return redirect('/channels?error=' . urlencode($limitCheck['reason']));
                    }
                }

                $connection = new ChannelConnection();
                $connection->tenant_id = $tenantId;
                $connection->channel_type = 'messenger';
            }

            $existingCreds = $connection->exists ? $connection->decrypted_credentials : [];
            $webhookVerifyToken = $existingCreds['webhook_verify_token'] ?? Str::random(32);

            $connection->name = $targetPage['name'] . " (Messenger)";
            $connection->status = 'connected';
            $connection->credentials = [
                'page_access_token' => $targetPage['access_token'],
                'page_id' => $targetPage['id'],
                'webhook_verify_token' => $webhookVerifyToken,
            ];
            $connection->save();

            return redirect('/channels?success=Facebook+Messenger+channel+connected+successfully');
        } catch (Exception $e) {
            Log::error("Facebook Messenger OAuth callback failed: " . $e->getMessage());
            return redirect('/channels?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Delete/Disconnect Facebook Messenger channel by ID.
     */
    public function disconnect(Request $request, $id = null): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $targetId = $id ?: $request->input('id');

        $query = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'messenger');

        if ($targetId) {
            $query->where('id', $targetId);
        }

        $query->delete();

        $remaining = ChannelConnection::where('tenant_id', $tenantId)
            ->where('channel_type', 'messenger')
            ->count();

        return response()->json([
            'connected' => $remaining > 0,
            'remaining_count' => $remaining,
            'message' => 'Facebook Messenger connection has been disconnected successfully.'
        ]);
    }
}
